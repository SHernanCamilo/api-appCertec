<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();

        if (!$user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado',
            ], 401);
        }

        if (!$user->esAdministrador()) {
            Log::warning('Acceso admin denegado', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ruta' => $request->path(),
                'metodo' => $request->method(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Solo un administrador puede realizar esta acción.',
            ], 403);
        }

        return $next($request);
    }
}
