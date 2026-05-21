<?php

namespace App\Providers;

use App\Services\SynapCores\SynapCoresAuth;
use App\Services\SynapCores\SynapCoresClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SynapCoresAuth::class, function () {
            return new SynapCoresAuth(
                baseUrl: config('services.synapcores.url'),
                apiKey:  config('services.synapcores.api_key'),
                timeout: config('services.synapcores.timeout'),
            );
        });

        $this->app->singleton(SynapCoresClient::class, function ($app) {
            return new SynapCoresClient(
                auth:    $app->make(SynapCoresAuth::class),
                baseUrl: config('services.synapcores.url'),
                timeout: config('services.synapcores.timeout'),
            );
        });
    }

    public function boot(): void {}
}
