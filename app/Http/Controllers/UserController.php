<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    /**
     * Display a listing of users.
     */
    public function index()
    {
        $users = User::with(['rolesCustom', 'empresas', 'sucursal', 'sede'])->get()->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'cargo' => $user->cargo,
                'email' => $user->email,
                'tipo_identificacion' => $user->tipo_identificacion,
                'numero_identificacion' => $user->numero_identificacion,
                'direccion' => $user->direccion,
                'telefono' => $user->telefono,
                'estado' => $user->estado,
                'created_at' => $user->created_at,
                'roles' => $user->rolesCustom,
                'empresas' => $user->empresas,
                'sucursal' => $user->sucursal,
                'sede' => $user->sede,
                'permissions' => []
            ];
        });

        return response()->json($users);
    }

    /**
     * Store a newly created user.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'cargo' => 'required|string|max:255',
            'email' => 'required|email|unique:users|max:255',
            'password' => 'required|string|min:6|confirmed',
            'tipo_identificacion' => 'nullable|string|max:10',
            'numero_identificacion' => 'nullable|string|max:50',
            'direccion' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'roles' => 'sometimes|array',
            'roles.*' => 'exists:seg_roles_custom,id',
            'empresasAsignadas' => 'required|array|min:1',
            'empresasAsignadas.*.empresa_id' => 'required|exists:ent_empresas,id',
            'empresasAsignadas.*.sucursal_id' => 'nullable|exists:config_ubi_sucursales,id',
            'empresasAsignadas.*.sede_id' => 'nullable|exists:config_ubi_sede,id',
            'empresasAsignadas.*.recursivo' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'cargo' => $request->cargo,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'tipo_identificacion' => $request->tipo_identificacion,
            'numero_identificacion' => $request->numero_identificacion,
            'direccion' => $request->direccion,
            'telefono' => $request->telefono,
        ]);

        // Asignar roles (usando la tabla personalizada)
        if ($request->has('roles')) {
            $user->rolesCustom()->sync($request->roles);
        }

        // Asignar empresas múltiples con sucursales y sedes
        if ($request->has('empresasAsignadas')) {
            $empresasData = [];
            foreach ($request->empresasAsignadas as $asignacion) {
                $empresasData[$asignacion['empresa_id']] = [
                    'id_sucursal' => $asignacion['sucursal_id'],
                    'id_sede' => $asignacion['sede_id'],
                    'recursivo' => $asignacion['recursivo']
                ];
            }
            $user->empresas()->sync($empresasData);
        }

        // Recargar relaciones
        $user->load('rolesCustom', 'empresas', 'sucursal', 'sede');

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'tipo_identificacion' => $user->tipo_identificacion,
            'numero_identificacion' => $user->numero_identificacion,
            'direccion' => $user->direccion,
            'telefono' => $user->telefono,
            'created_at' => $user->created_at,
            'roles' => $user->rolesCustom,
            'empresas' => $user->empresas,
            'sucursal' => $user->sucursal,
            'sede' => $user->sede,
            'permissions' => []
        ], 201);
    }

    /**
     * Display the specified user.
     */
    public function show($id)
    {
        $user = User::with('roles')->findOrFail($id);

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'cargo' => $user->cargo,
            'email' => $user->email,
            'tipo_identificacion' => $user->tipo_identificacion,
            'numero_identificacion' => $user->numero_identificacion,
            'direccion' => $user->direccion,
            'telefono' => $user->telefono,
            'estado' => $user->estado,
            'created_at' => $user->created_at,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllPermissions()->pluck('name')
        ]);
    }

    /**
     * Update the specified user.
     */
    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'cargo' => 'sometimes|required|string|max:255',
            'email' => 'sometimes|required|email|max:255|unique:users,email,' . $id,
            'password' => 'sometimes|nullable|string|min:6|confirmed',
            'tipo_identificacion' => 'sometimes|nullable|string|max:10',
            'numero_identificacion' => 'sometimes|nullable|string|max:50',
            'direccion' => 'sometimes|nullable|string|max:255',
            'telefono' => 'sometimes|nullable|string|max:20',
            'roles' => 'sometimes|array',
            'roles.*' => 'exists:seg_roles_custom,id',
            'empresasAsignadas' => 'sometimes|array|min:1',
            'empresasAsignadas.*.empresa_id' => 'required|exists:ent_empresas,id',
            'empresasAsignadas.*.sucursal_id' => 'nullable|exists:config_ubi_sucursales,id',
            'empresasAsignadas.*.sede_id' => 'nullable|exists:config_ubi_sede,id',
            'empresasAsignadas.*.recursivo' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        // Actualizar campos básicos
        if ($request->has('name')) {
            $user->name = $request->name;
        }

        if ($request->has('cargo')) {
            $user->cargo = $request->cargo;
        }

        if ($request->has('email')) {
            $user->email = $request->email;
        }

        if ($request->has('password') && $request->password) {
            $user->password = Hash::make($request->password);
        }

        // Actualizar campos adicionales
        if ($request->has('tipo_identificacion')) {
            $user->tipo_identificacion = $request->tipo_identificacion;
        }

        if ($request->has('numero_identificacion')) {
            $user->numero_identificacion = $request->numero_identificacion;
        }

        if ($request->has('direccion')) {
            $user->direccion = $request->direccion;
        }

        if ($request->has('telefono')) {
            $user->telefono = $request->telefono;
        }

        $user->save();

        // Sincronizar roles (usando la tabla personalizada)
        if ($request->has('roles')) {
            $user->rolesCustom()->sync($request->roles);
        }

        // Sincronizar empresas múltiples con sucursales y sedes
        if ($request->has('empresasAsignadas')) {
            $empresasData = [];
            foreach ($request->empresasAsignadas as $asignacion) {
                $empresasData[$asignacion['empresa_id']] = [
                    'id_sucursal' => $asignacion['sucursal_id'],
                    'id_sede' => $asignacion['sede_id'],
                    'recursivo' => $asignacion['recursivo']
                ];
            }
            $user->empresas()->sync($empresasData);
        }

        // Recargar relaciones
        $user->load('rolesCustom', 'empresas', 'sucursal', 'sede');

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'tipo_identificacion' => $user->tipo_identificacion,
            'numero_identificacion' => $user->numero_identificacion,
            'direccion' => $user->direccion,
            'telefono' => $user->telefono,
            'created_at' => $user->created_at,
            'roles' => $user->rolesCustom,
            'empresas' => $user->empresas,
            'sucursal' => $user->sucursal,
            'sede' => $user->sede,
            'permissions' => []
        ]);
    }

    /**
     * Remove the specified user.
     */
    public function destroy($id)
    {
        $user = User::findOrFail($id);
        
        // Prevenir eliminar el propio usuario
        if ($user->id === auth()->id()) {
            return response()->json([
                'message' => 'No puedes eliminar tu propio usuario'
            ], 403);
        }

        $user->delete();

        return response()->json([
            'message' => 'Usuario eliminado exitosamente'
        ]);
    }

    /**
     * Cambiar estado del usuario (activar/inactivar)
     */
    public function cambiarEstado(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        // Prevenir inactivar el propio usuario
        if ($user->id === auth()->id()) {
            return response()->json([
                'message' => 'No puedes cambiar el estado de tu propio usuario'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'estado' => 'required|boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $estadoAnterior = $user->estado;
        $user->estado = $request->estado;
        $user->save();

        $accion = $request->estado ? 'activado' : 'inactivado';
        
        // Log de auditoría
        \Log::info("Usuario {$accion}", [
            'usuario_modificado' => $user->email,
            'modificado_por' => auth()->user()->email,
            'estado_anterior' => $estadoAnterior,
            'estado_nuevo' => $request->estado
        ]);

        return response()->json([
            'message' => "Usuario {$accion} exitosamente",
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'estado' => $user->estado
            ]
        ]);
    }

    /**
     * Obtener usuarios del tenant de Microsoft
     */
    public function obtenerUsuariosTenant(Request $request)
    {
        \Log::info('🔍 Iniciando obtenerUsuariosTenant');
        
        try {
            // Verificar permisos (solo usuarios autenticados por ahora)
            $user = auth()->user();
            if (!$user) {
                \Log::error('❌ Usuario no autenticado');
                return response()->json([
                    'message' => 'No tienes permisos para sincronizar usuarios del tenant'
                ], 403);
            }

            \Log::info('✅ Usuario autenticado:', ['user' => $user->email]);

            // Obtener el tenant solicitado (medilaser o jersalud)
            $tenantType = $request->query('tenant', 'medilaser');
            
            // Obtener configuración de Microsoft según el tenant
            if ($tenantType === 'jersalud') {
                $clientId = env('MICROSOFT_JERSALUD_CLIENT_ID');
                $clientSecret = env('MICROSOFT_JERSALUD_CLIENT_SECRET');
                $tenantId = env('MICROSOFT_JERSALUD_TENANT_ID');
                $tenantName = 'Jersalud';
            } else {
                $clientId = config('services.microsoft.client_id');
                $clientSecret = config('services.microsoft.client_secret');
                $tenantId = config('services.microsoft.tenant');
                $tenantName = 'Medilaser';
            }

            \Log::info('🔧 Configuración Microsoft:', [
                'tenant_type' => $tenantType,
                'tenant_name' => $tenantName,
                'client_id' => $clientId ? 'Configurado' : 'No configurado',
                'client_secret' => $clientSecret ? 'Configurado' : 'No configurado',
                'tenant_id' => $tenantId
            ]);

            if (!$clientId || !$clientSecret) {
                \Log::error('❌ Microsoft Graph API no configurado');
                return response()->json([
                    'message' => 'Microsoft Graph API no está configurado correctamente'
                ], 500);
            }

            // Obtener token de aplicación para Microsoft Graph
            $tokenUrl = "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token";
            
            $tokenData = [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials'
            ];

            \Log::info('🔑 Solicitando token a Microsoft Graph');
            $tokenResponse = $this->makeHttpRequest($tokenUrl, 'POST', $tokenData);
            
            if (!$tokenResponse || !isset($tokenResponse['access_token'])) {
                \Log::error('❌ Error al obtener token:', ['response' => $tokenResponse]);
                return response()->json([
                    'message' => 'Error al obtener token de Microsoft Graph'
                ], 500);
            }

            \Log::info('✅ Token obtenido exitosamente');
            $accessToken = $tokenResponse['access_token'];

            // Obtener TODOS los usuarios del tenant usando paginación
            $allTenantUsers = [];
            $usersUrl = 'https://graph.microsoft.com/v1.0/users?$select=id,displayName,mail,userPrincipalName,jobTitle,department,accountEnabled&$top=999';
            
            $headers = [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ];

            $pageCount = 0;
            $maxPages = 50; // Límite de seguridad para evitar loops infinitos

            \Log::info('👥 Iniciando carga paginada de usuarios del tenant');

            // Loop para obtener todas las páginas de usuarios
            while ($usersUrl && $pageCount < $maxPages) {
                $pageCount++;
                \Log::info("📄 Cargando página {$pageCount} de usuarios", ['url' => $usersUrl]);

                $usersResponse = $this->makeHttpRequest($usersUrl, 'GET', null, $headers);

                if (!$usersResponse || !isset($usersResponse['value'])) {
                    \Log::error('❌ Error al obtener usuarios en página ' . $pageCount, ['response' => $usersResponse]);
                    break;
                }

                $pageUsers = $usersResponse['value'];
                $allTenantUsers = array_merge($allTenantUsers, $pageUsers);
                
                \Log::info("✅ Página {$pageCount} cargada", [
                    'usuarios_en_pagina' => count($pageUsers),
                    'total_acumulado' => count($allTenantUsers)
                ]);

                // Verificar si hay más páginas
                if (isset($usersResponse['@odata.nextLink'])) {
                    $usersUrl = $usersResponse['@odata.nextLink'];
                    \Log::info('➡️ Hay más páginas, continuando...');
                } else {
                    $usersUrl = null;
                    \Log::info('🏁 No hay más páginas, carga completa');
                }
            }

            $tenantUsers = $allTenantUsers;
            \Log::info('📊 Total de usuarios obtenidos del tenant:', [
                'count' => count($tenantUsers),
                'paginas_procesadas' => $pageCount
            ]);
            
            $existingUsers = User::pluck('email')->toArray();
            \Log::info('📊 Usuarios existentes en la app:', ['count' => count($existingUsers)]);

            // Procesar usuarios del tenant
            $usuariosDisponibles = [];
            foreach ($tenantUsers as $tenantUser) {
                $email = $tenantUser['mail'] ?? $tenantUser['userPrincipalName'] ?? null;
                
                if ($email && !in_array($email, $existingUsers)) {
                    $usuariosDisponibles[] = [
                        'microsoft_id' => $tenantUser['id'],
                        'name' => $tenantUser['displayName'],
                        'email' => $email,
                        'job_title' => $tenantUser['jobTitle'] ?? '',
                        'department' => $tenantUser['department'] ?? '',
                        'account_enabled' => $tenantUser['accountEnabled'] ?? true,
                        'exists_in_app' => false
                    ];
                } else {
                    \Log::info('🚫 Usuario ya existe en la app:', ['email' => $email]);
                }
            }

            \Log::info('✅ Procesamiento completado:', [
                'total_tenant_users' => count($tenantUsers),
                'usuarios_disponibles' => count($usuariosDisponibles)
            ]);

            return response()->json([
                'message' => 'Usuarios del tenant obtenidos exitosamente',
                'total_tenant_users' => count($tenantUsers),
                'available_users' => $usuariosDisponibles,
                'total_available' => count($usuariosDisponibles),
                'tenant_id' => $tenantId,
                'tenant_name' => $tenantName,
                'tenant_type' => $tenantType
            ]);

        } catch (\Exception $e) {
            \Log::error('💥 Error en obtenerUsuariosTenant:', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Error interno al obtener usuarios del tenant',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Sincronizar usuarios seleccionados del tenant
     */
    public function sincronizarUsuariosTenant(Request $request)
    {
        try {
            // Verificar permisos (solo usuarios autenticados por ahora)
            $user = auth()->user();
            if (!$user) {
                return response()->json([
                    'message' => 'No tienes permisos para sincronizar usuarios del tenant'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'usuarios' => 'required|array|min:1',
                'usuarios.*.microsoft_id' => 'required|string',
                'usuarios.*.name' => 'required|string|max:255',
                'usuarios.*.email' => 'required|email|unique:users,email',
                'usuarios.*.job_title' => 'nullable|string|max:255',
                'usuarios.*.department' => 'nullable|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $usuariosCreados = [];
            $errores = [];

            foreach ($request->usuarios as $userData) {
                try {
                    // Generar una contraseña aleatoria (no será usada ya que usan Microsoft Auth)
                    $randomPassword = \Str::random(16);
                    
                    $newUser = User::create([
                        'name' => $userData['name'],
                        'email' => $userData['email'],
                        'password' => Hash::make($randomPassword),
                        'microsoft_id' => $userData['microsoft_id'],
                        'auth_type' => 'microsoft',
                        'cargo' => $userData['job_title'] ?? 'Usuario',
                        'estado' => true,
                        'email_verified_at' => now()
                    ]);

                    $usuariosCreados[] = [
                        'id' => $newUser->id,
                        'name' => $newUser->name,
                        'email' => $newUser->email,
                        'cargo' => $newUser->cargo
                    ];

                } catch (\Exception $e) {
                    $errores[] = [
                        'email' => $userData['email'],
                        'error' => $e->getMessage()
                    ];
                }
            }

            // Log de auditoría
            \Log::info('Sincronización de usuarios del tenant:', [
                'sincronizado_por' => auth()->user()->email,
                'usuarios_creados' => count($usuariosCreados),
                'errores' => count($errores),
                'usuarios' => array_column($usuariosCreados, 'email')
            ]);

            return response()->json([
                'message' => 'Sincronización completada',
                'usuarios_creados' => $usuariosCreados,
                'total_creados' => count($usuariosCreados),
                'errores' => $errores,
                'total_errores' => count($errores)
            ]);

        } catch (\Exception $e) {
            \Log::error('Error en sincronización de usuarios del tenant:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Error interno en la sincronización',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Realizar petición HTTP
     */
    private function makeHttpRequest($url, $method = 'GET', $data = null, $headers = [])
    {
        \Log::info('🌐 Realizando petición HTTP:', [
            'url' => $url,
            'method' => $method,
            'has_data' => !is_null($data),
            'headers_count' => count($headers)
        ]);

        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_VERBOSE => true
        ]);

        if ($data && $method === 'POST') {
            if (is_array($data)) {
                $postData = http_build_query($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
                \Log::info('📤 Datos POST:', ['data' => $postData]);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                \Log::info('📤 Datos POST (raw):', ['data' => $data]);
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        \Log::info('📥 Respuesta HTTP:', [
            'http_code' => $httpCode,
            'curl_error' => $curlError,
            'response_length' => strlen($response),
            'response_preview' => substr($response, 0, 200)
        ]);

        if ($curlError) {
            \Log::error('❌ Error cURL:', ['error' => $curlError]);
            return null;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $decodedResponse = json_decode($response, true);
            \Log::info('✅ Respuesta decodificada exitosamente');
            return $decodedResponse;
        } else {
            \Log::error('❌ Código HTTP de error:', [
                'code' => $httpCode,
                'response' => $response
            ]);
        }

        return null;
    }

    /**
     * Verificar si un email ya existe
     */
    public function checkEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Email inválido',
                'errors' => $validator->errors()
            ], 422);
        }

        $exists = User::where('email', $request->email)->exists();

        return response()->json([
            'exists' => $exists,
            'message' => $exists ? 'El email ya está registrado' : 'El email está disponible'
        ]);
    }
}
