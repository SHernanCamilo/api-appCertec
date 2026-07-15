<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon enviará notificaciones cuando un job falle
        // Horizon::routeSmsNotificationsTo('...');
        // Horizon::routeMailNotificationsTo('admin@medilaser.com.co');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user) {
            return in_array($user->email, [
                'jscabreras@medilaser.com.co',
                'admin@medilaser.com.co',
            ]);
        });
    }
}
