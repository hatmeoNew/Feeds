<?php
/**
 * 
 * This file is auto generate by Nicelizhi\Apps\Commands\Create
 * @author Steve
 * @date 2024-08-09 17:05:18
 * @link https://github.com/xxxl4
 * 
 */
namespace NexaMerchant\Feeds\Console\Commands;

use NexaMerchant\Apps\Console\Commands\CommandInterface;

class Install extends CommandInterface 

{
    protected $signature = 'Feeds:install';

    protected $description = 'Install Feeds an app';

    public function getAppVer() {
        return config("Feeds.ver");
    }

    public function getAppName() {
        return config("Feeds.name");
    }

    public function handle()
    {
        $this->info("Install app: Feeds");
        if (!$this->confirm('Do you wish to continue?')) {
            // ...
            $this->error("App Feeds Install cannelled");
            return false;
        }
    }
}