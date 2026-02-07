<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\AllowedDomain;
use Symfony\Component\HttpFoundation\Response;

class CheckAllowedDomain
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        // Si no hay usuario autenticado, dejar pasar (otros middlewares manejarán esto)
        if (!$user) {
            return $next($request);
        }

        // Si es autenticación local, permitir acceso
        if ($user->auth_type === 'local') {
            return $next($request);
        }

        // Si es autenticación Microsoft, verificar dominio
        if ($user->auth_type === 'microsoft') {
            $email = $user->email;
            
            if (!AllowedDomain::isEmailAllowed($email)) {
                return response()->json([
                    'message' => 'Acceso denegado',
                    'error' => 'Tu dominio de correo no tiene acceso a esta aplicación'
                ], 403);
            }
        }

        return $next($request);
    }
}
