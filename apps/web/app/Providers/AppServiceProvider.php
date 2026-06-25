<?php

namespace App\Providers;

use App\Services\Pass1\GraderClient;
use App\Services\Pass1\ZenGraderClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GraderClient::class, ZenGraderClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
