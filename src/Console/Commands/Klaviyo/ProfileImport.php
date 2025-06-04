<?php
namespace NexaMerchant\Feeds\Console\Commands\Klaviyo;

use Illuminate\Console\Command;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\NumberParseException;
use Illuminate\Support\Facades\Cache;

class ProfileImport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'profile:import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Push profile to Klaviyo';

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
        $this->info('Pushing customers to Klaviyo...');

        ini_set('memory_limit', '312M');

        // dd(self::formatPhoneNumber('8712358270'));

        $brandName = Push::getBrandMapping();
        $emaillist = $this->createEmailList($brandName);
        // dd($emaillist);
        $client = new \GuzzleHttp\Client();

        $filePath = storage_path('imports/kundies_com.csv');
        if (!file_exists($filePath)) {
            dd('Excel file not found: ' . $filePath);
            return 1;
        }
        // $spreadsheet = IOFactory::load($filePath);
        // $sheetData = $spreadsheet->getActiveSheet()->toArray();

        foreach ($this->getFileData($filePath) as $line => $row) {
            if ($line == 0 || $line < 142) continue;
            $first_name = $row[0] ?? '';
            $last_name = $row[1] ?? '';
            $email = $row[2] ?? '';
            if (empty($email)) {
                $this->error('Email is empty on line ' . ($line + 1));
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->error('Invalid email address: ' . ($line + 1));
                continue;
            }
            $this->info($line . ' -- Pushing profile to Klaviyo...' . $email);
            $phone_number = $row[13] ?? '';
            if (!empty($phone_number)) {
                $phone_number = self::formatPhoneNumber($phone_number);
            }
            $location = [
                'address1' => $row[4] ?? '',
                'address2' => $row[5] ?? '',
                'city' => $row[7] ?? '',
                'region' => $row[8] ?? '',
                'country' => $row[10] ?? '',
                'zip' => $row[12] ?? '',
            ];

            $location = array_filter($location, function ($value) {
                return !empty($value);
            });

            $data = [
                'data' => [
                    'type' => 'profile',
                    'attributes' => [
                        'email' => $email
                    ],
                ],
            ];

            if (!empty($first_name)) {
                $data['data']['attributes']['first_name'] = $first_name;
            }

            if (!empty($last_name)) {
                $data['data']['attributes']['last_name'] = $last_name;
            }

            if (!empty($phone_number)) {
                $data['data']['attributes']['phone_number'] = $phone_number;
            }

            if ($location) {
                $data['data']['attributes']['location'] = $location;
            }
            // dd($data);
            try {
                // 推送到 Klaviyo
                $profile = $this->profile($email, $data);
                if (!$profile) {
                    dump('Profile not found for email: ' . $email);
                    continue;
                }

                // 关联到分组
                $emaillist = $this->createEmailList($brandName);
                if (empty($emaillist)) {
                    $this->error('Email list not found for brand: ' . $brandName);
                    continue;
                }

                // dd($emaillist);

                $profile_id = isset($profile['data']['id']) ? $profile['data']['id'] : $profile['data'][0]['id'];

                $client->request('POST', 'https://a.klaviyo.com/api/lists/' . $emaillist[0]['id'] . '/relationships/profiles', [
                    'body' => '{"data":[{"type":"profile","id":"' . $profile_id . '"}]}',
                    'headers' => [
                        'Authorization' => 'Klaviyo-API-Key ' . config('mail.mailers.klaviyo.api_key'),
                        'accept' => 'application/vnd.api+json',
                        'content-type' => 'application/vnd.api+json',
                        'revision' => $this->revision,
                    ],
                ]);

                // dd('Profile pushed successfully: ' . $email);
            } catch (\Exception $e) {
                $this->error('Error pushing profile: ' . $e->getMessage());
                dd();
            }
            sleep(1); // 避免请求过快
        }

    }

    /**
     * 获取csv文件数据
     * @param string $filename
     * @return \Generator
     */
    public function getFileData(string $filename)
    {
        if (!file_exists($filename)) return [];
        $file = fopen($filename, 'r');
        while ($arr = fgetcsv($file)) {
            yield $arr;
        }
        fclose($file);
    }

    public static function formatPhoneNumber($phone)
    {
        $phoneUtil = PhoneNumberUtil::getInstance();
        try {
            $number = $phoneUtil->parse($phone, config('onebuy.default_country'));
            return $phoneUtil->format($number, PhoneNumberFormat::E164);
        } catch (NumberParseException $e) {
            return ""; // Invalid phone number
        }
    }

    // https://developers.klaviyo.com/en/reference/create_list
    public function createEmailList($name)
    {
        // 检查缓存
        $cachedList = Cache::get('klaviyo_email_list_' . $name);
        if ($cachedList) {
            // dump('Using cached email list for: ' . $name);
            return json_decode($cachedList, true);
        }
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
            // 写入缓存
            Cache::put('klaviyo_email_list_' . $name, json_encode($found), 60 * 24 * 30); // 缓存30天
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
        $res = json_decode($body, true);

        // 写入缓存
        Cache::put('klaviyo_email_list_' . $name, $body, 60 * 24 * 30); // 缓存30天

        return $res;
    }


    public function profile($email, $body)
    {
        // if the profile email is not a valid email address, the request will fail
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        // $this->info('Pushing profile to Klaviyo...' . $email);
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
            // dd($body);
            try {
                $response = $client->request('POST', 'https://a.klaviyo.com/api/profiles', [
                    'body' => json_encode($body),
                    'headers' => [
                        'Authorization' => 'Klaviyo-API-Key ' . config('mail.mailers.klaviyo.api_key'),
                        'accept' => 'application/vnd.api+json',
                        'content-type' => 'application/vnd.api+json',
                        'revision' => $this->revision,
                    ],
                ]);
            } catch (\Throwable $e) {
                dump([
                    'email' => $email,
                    'message' => $e->getMessage(),
                    'response' => $e->getResponse()?->getBody()->getContents(),
                ]);
                return [];
            }

            $body = $response->getBody();
            return json_decode($body, true);
        }

        return $result;
    }

}