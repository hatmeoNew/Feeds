<?php

namespace NexaMerchant\Feeds\Console\Commands\Klaviyo;

use Illuminate\Console\Command;
use Webkul\Customer\Models\Customer;
use Webkul\Sales\Models\Order;

class Push extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'klaviyo:push {order_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Push products to Klaviyo';

    protected $revision = '2025-04-15';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // $this->info('Pushing products to Klaviyo...');
        // sync customers to klaviyo
        $this->info('Pushing customers to Klaviyo...');

        // var_dump(config('mail.mailers.klaviyo.api_key'));
        $brandMapping = $this->getBrandMapping();
        if (!isset($brandMapping[config('onebuy.brand')])) {
            $this->error('Brand not found in mapping: ' . config('onebuy.brand'));
            return 1;
        }
        $emaillist = $this->createEmailList($brandMapping[config('onebuy.brand')]);
        //var_dump($emaillist);exit;

        $orderQuery = Order::select(['customer_email', 'customer_first_name', 'customer_last_name', 'id']);
        if ($this->argument('order_id')) {
            $orderQuery->where('id', $this->argument('order_id'));
        }
        // $orderQuery->orderBy("id", "DESC")->limit(10);
        // $orders = Order::select(['customer_email','customer_first_name','customer_last_name','id'])->orderBy("id", "DESC")->limit(1000)->get();
        $orders = $orderQuery->get();
        if ($orders->isEmpty()) {
            $this->info('No orders found.');
            return 0;
        }
        $client = new \GuzzleHttp\Client();
        $total = count($orders);
        foreach ($orders as $k => $order) {
            $this->info('Pushing order ' . $k . '/' . $total . ' ' . $order->customer_email);
            //$customer = $order->customer_email;

            // post to klaviyo
            // $this->info('Pushing order to Klaviyo...');
            //$order->customer_email = 'nice.lizhi@gmail.com';

            try  {
                $profile = $this->profile($order->customer_email, $order->customer_first_name, $order->customer_last_name);
                if (!$profile) {
                    dump('Profile not found for email: ' . $order->customer_email);
                    continue;
                }

                $profile_id = isset($profile['data']['id']) ? $profile['data']['id'] : $profile['data'][0]['id'];

                $response = $client->request('POST', 'https://a.klaviyo.com/api/lists/' . $emaillist[0]['id'] . '/relationships/profiles', [
                    'body' => '{"data":[{"type":"profile","id":"' . $profile_id . '"}]}',
                    'headers' => [
                        'Authorization' => 'Klaviyo-API-Key ' . config('mail.mailers.klaviyo.api_key'),
                        'accept' => 'application/vnd.api+json',
                        'content-type' => 'application/vnd.api+json',
                        'revision' => $this->revision,
                    ],
                ]);
            } catch (\Exception $e) {
                // $this->error('Error: ' . $e->getMessage());
                dump($e->getMessage());
            }

            //   echo $response->getBody();

            //exit;
            // sleep(1);
        }
    }

    // https://developers.klaviyo.com/en/reference/create_list
    public function createEmailList($name)
    {
        $client = new \GuzzleHttp\Client();
        $query = [
            'filter' => 'equals(name,"' . $name . '")'
        ];
        $response = $client->request('GET', 'https://a.klaviyo.com/api/lists', [
            'headers' => [
                'Authorization' => 'Klaviyo-API-Key ' . config('mail.mailers.klaviyo.api_key'),
                'Accept'        => 'application/vnd.api+json',
                'Revision'      => $this->revision,
            ],
            'query' => $query
        ]);

        $body = $response->getBody()->getContents();
        $result = json_decode($body, true);
        $found = array_filter($result['data'], function ($list) use ($name) {
            return isset($list['attributes']['name']) && $list['attributes']['name'] === $name;
        });

        if (!empty($found)) {
            return $found;
        }

        $response = $client->request('POST', 'https://a.klaviyo.com/api/lists', [
            'body' => '{"data":{"type":"list","attributes":{"name":"' . $name . '"}}}',
            'headers' => [
                'Authorization' => 'Klaviyo-API-Key ' . config('mail.mailers.klaviyo.api_key'),
                'accept' => 'application/vnd.api+json',
                'content-type' => 'application/vnd.api+json',
                'revision' => $this->revision,
            ],
        ]);

        $body = $response->getBody();
        return json_decode($body, true);
    }


    public function profile($email, $first_name, $last_name)
    {
        // if the profile email is not a valid email address, the request will fail
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $this->info('Pushing profile to Klaviyo...' . $email);
        $client = new \GuzzleHttp\Client();
        $query = [
            'filter' => 'equals(email,"' . $email . '")'
        ];
        $response = $client->request('GET', 'https://a.klaviyo.com/api/profiles', [
            'headers' => [
                'Authorization' => 'Klaviyo-API-Key ' . config('mail.mailers.klaviyo.api_key'),
                'accept' => 'application/vnd.api+json',
                'content-type' => 'application/vnd.api+json',
                'revision' => $this->revision,
            ],
            'query' => $query
        ]);
        $data = $response->getBody()->getContents();
        $result = json_decode($data, true);
        if (empty($result['data'])) {
            //https://developers.klaviyo.com/en/reference/create_profile
            $response = $client->request('POST', 'https://a.klaviyo.com/api/profiles', [
                'body' => '{"data":{"type":"profile","attributes":{"email":"' . $email . '","first_name":"' . $first_name . '","last_name":"' . $last_name . '"}}}',
                'headers' => [
                    'Authorization' => 'Klaviyo-API-Key ' . config('mail.mailers.klaviyo.api_key'),
                    'accept' => 'application/vnd.api+json',
                    'content-type' => 'application/vnd.api+json',
                    'revision' => '2025-01-15',
                ],
            ]);
            $body = $response->getBody();
            return json_decode($body, true);
        }

        return $result;
    }

    public function getBrandMapping()
    {
        return [
            'HatmeHU' => 'HatmeHU',
            'KundiesCZ' => 'KundiesCZ',
            'KundiesPL' => 'KundiesPL',
            'MqqhotCZ' => '捷克综合站Mqqhot.com',
            'OthshoeCZ' => 'OthshoeCZ',
            'OthshoePl' => 'OthshoePl',
            'SedyesPL' => '波兰综合站Sedyes.com',
            'ROCOD' => 'ROCOD',
            'WmbhSK' => 'WmbhSK',
            'BotmaFR' => 'BotmaFR',
            'GofreiDE' => 'GofreiDE',
            'HatmeDE' => '德国综合站hatme.de',
            'HautotoolDE' => 'HautotoolDE',
            'KundiesDE' => '德国内衣站kundies.de',
            'OthshoeDE' => '德国鞋子站othshoe.de',
            'OthshoeUK' => '英国鞋子站othshoe.uk',
            'WmbhUK' => '英国内衣站wmbh.uk',
            'WmbraDE' => 'WmbraDE',
            'Wmbrashop' => 'Wmbrashop',
            'WmbraUk' => 'WmbraUk',
            'WmcerES' => 'WmcerES',
            'WngiftDE' => 'WngiftDE',
            'YooJeUK' => '英国综合站yooje.uk',
            'HatmeAT' => 'HatmeAT',
            'WmcerESCOD' => 'WmcerESCOD',
            'WmcerIT' => 'WmcerIT',
            'Hatmeo' => '美国综合站hatmeo.com',
            'HatmeoNet' => 'HatmeoNet',
            'Hautotool' => 'Hautotool',
            'Kundies' => '美国内衣站kundies.com',
            'Othshoe' => '美国鞋子站othshoe.com',
            'Wngift' => 'Wngift',
        ];
    }
}
