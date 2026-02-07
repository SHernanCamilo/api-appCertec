<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\GLPI\GLPIService;
use App\Services\GLPI\GLPIComputerService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Registrar servicios de GLPI
        $this->app->singleton(GLPIService::class, function ($app) {
            return new GLPIService();
        });

        $this->app->singleton(GLPIComputerService::class, function ($app) {
            return new GLPIComputerService($app->make(GLPIService::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
