<?php
/**
 *
 * This file is auto generate by Nicelizhi\Apps\Commands\Create
 * @author Steve
 * @date 2024-08-09 17:05:18
 * @link https://github.com/xxxl4
 *
 */
namespace NexaMerchant\Feeds\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Webkul\Shop\Http\Middleware\AuthenticateCustomer;
use Webkul\Shop\Http\Middleware\Currency;
use Webkul\Shop\Http\Middleware\Locale;
use Webkul\Shop\Http\Middleware\Theme;

class FeedsServiceProvider extends ServiceProvider
{
    private $version = null;
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot(Router $router)
    {
        Route::middleware('web')->group(__DIR__ . '/../Routes/web.php');
        Route::middleware('api')->group(__DIR__ . '/../Routes/api.php');

        $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'Feeds');

        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'Feeds');

        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');


        /*
        $this->app->register(EventServiceProvider::class);
        */

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../Resources/views' => $this->app->resourcePath('themes/default/views'),
            ], 'Feeds');
        }

    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->registerCommands();
        $this->registerConfig();
    }

    /**
     * Register package config.
     *
     * @return void
     */
    protected function registerConfig()
    {

        $this->mergeConfigFrom(
            dirname(__DIR__) . '/Config/menu.php', 'menu.admin'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__) . '/Config/acl.php',
            'acl'
        );


        $this->mergeConfigFrom(
            dirname(__DIR__) . '/Config/Feeds.php', 'Feeds'
        );

        // api docs
        $this->mergeConfigFrom(
            dirname(__DIR__) . '/Config/l5-swagger.php', 'l5-swagger.documentations'
        );

    }

    /**
     * Register the console commands of this package.
     *
     * @return void
     */
    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                \NexaMerchant\Feeds\Console\Commands\Install::class,
                \NexaMerchant\Feeds\Console\Commands\UnInstall::class,
                \NexaMerchant\Feeds\Console\Commands\Klaviyo\Push::class,
                \NexaMerchant\Feeds\Console\Commands\Klaviyo\ProfileImport::class,
            ]);
        }
    }
}
