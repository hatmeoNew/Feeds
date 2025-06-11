<?php

namespace NexaMerchant\Feeds\Console\Commands\Klaviyo;

use GuzzleHttp\Client;
use Webkul\Sales\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendKlaviyoEvent extends Command
{
    protected $signature = 'klaviyo:event {order_id=} {metric_name=} {is_debug?}';
    protected $description = '上报Klaviyo事件以触发邮件发送';

    const REVISION = '2025-04-15';
    const API_URL = 'https://a.klaviyo.com/api/events';
    const TEST_EMAIL = '916675194@qq.com';

    public function handle()
    {
        // 获取参数
        $orderId = $this->option('order_id');
        $metricName = $this->option('metric_name');
        $isDebug = $this->argument('is_debug');

        try {
            // 1. 根据订单ID查询订单信息
            $order = Order::select(['customer_email', 'customer_first_name', 'customer_last_name', 'id'])->where('id', $orderId)->first();
            if ($order->isEmpty()) {
                $this->info('No order found.');
                return 0;
            }

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
                                    'name' => $metricName
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

            // 4. 处理响应
            if ($response->failed()) {
                $this->error("Klaviyo API请求失败: {$response->status()}: {$response->body()}");
                Log::error("Klaviyo Event Error", [
                    'order_id' => $orderId,
                    'response' => $response->body()
                ]);
                return false;
            }

            $this->info("事件上报成功: {$response->json()['data']['id']}");
            Log::info("Klaviyo Event Sent", ['order_id' => $orderId]);
            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("处理失败: {$e->getMessage()}");
            Log::error("Klaviyo Command Error", [
                'order_id' => $orderId,
                'error' => $e->getMessage()
            ]);
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
        return [
            'order_number'    => $order->increment_id,
            'total'           => core()->currency($order->grand_total),
            'sub_total'       => core()->currency($order->sub_total),
            'discount_amount' => core()->currency($order->discount_amount),
            'shipping_amount' => core()->currency($order->shipping_amount),
            'order_time'      => $order->created_at,
            'payment'         => $order->payment->method,
            'items'           => $order->items->map(function($item) {
                return [
                    'name'            => $item->name,
                    'sku'             => $item->sku,
                    'quantity'        => $item->qty_ordered,
                    'price'           => core()->currency($item->price),
                    'discount_amount' => core()->currency($item->discount_amount),
                    'image'           => $item->image_url,
                    'attributes'      => $item->additional['attributes'],
                ];
            })->toArray(),
            'billing_address'  => $order->billing_address,
            'shipping_address' => $order->shipping_address,
            'username'         => trim($order->shipping_address->first_name . ' ' . $order->shipping_address->last_name),
            'logo'             => storage_path('app/public/logo.webp'),
        ];
    }
}