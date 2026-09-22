<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        // ─── Rate limiting POR USUARIO/TOKEN, no por IP ─────────────────────
        //
        // PROBLEMA que resuelve: toda la organizacion sale por la MISMA IP
        // publica. El limiter anterior caia a `$request->ip()` cuando no resolvia
        // el usuario, asi que un solo usuario (p.ej. exportando un mes de Excel)
        // agotaba el cupo y BLOQUEABA A TODOS con "Too Many Attempts".
        //
        // Solucion: identificar al usuario por su token JWT y limitar por su id.
        // Cada usuario tiene su propio cupo; nunca se comparte por IP. La IP solo
        // se usa como ultimo recurso para peticiones anonimas (login), y con un
        // cupo generoso para no castigar a varios usuarios tras la misma IP.
        RateLimiter::for('api', function (Request $request) {
            $userId = $this->resolveUserId($request);

            if ($userId !== null) {
                // Usuario autenticado: cupo amplio y aislado por su id.
                return Limit::perMinute(300)->by('user:' . $userId);
            }

            // Anonimo (login, etc.): por IP, pero holgado porque muchos usuarios
            // de la organizacion comparten la misma IP publica.
            return Limit::perMinute(120)->by('ip:' . $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Resuelve el id del usuario desde el token JWT sin romper si el token es
     * invalido o no viene.
     *
     * Se intenta el guard 'api' (JWT) explicitamente porque el limiter puede
     * ejecutarse antes de que el middleware de auth haya poblado el usuario por
     * defecto; asi el rate limit queda ligado al TOKEN del usuario y no a la IP.
     */
    private function resolveUserId(Request $request): ?int
    {
        try {
            $user = $request->user() ?: Auth::guard('api')->user();
            return $user?->id;
        } catch (\Throwable $e) {
            // Token ausente/invalido/expirado: se trata como anonimo.
            return null;
        }
    }
}
