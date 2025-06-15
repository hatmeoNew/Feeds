<?php

namespace NexaMerchant\Feeds\Console\Commands\Klaviyo;

use GuzzleHttp\Client;
use Webkul\Sales\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;
use Webkul\Product\Models\ProductImage;
use Nicelizhi\Shopify\Models\ShopifyProduct;
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

    static $eventList = [
        self::METRIC_TYPE_100 => 'Placed Order',
        self::METRIC_TYPE_200 => 'Fulfilled Order',
        self::METRIC_TYPE_300 => 'Cancelled Order'
    ];

    public $email;

    public function handle()
    {
        $orderId = $this->option('order_id');
        $metricType = $this->option('metric_type');
        $isDebug = $this->option('is_debug') === '1';

        $order = Order::findOrFail($orderId);
        $this->email = $isDebug ? self::TEST_EMAIL : $order->customer_email;
        echo $this->email, PHP_EOL;

        // 检测是否已发送邮件
        $exists = DB::table('email_send_records')->where([
            'order_id'    => $orderId,
            'email'       => $order->customer_email,
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
                'email' => $order->customer_email,
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
        $line_items = $this->_formatOrderItem($shipment);
        // dd($line_items);

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
            'shop_email'    => core()->getConfigData('emails.configure.email_settings.shop_email_from') ?: 'vip@kundies.com'
        ];
    }

    /**
     * 构建事件属性数据
     * @param Order $order
     * @return array
     */
    protected function buildEventProperties(Order $order): array
    {
        $line_items = $this->_formatOrderItem($order);

        $logo = asset('storage/logo.webp');
        $logo = str_replace('shop.', 'api.', $logo);

        return [
            'order_number'    => config('odoo_api.order_pre') . '#' . $order->id,//$order->increment_id,
            'total'           => core()->currency($order->grand_total),
            'sub_total'       => core()->currency($order->sub_total),
            'discount_amount' => core()->currency($order->discount_amount),
            'shipping_amount' => core()->currency($order->shipping_amount),
            'order_time'      => date('Y-m-d H:i:s', strtotime($order->created_at)),
            'payment'         => ucfirst($order->payment->method),
            'items'           => collect($line_items)->map(function($item) {
                return [
                    'sku'        => $item['name'],
                    'quantity'   => $item['quantity'],
                    'price'      => core()->currency($item['price']),
                    'image'      => $item['img'],
                    'attributes' => $item['attribute_name'] ?? '',
                ];
            })->toArray(),
            'billing_address'  => collect($order->billing_address)->only(['phone', 'address1', 'country', 'city'])->toArray(),
            'shipping_address' => collect($order->shipping_address)->only(['phone', 'address1', 'country', 'city'])->toArray(),
            'username'         => trim($order->shipping_address->first_name . ' ' . $order->shipping_address->last_name),
            'logo'             => $logo,
            'shop_email'       => core()->getConfigData('emails.configure.email_settings.shop_email_from') ?: 'vip@kundies.com'
        ];
    }

    public function _formatOrderItem($order)
    {
        $line_items = [];
        foreach ($order->items as $orderItem) {

            $additional = $orderItem['additional'];
            $variant_id = $additional['selected_configurable_option'] ?: $orderItem['product_id'];
            if (empty($additional['img'])) {
                $additional['img'] = ProductImage::where('product_id', $variant_id)->value('path');
            }

            if (!empty($additional['selected_configurable_option'])) {
                $variant_id = $additional['selected_configurable_option'];
            } else {
                $variant_id = $additional['product_id']; //表示运费险订单
            }
            $shopifyInfo = Product::query()->where('id', $variant_id)->value('sku');
            list($shopify_product_id, $shopify_variant_id) = explode('-', $shopifyInfo);
            $shopifyProduct = ShopifyProduct::query()->where('product_id', $shopify_product_id)->select('variants', 'images', 'options')->first();
            $options = [];
            foreach ($shopifyProduct['variants'] as $variants) {
                if ($variants['id'] == $shopify_variant_id) {
                    $additional['product_sku'] = $variants['sku'];

                    if (!empty($variants['option1'])) {
                        $options['option1'] = $variants['option1'];
                    }
                    if (!empty($variants['option2'])) {
                        $options['option2'] = $variants['option2'];
                    }
                    if (!empty($variants['option3'])) {
                        $options['option3'] = $variants['option3'];
                    }
                    foreach ($shopifyProduct['images'] as $images) {
                        if ($variants['image_id'] == $images['id']) {
                            $additional['img'] = $images['src'];
                            break;
                        }
                    }
                    // 如果没有图片，则取第一个图片
                    if (empty($additional['img'])) {
                        $additional['img'] = $shopifyProduct['images'][0]['src'];
                    }

                    if ($additional['img'] && strpos($additional['img'], 'https') === false) {
                        $additional['img'] = env('APP_URL') . '/cache/large/' . $additional['img'];
                        $additional['img'] = str_replace('shop.', 'api.', $additional['img']);
                    }

                    break;
                }
            }

            if ($variant_id == env('ONEBUY_RETURN_SHIPPING_INSURANCE_PRODUCT_ID')) {
                $additional['img'] = 'https://api.kundies.com/storage/tinymce/cGxK7A86dDzSQl8bEy0WlQPdNy2de8Vur9fHzvXu.webp';
            }

            if (!empty($additional['attributes'])) {
                $additional['attributes'] = array_values($additional['attributes']);
            } else {
                // dump($options);
                $additional['attributes'] = [];

                // 非运费险订单才需要属性
                if ($variant_id != env('ONEBUY_RETURN_SHIPPING_INSURANCE_PRODUCT_ID')) {

                    $i = 0;
                    foreach ($options as $option) {
                        if (empty($shopifyProduct['options'][$i])) {
                            $i++;
                            continue;
                        }
                        $attrName = $shopifyProduct['options'][$i]['name'];
                        $attrValue = $option;

                        $additional['attributes'][] = [
                            'attribute_name' => $attrName,
                            'option_label' => $attrValue,
                        ];

                        $i++;
                    }
                }
            }

            if (!empty($additional['attributes'])) {
                $additional['attributes'] = array_values($additional['attributes']);
            } else {
                $additional['attributes'] = [];
            }

            $additional['price'] = $orderItem['price'];
            $additional['name'] = $orderItem['name'];

            array_push($line_items, $additional);
        }

        return $line_items;
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
        // dd($eventData);
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