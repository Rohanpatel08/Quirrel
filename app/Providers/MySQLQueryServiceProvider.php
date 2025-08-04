<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\Judge0Service;
use App\Services\MySQLExecutionService;

class MySQLQueryServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(Judge0Service::class, function ($app) {
            return new Judge0Service();
        });

        $this->app->singleton(MySQLExecutionService::class, function ($app) {
            return new MySQLExecutionService();
        });
    }

    public function boot()
    {
        // Add Judge0 configuration
        config([
            'app.judge0_api_url' => env('JUDGE0_API_URL', 'http://localhost:2358'),
            'app.judge0_mysql_language_id' => env('JUDGE0_MYSQL_LANGUAGE_ID', 100),
        ]);
    }
}