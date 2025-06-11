<?php

namespace NexaMerchant\Feeds\Console\Commands\Klaviyo;

use GuzzleHttp\Client;
use Webkul\Sales\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;
use Nicelizhi\Shopify\Helpers\Utils;
use Webkul\Product\Models\ProductImage;

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

    static $metricTypeList = [
        self::METRIC_TYPE_100 => 'Placed Order',
    ];

    public function handle()
    {
        // 获取参数
        $orderId = $this->option('order_id');
        $metricType = $this->option('metric_type');
        $isDebug = $this->option('is_debug');

        try {
            // 1. 根据订单ID查询订单信息
            $order = Order::query()->where('id', $orderId)->first();
            if (empty($order)) {
                $this->info('No order found.');
                return 0;
            }

            // 检测是否已发送邮件
            $exists = DB::table('email_send_records')->where([
                'order_id'    => $orderId,
                'email'       => $order->email,
                'send_status' => 'success',
                'metric_name' => self::$metricTypeList[$metricType],
            ])->exists();
            if ($exists) {
                $this->info('订单 ' . $orderId . ' 已发送邮件');
                return 0;
            }

            // dd($this->buildEventProperties($order));

            // 2. 构造Klaviyo事件数据
            $eventData = [
                'data' => [
                    'type' => 'event',
                    'attributes' => [
                        'properties' => $this->buildEventProperties($order),
                        'metric' => [
                            'data' => [
                                'type' => 'metric',
                                'attributes' => [
                                    'name' => self::$metricTypeList[$metricType]
                                ]
                            ]
                        ],
                        'profile' => [
                            'data' => [
                                'type' => 'profile',
                                'attributes' => [
                                    'properties' => [
                                        'email' => $isDebug ? self::TEST_EMAIL : $order->customer_email
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]
            ];

            // dd($eventData);

            // 3. 发送Klaviyo API请求
            $client = new Client();
            $response = $client->post(self::API_URL, [
                'headers' => [
                    'Accept'        => 'application/vnd.api+json',
                    'Revision'      => self::REVISION,
                    'Authorization' => 'Klaviyo-API-Key ' . config('mail.mailers.klaviyo.api_key'),
                ],
                'json' => $eventData
            ]);

            $email_send_record = [
                'order_id' => $orderId,
                'email' => $order->customer_email,
                'metric_name' => self::$metricTypeList[$metricType],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            // 4. 处理响应
            if ($response->getStatusCode() >= 400) {
                $this->error("Klaviyo API请求失败: {$response->getStatusCode()}: " . $response->getBody()->getContents());
                Log::error("Klaviyo Event Error", [
                    'order_id' => $orderId,
                    'response' => $response->getBody()->getContents()
                ]);

                DB::table('email_send_records')->insert(
                    array_merge($email_send_record, [
                        'send_status' => 'failed',
                        'failure_reason' => json_encode([
                            'statusCode' => $response->getStatusCode(),
                            'response' => $response->getBody()->getContents()
                        ])
                    ])
                );

                Utils::sendFeishu($orderId . 'email send failed');

                return false;
            }

            DB::table('email_send_records')->insert(
                array_merge($email_send_record, [
                    'send_status' => 'success',
                ])
            );

            Log::info("Klaviyo Event Sent Success!", ['order_id' => $orderId]);

            return true;
        } catch (\Exception $e) {
            // $this->error("处理失败: {$e->getMessage()}");
            // Log::error("Klaviyo Command Error", [
            //     'order_id' => $orderId,
            //     'error' => $e->getMessage()
            // ]);

            Utils::sendFeishu(json_encode([
                'order_id' => $orderId,
                'line' => $e->getLine(),
                'error' => 'email send fail:' . $e->getMessage()
            ]));

            return false;
        }
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
            'billing_address'  => collect($order->billing_address)->only(['phone', 'address1'])->toArray(),
            'shipping_address' => collect($order->shipping_address)->only(['phone', 'address1'])->toArray(),
            'username'         => trim($order->shipping_address->first_name . ' ' . $order->shipping_address->last_name),
            'logo'             => asset('storage/logo.webp'),
        ];
    }
}