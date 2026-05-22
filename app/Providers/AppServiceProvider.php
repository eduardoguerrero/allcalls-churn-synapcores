<?php

namespace App\Providers;

use App\Repositories\EloquentLoyaltyMemberRepository;
use App\Repositories\LoyaltyMemberRepositoryInterface;
use App\Services\SynapCores\SynapCoresAuth;
use App\Services\SynapCores\SynapCoresClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LoyaltyMemberRepositoryInterface::class, EloquentLoyaltyMemberRepository::class);

        $this->app->singleton(SynapCoresAuth::class, function () {
            return new SynapCoresAuth(
                baseUrl:  config('services.synapcores.url'),
                username: config('services.synapcores.username'),
                password: config('services.synapcores.password'),
                apiKey:   config('services.synapcores.api_key'),
            );
        });

        $this->app->singleton(SynapCoresClient::class, function ($app) {
            return new SynapCoresClient(
                auth:     $app->make(SynapCoresAuth::class),
                baseUrl:  config('services.synapcores.url'),
                database: config('services.synapcores.database'),
                timeout:  config('services.synapcores.timeout'),
            );
        });
    }

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });
    }
}
