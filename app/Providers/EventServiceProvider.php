<?php

namespace App\Providers;

use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        // Registrar el provider de Microsoft Azure
        Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
            $event->extendSocialite('microsoft', \SocialiteProviders\Azure\Provider::class);
        });

        Event::listen(function (\Illuminate\Queue\Events\Looping $event) {
            $queue = (string) ($event->queue ?? '');
            if (!str_contains($queue, 'scheduled-tasks')) {
                return;
            }

            $directory = storage_path('framework');
            if (!is_dir($directory)) {
                return;
            }

            $safeQueue = preg_replace('/[^a-z0-9_-]/i', '_', $queue) ?: 'scheduled-tasks';
            @file_put_contents(
                $directory . DIRECTORY_SEPARATOR . 'scheduled-tasks-worker-' . $safeQueue . '.json',
                json_encode(['beat' => now()->toIso8601String(), 'queue' => $queue])
            );

            static $lastCatchUp = 0;
            if (time() - $lastCatchUp >= 30) {
                $lastCatchUp = time();
                try {
                    app(\App\Services\TaskSchedulerService::class)->enqueueDueRecurringTasks();
                } catch (\Throwable $exception) {
                    \Illuminate\Support\Facades\Log::warning('No se pudieron reencolar tareas vencidas', [
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        });
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
