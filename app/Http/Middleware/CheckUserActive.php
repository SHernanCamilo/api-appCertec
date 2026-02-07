<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUserActive
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Solo aplicar a rutas autenticadas
        if (auth('api')->check()) {
            $user = auth('api')->user();
            
            // Verificar que el usuario esté activo
            if (!$user->estaActivo()) {
                \Log::warning('🚫 Usuario inactivo intentando acceder a ruta protegida:', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'route' => $request->path(),
                    'ip' => $request->ip()
                ]);
                
                // Invalidar la sesión
                auth('api')->logout();
                
                return response()->json([
                    'error' => 'Cuenta inactiva',
                    'message' => 'Tu cuenta ha sido desactivada. Por favor, contacta al administrador.',
                    'code' => 'USER_INACTIVE'
                ], 403);
            }
        }
        
        return $next($request);
    }
}