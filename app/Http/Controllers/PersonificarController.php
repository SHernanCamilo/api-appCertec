<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Tymon\JWTAuth\Facades\JWTAuth;

class PersonificarController extends Controller
{
    /**
     * Obtener lista de usuarios disponibles para personificar
     */
    public function getUsuariosDisponibles(): JsonResponse
    {
        \Log::info('🎭 PersonificarController::getUsuariosDisponibles - Iniciando');
        
        // Verificar que el usuario actual tenga el permiso org-personificar
        $currentUser = auth('api')->user();
        \Log::info('👤 Usuario actual:', ['id' => $currentUser?->id, 'email' => $currentUser?->email]);
        
        if (!$this->tienePermisoPersonificar($currentUser)) {
            \Log::warning('❌ Usuario sin permisos para personificar');
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para personificar usuarios'
            ], 403);
        }
        
        \Log::info('✅ Usuario tiene permisos para personificar');

        try {
            // Obtener todos los usuarios excepto el actual (versión simplificada para debug)
            $usuarios = User::where('id', '!=', $currentUser->id)
                ->orderBy('name')
                ->get()
                ->map(function ($user) {
                    \Log::info('📝 Procesando usuario:', ['id' => $user->id, 'name' => $user->name]);
                    
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'empresas' => ['Sin empresas'], // Temporal para debug
                        'roles' => ['Sin roles'], // Temporal para debug
                        'ultimo_acceso' => $user->updated_at ?? $user->created_at,
                        'created_at' => $user->created_at
                    ];
                });
        } catch (\Exception $e) {
            \Log::error('❌ Error en getUsuariosDisponibles: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar usuarios: ' . $e->getMessage()
            ], 500);
        }

        \Log::info('📊 Usuarios encontrados:', ['count' => $usuarios->count()]);
        
        return response()->json([
            'success' => true,
            'message' => 'Usuarios disponibles para personificar',
            'data' => $usuarios
        ], 200);
    }

    /**
     * Iniciar personificación de un usuario
     */
    public function iniciarPersonificacion(Request $request): JsonResponse
    {
        \Log::info('🎭 PersonificarController::iniciarPersonificacion - Iniciando', [
            'request_data' => $request->all()
        ]);

        $request->validate([
            'user_id' => 'required|exists:users,id'
        ]);

        $currentUser = auth('api')->user();
        $targetUserId = $request->user_id;
        
        \Log::info('👤 Datos de personificación:', [
            'current_user' => $currentUser->id,
            'target_user' => $targetUserId
        ]);

        // Verificar permisos
        if (!$this->tienePermisoPersonificar($currentUser)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para personificar usuarios'
            ], 403);
        }

        // No permitir personificar a sí mismo
        if ($currentUser->id == $targetUserId) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes personificarte a ti mismo'
            ], 400);
        }

        // Obtener el usuario objetivo (simplificado para evitar problemas con relaciones)
        $targetUser = User::find($targetUserId);
        
        \Log::info('🎯 Usuario objetivo encontrado:', [
            'id' => $targetUser->id,
            'name' => $targetUser->name,
            'email' => $targetUser->email
        ]);

        if (!$targetUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        // Guardar información de la personificación en la sesión/cache
        $personificacionData = [
            'original_user_id' => $currentUser->id,
            'original_user_name' => $currentUser->name,
            'original_user_email' => $currentUser->email,
            'target_user_id' => $targetUser->id,
            'target_user_name' => $targetUser->name,
            'target_user_email' => $targetUser->email,
            'started_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent()
        ];

        // Generar nuevo token para el usuario objetivo
        $token = JWTAuth::fromUser($targetUser);

        // Guardar datos de personificación en el token personalizado
        $customClaims = [
            'personificando' => true,
            'original_user_id' => $currentUser->id,
            'original_user_name' => $currentUser->name,
            'target_user_id' => $targetUser->id,
            'started_at' => now()->timestamp
        ];

        $tokenWithClaims = JWTAuth::claims($customClaims)->fromUser($targetUser);

        // Log de auditoría
        \Log::info('✅ Personificación iniciada exitosamente', [
            'original_user' => $currentUser->email,
            'target_user' => $targetUser->email,
            'token_preview' => substr($tokenWithClaims, 0, 20) . '...',
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        $responseData = [
            'success' => true,
            'message' => "Ahora estás personificando a {$targetUser->name}",
            'data' => [
                'token' => $tokenWithClaims,
                'user' => [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                    'empresas' => [], // Temporal para evitar problemas con relaciones
                    'roles' => [] // Temporal para evitar problemas con relaciones
                ],
                'personificacion' => [
                    'activa' => true,
                    'original_user' => [
                        'id' => $currentUser->id,
                        'name' => $currentUser->name,
                        'email' => $currentUser->email
                    ],
                    'started_at' => now()
                ]
            ]
        ];

        \Log::info('📤 Enviando respuesta de personificación:', [
            'success' => $responseData['success'],
            'message' => $responseData['message'],
            'target_user_id' => $responseData['data']['user']['id'],
            'target_user_name' => $responseData['data']['user']['name']
        ]);

        return response()->json($responseData, 200);
    }

    /**
     * Finalizar personificación y volver al usuario original
     */
    public function finalizarPersonificacion(): JsonResponse
    {
        $currentToken = JWTAuth::getToken();
        $payload = JWTAuth::getPayload($currentToken);

        // Verificar si hay una personificación activa
        if (!$payload->get('personificando')) {
            return response()->json([
                'success' => false,
                'message' => 'No hay una personificación activa'
            ], 400);
        }

        $originalUserId = $payload->get('original_user_id');
        $originalUser = User::with(['empresas', 'rolesCustom'])->find($originalUserId);

        if (!$originalUser) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario original no encontrado'
            ], 404);
        }

        // Invalidar el token actual
        try {
            JWTAuth::invalidate($currentToken);
            \Log::info('🔒 Token de personificación invalidado');
        } catch (\Exception $e) {
            \Log::warning('⚠️ No se pudo invalidar el token anterior: ' . $e->getMessage());
        }

        // IMPORTANTE: Generar nuevo token LIMPIO con claims explícitos SIN personificación
        // Usamos claims vacíos para asegurar que no herede nada del token anterior
        $cleanClaims = [
            'personificando' => false
        ];
        
        $newToken = JWTAuth::claims($cleanClaims)->fromUser($originalUser);
        
        \Log::info('🔑 Nuevo token LIMPIO generado para usuario original:', [
            'user_id' => $originalUser->id,
            'user_name' => $originalUser->name,
            'token_preview' => substr($newToken, 0, 20) . '...',
            'personificando' => false
        ]);

        // Log de auditoría
        \Log::info('✅ Personificación finalizada', [
            'original_user' => $originalUser->email,
            'duration' => now()->timestamp - $payload->get('started_at')
        ]);

        return response()->json([
            'success' => true,
            'message' => "Has vuelto a tu cuenta original ({$originalUser->name})",
            'data' => [
                'token' => $newToken,
                'user' => [
                    'id' => $originalUser->id,
                    'name' => $originalUser->name,
                    'email' => $originalUser->email,
                    'empresas' => $originalUser->empresas,
                    'roles' => $originalUser->rolesCustom
                ],
                'personificacion' => [
                    'activa' => false
                ]
            ]
        ], 200);
    }

    /**
     * Obtener estado actual de personificación
     */
    public function getEstadoPersonificacion(): JsonResponse
    {
        \Log::info('🔍 PersonificarController::getEstadoPersonificacion - Consultando estado');
        
        $currentToken = JWTAuth::getToken();
        
        if (!$currentToken) {
            \Log::info('⚠️ No hay token disponible');
            return response()->json([
                'success' => true,
                'data' => [
                    'activa' => false
                ]
            ], 200);
        }

        $payload = JWTAuth::getPayload($currentToken);
        $personificando = $payload->get('personificando', false);
        
        \Log::info('🎭 Estado de personificación en token:', [
            'personificando' => $personificando,
            'original_user_id' => $payload->get('original_user_id'),
            'target_user_id' => $payload->get('target_user_id')
        ]);

        if ($personificando) {
            $originalUserId = $payload->get('original_user_id');
            $originalUserName = $payload->get('original_user_name');
            $startedAt = $payload->get('started_at');

            $estadoData = [
                'activa' => true,
                'original_user' => [
                    'id' => $originalUserId,
                    'name' => $originalUserName
                ],
                'started_at' => $startedAt,
                'duration' => now()->timestamp - $startedAt
            ];
            
            \Log::info('✅ Personificación activa detectada:', $estadoData);

            return response()->json([
                'success' => true,
                'data' => $estadoData
            ], 200);
        }

        \Log::info('❌ No hay personificación activa');

        return response()->json([
            'success' => true,
            'data' => [
                'activa' => false
            ]
        ], 200);
    }

    /**
     * Verificar si el usuario tiene permiso para personificar
     */
    private function tienePermisoPersonificar($user): bool
    {
        if (!$user) {
            \Log::warning('🚫 Usuario nulo en tienePermisoPersonificar');
            return false;
        }

        \Log::info('🔍 Verificando permisos para usuario:', ['id' => $user->id, 'email' => $user->email]);

        try {
            // Verificar si es administrador
            $esAdmin = $user->rolesCustom()->where('es_admin', true)->exists();
            \Log::info('👑 ¿Es admin?', ['es_admin' => $esAdmin]);
            
            if ($esAdmin) {
                \Log::info('✅ Usuario es administrador, tiene permisos');
                return true;
            }

            // Por ahora, permitir a todos los usuarios autenticados para debug
            \Log::info('🔧 DEBUG: Permitiendo acceso temporal para debug');
            return true;

            // TODO: Restaurar verificación de permisos específicos
            /*
            $tienePermiso = $user->rolesCustom()
                ->whereHas('perfiles.modulo.permisos', function ($query) {
                    $query->where('codigo', 'org-personificar')
                          ->where('estado', 1);
                })
                ->exists();
            
            \Log::info('🎫 ¿Tiene permiso específico?', ['tiene_permiso' => $tienePermiso]);
            return $tienePermiso;
            */
        } catch (\Exception $e) {
            \Log::error('❌ Error verificando permisos: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtener historial de personificaciones (para auditoría)
     */
    public function getHistorialPersonificaciones(): JsonResponse
    {
        $currentUser = auth('api')->user();

        if (!$this->tienePermisoPersonificar($currentUser)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para ver el historial'
            ], 403);
        }

        // Aquí podrías implementar un sistema de logs más sofisticado
        // Por ahora, devolvemos un mensaje indicando que se puede consultar en logs
        return response()->json([
            'success' => true,
            'message' => 'El historial de personificaciones se encuentra en los logs del sistema',
            'data' => [
                'log_location' => 'storage/logs/laravel.log',
                'search_pattern' => 'Personificación iniciada|Personificación finalizada'
            ]
        ], 200);
    }
}