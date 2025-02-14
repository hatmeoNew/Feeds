<?php
/**
 * 
 * This file is auto generate by Nicelizhi\Apps\Commands\Create
 * @author Steve
 * @date 2024-08-09 17:05:18
 * @link https://github.com/xxxl4
 * 
 */
use Illuminate\Support\Facades\Route;
use NexaMerchant\Feeds\Http\Controllers\Web\ExampleController;
use NexaMerchant\Feeds\Http\Controllers\Web\FeedsController;

Route::group(['middleware' => ['locale', 'theme', 'currency'], 'prefix'=>'feeds'], function () {

    Route::controller(ExampleController::class)->prefix('example')->group(function () {

        Route::get('demo', 'demo')->name('feeds.web.example.demo');

    });

    Route::controller(FeedsController::class)->prefix('')->group(function () {

        Route::get('atom', 'atom')->name('feeds.web.feeds.atom');
        Route::get('klaviyo', 'klaviyo')->name('feeds.web.feeds.klaviyo');

    });

});

include "admin.php";