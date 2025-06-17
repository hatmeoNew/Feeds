<?php

namespace NexaMerchant\Feeds\Console\Commands\Klaviyo;

use GuzzleHttp\Client;
use Webkul\Sales\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Nicelizhi\Shopify\Helpers\Utils;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductImage;
use Webkul\Sales\Repositories\ShipmentRepository;

class SendKlaviyoEvent extends Command
{
    protected $signature = 'klaviyo:event {--order_id=} {--metric_type=} {--is_debug=}';
    protected $description = '上报Klaviyo事件以触发邮件发送';

    const REVISION = '2025-04-15';
    const API_URL = 'https://a.klaviyo.com/api/events';
    const TEST_EMAIL = '916675194@qq.com';

    const METRIC_TYPE_100 = 100;
    const METRIC_TYPE_200 = 200;
    const METRIC_TYPE_300 = 300;

    public $email;
    public $metric_type;

    static $eventList = [
        self::METRIC_TYPE_100 => 'Placed Order',
        self::METRIC_TYPE_200 => 'Fulfilled Order',
        self::METRIC_TYPE_300 => 'Cancelled Order'
    ];

    public function handle()
    {
        $orderId = $this->option('order_id');
        $metricType = $this->option('metric_type');
        $isDebug = $this->option('is_debug') === '1';

        $order = Order::findOrFail($orderId);
        $this->email = $isDebug ? self::TEST_EMAIL : $order->customer_email;
        $this->metric_type = $metricType;
        if (empty($this->email)) {
            Utils::sendFeishu('邮件地址为空' . ' 订单ID：' . $orderId . ' . website:' . config('odoo_api.website_url'));
        }

        // 检测是否已发送邮件
        $exists = DB::table('email_send_records')->where([
            'order_id'    => $orderId,
            'email'       => $this->email,
            'send_status' => 'success',
            'metric_name' => self::$eventList[$metricType],
        ])->exists();
        if ($exists && !$isDebug && ($metricType == self::METRIC_TYPE_100)) {
            $this->info('订单 ' . $orderId . ' 已发送邮件');
            return 0;
        }

        // 下单
        if ($metricType == self::METRIC_TYPE_100) {
            $sendRes = $this->placedOrder($order);
            dump('send res', $sendRes);
        }

        // 发货
        if ($metricType == self::METRIC_TYPE_200) {
            // 找出该订单的出货单记录
            $shipments = app(ShipmentRepository::class)->with('items')->where('order_id', $orderId)->get();
            foreach ($shipments as $shipment) {
                $sendRes = $this->fulfilledOrder($order, $shipment);
                dump('send res', $sendRes);
            }
        }

        return true;
    }

    protected function fulfilledOrder($order, $shipment)
    {
        $properties = $this->buildEventPropertiesFulfilled($order, $shipment);
        // dd($properties);
        $sendRes = $this->sendEvent(self::$eventList[self::METRIC_TYPE_200], $properties);
        // 数据入库 & 日志处理
        DB::table('email_send_records')->insert(array_merge(
            [
                'order_id' => $order->id,
                'email' => $this->email,
                'metric_name' => self::$eventList[self::METRIC_TYPE_200],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            $sendRes
        ));

        return $sendRes;
    }

    protected function placedOrder($order)
    {
        $properties = $this->buildEventProperties($order);
        // dd($properties);

        $sendRes = $this->sendEvent(self::$eventList[self::METRIC_TYPE_100], $properties);

        // 数据入库 & 日志处理
        DB::table('email_send_records')->insert(array_merge(
            [
                'order_id' => $order->id,
                'email' => $order->customer_email,
                'metric_name' => self::$eventList[self::METRIC_TYPE_100],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            $sendRes
        ));

        return $sendRes;
    }

    protected function buildEventPropertiesFulfilled($order, $shipment)
    {
        $line_items = [];
        foreach ($shipment->items as $shipmentItem) {

            $additional = $shipmentItem['additional'];
            $variant_id = $additional['selected_configurable_option'] ?: $shipmentItem['product_id'];
            if (empty($additional['img'])) {
                $additional['img'] = ProductImage::where('product_id', $variant_id)->value('path');
            }
            $additional['product_sku'] = Product::where('id', $variant_id)->value('custom_sku');

            if (!empty($additional['attributes'])) {
                $additional['attributes'] = array_values($additional['attributes']);
            } else {
                $additional['attributes'] = [];
            }

            $additional['price'] = $shipmentItem['price'];
            $additional['name'] = $shipmentItem['name'];

            array_push($line_items, $additional);
        }

        $logo = asset('storage/logo.webp');
        $logo = str_replace(['shop.', 'offer.'], 'api.', $logo);
        return [
            'order_number'    => config('odoo_api.order_pre') . '#' . $order->id,
            'items'           => collect($line_items)->map(function($item) {
                return [
                    'sku'        => $item['name'],
                    'quantity'   => $item['quantity'],
                    'image'      => $item['img'],
                    'attributes' => $item['attribute_name'] ?? '',
                ];
            })->toArray(),
            'username'      => trim($order->shipping_address->first_name . ' ' . $order->shipping_address->last_name),
            'logo'          => $logo,
            'carrier_title' => $shipment->carrier_title,
            'track_number'  => $shipment->track_number,
            'shop_email'       => core()->getConfigData('emails.configure.email_settings.shop_email_from') ?: 'vip@kundies.com',
            'shop_name'        => core()->getConfigData('emails.configure.email_settings.sender_name') ?: 'Kundies',
            'subject_line'     => $this->getSubjectLine()
        ];
    }

    public function getSubjectLine()
    {
        $subject_line = '';
        switch ($this->metric_type) {
            case self::METRIC_TYPE_100:
                $subject_line = trans('email.new_order_confirmation');
                break;

            case self::METRIC_TYPE_200:
                $subject_line = trans('email.shipped_confirmation');
                break;

            default:
                # code...
                break;
        }

        return $subject_line;
    }

    /**
     * 构建事件属性数据
     * @param Order $order
     * @return array
     */
    protected function buildEventProperties(Order $order): array
    {
        $line_items = [];
        foreach ($order->items as $orderItem) {

            $additional = $orderItem['additional'];
            $variant_id = $additional['selected_configurable_option'] ?: $orderItem['product_id'];
            if (empty($additional['img'])) {
                $additional['img'] = ProductImage::where('product_id', $variant_id)->value('path');
            }
            $additional['product_sku'] = Product::where('id', $variant_id)->value('custom_sku');

            if (!empty($additional['attributes'])) {
                $additional['attributes'] = array_values($additional['attributes']);
            } else {
                $additional['attributes'] = [];
            }

            $additional['price'] = $orderItem['price'];
            $additional['name'] = $orderItem['name'];

            array_push($line_items, $additional);
        }

        $payment = ucfirst($order->payment->method);
        if (stripos($payment, 'paypal') !== false) {
            $payment = 'PayPal';
        }

        return [
            'order_number'    => config('odoo_api.order_pre') . '#' . $order->id,//$order->increment_id,
            'total'           => core()->currency($order->grand_total),
            'sub_total'       => core()->currency($order->sub_total),
            'discount_amount' => core()->currency($order->discount_amount),
            'shipping_amount' => core()->currency($order->shipping_amount),
            'order_time'      => date('Y-m-d H:i:s', strtotime($order->created_at)),
            'payment'         => $payment,
            'items'           => collect($line_items)->map(function($item) {
                return [
                    'sku'        => $item['name'],
                    'quantity'   => $item['quantity'],
                    'price'      => core()->currency($item['price']),
                    'image'      => $item['img'],
                    'attributes' => $item['attribute_name'] ?? '',
                ];
            })->toArray(),
            'billing_address'  => collect($order->billing_address)->only(['country', 'city', 'phone', 'address1'])->toArray(),
            'shipping_address' => collect($order->shipping_address)->only(['country', 'city', 'phone', 'address1'])->toArray(),
            'username'         => trim($order->shipping_address->first_name . ' ' . $order->shipping_address->last_name),
            'logo'             => asset('storage/logo.webp'),
            'shop_email'       => core()->getConfigData('emails.configure.email_settings.shop_email_from') ?: 'vip@kundies.com',
            'shop_name'        => core()->getConfigData('emails.configure.email_settings.sender_name') ?: 'Kundies',
            'subject_line'     => $this->getSubjectLine()
        ];
    }

    /**
     * 发送 Klaviyo 事件
     *
     * @param string $eventName 事件名称，如 "Placed Order"
     * @param string $email 接收者邮箱
     * @param array $properties 事件属性（如订单信息）
     * @return array
     */
    public function sendEvent(string $eventName, array $properties = []): array
    {
        $eventData = [
            'data' => [
                'type' => 'event',
                'attributes' => [
                    'metric' => [
                        'data' => [
                            'type' => 'metric',
                            'attributes' => [
                                'name' => $eventName
                            ]
                        ]
                    ],
                    'profile' => [
                        'data' => [
                            'type' => 'profile',
                            'attributes' => [
                                'properties' => [
                                    'email' => $this->email,
                                    'locale' => env('APP_LOCALE', 'en')
                                ]
                            ]
                        ]
                    ],
                    'properties' => $properties
                ]
            ]
        ];

        $client = new Client();
        $response = $client->post(self::API_URL, [
            'headers' => [
                'Accept'        => 'application/vnd.api+json',
                'Revision'      => self::REVISION,
                'Authorization' => 'Klaviyo-API-Key ' . config('mail.mailers.klaviyo.api_key'),
            ],
            'json' => $eventData
        ]);

        if ($response->getStatusCode() >= 400) {
            return [
                'send_status' => 0,
                'failure_reason' => json_encode(
                    $response->getStatusCode(),
                    $response->getBody()->getContents()
                )
            ];
        }

        return [
            'send_status' => 1,
            'failure_reason' => ''
        ];

    }
}