<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('login');
    }

    /**
     * Handle an incoming request.
     */
    protected function authenticate($request, array $guards)
    {
        parent::authenticate($request, $guards);

        // Log de diagnóstico: empresa del usuario autenticado
        try {
            $user = auth('api')->user();

            if ($user) {
                $contexto = \App\Models\UsuarioContexto::where('user_id', $user->id)
                    ->with(['empresa', 'sucursal', 'sede'])
                    ->first();

                Log::channel('daily')->info('🔐 Usuario autenticado', [
                    'user_id'      => $user->id,
                    'email'        => $user->email,
                    'estado'       => $user->estado,
                    'ruta'         => $request->path(),
                    'metodo'       => $request->method(),
                    'ip'           => $request->ip(),
                    'contexto'     => $contexto ? [
                        'empresa_id'   => $contexto->empresa_id,
                        'empresa'      => $contexto->empresa?->nombre ?? 'N/A',
                        'sucursal_id'  => $contexto->sucursal_id,
                        'sucursal'     => $contexto->sucursal?->nombre ?? 'N/A',
                        'sede_id'      => $contexto->sede_id,
                        'sede'         => $contexto->sede?->nombre ?? 'N/A',
                    ] : 'SIN CONTEXTO',
                    'empresas_asignadas' => $user->empresas()->pluck('ent_empresas.nombre', 'ent_empresas.id'),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('❌ Error en log de autenticación', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
