<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\AllowedDomain;
use App\Services\SidebarService;
use App\Services\Tenant\UserGrupSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Laravel\Socialite\Facades\Socialite;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Str;

class MicrosoftAuthController extends Controller
{
    protected $sidebarService;
    protected $userGrupSyncService;

    public function __construct(SidebarService $sidebarService, UserGrupSyncService $userGrupSyncService)
    {
        $this->sidebarService = $sidebarService;
        $this->userGrupSyncService = $userGrupSyncService;
    }

    /**
     * Obtener permisos de un usuario basados en sus roles y perfiles
     * (Copiado del AuthController para mantener consistencia)
     */
    private function getUserPermissions($user)
    {
        $permisosCodigos = collect();
        
        foreach ($user->rolesCustom as $rol) {
            foreach ($rol->perfiles as $perfil) {
                // Obtener el prefijo del código desde los permisos existentes del módulo
                $modulo = $perfil->modulo;
                $codigoModulo = 'mod';
                
                if ($modulo) {
                    // Buscar un permiso del módulo para extraer el prefijo
                    $primerPermiso = \App\Models\Permiso::where('id_modulo', $modulo->id)
                        ->where('tipo', 'boton')
                        ->orderBy('orden')
                        ->first();
                    
                    if ($primerPermiso) {
                        // Extraer el prefijo del código (ej: "org-emp-crear" -> "org-emp")
                        $partes = explode('-', $primerPermiso->codigo);
                        if (count($partes) >= 3) {
                            array_pop($partes); // Quitar la última parte (crear, editar, etc.)
                            $codigoModulo = implode('-', $partes);
                        }
                    } else {
                        // Fallback: convertir el código del módulo
                        $codigoModulo = strtolower(str_replace('_', '-', $modulo->codigo));
                    }
                }
                
                // Agregar permisos CRUD del perfil
                if ($perfil->puede_crear) {
                    $permisosCodigos->push("{$codigoModulo}-crear");
                }
                if ($perfil->puede_leer) {
                    $permisosCodigos->push("{$codigoModulo}-ver");
                }
                if ($perfil->puede_editar) {
                    $permisosCodigos->push("{$codigoModulo}-editar");
                }
                if ($perfil->puede_eliminar) {
                    $permisosCodigos->push("{$codigoModulo}-eliminar");
                }
                
                // Agregar permisos extra del perfil
                foreach ($perfil->permisos as $permiso) {
                    if ($permiso->estado) {
                        $permisosCodigos->push($permiso->codigo);
                    }
                }
            }
        }
        
        // Eliminar duplicados
        return $permisosCodigos->unique()->values()->toArray();
    }

    /**
     * Crear respuesta completa con token, usuario, permisos y sidebar
     * (Similar al respondWithToken del AuthController)
     */
    private function createAuthResponse($user, $token)
    {
        // Cargar relaciones necesarias
        $user->load(['rolesCustom.perfiles.permisos', 'empresas', 'sucursal', 'sede']);
        
        // Obtener todos los permisos del usuario
        $permisosUnicos = $this->getUserPermissions($user);
        
        // Obtener módulos del sidebar con permisos básicos
        $sidebar = $this->sidebarService->getSidebarModules($user);
        $tenantSync = $this->userGrupSyncService->syncFromAzureOnLogin($user, false);
        
        \Log::info('🔐 Login con Microsoft exitoso:', [
            'user' => $user->name,
            'roles' => $user->rolesCustom->pluck('nombre')->toArray(),
            'permisos_count' => count($permisosUnicos),
            'modulos_sidebar' => count($sidebar),
            'users_grups_count' => count($tenantSync['users_grups']),
        ]);
        
        return [
            'message' => 'Login exitoso con Microsoft',
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'auth_type' => $user->auth_type,
                'tenant_id' => $user->tenant_id,
                'tipo_identificacion' => $user->tipo_identificacion,
                'numero_identificacion' => $user->numero_identificacion,
                'direccion' => $user->direccion,
                'telefono' => $user->telefono,
                'estado' => $user->estado,
                'cargo' => $user->cargo,
                'roles' => $user->rolesCustom->pluck('nombre'),
                'empresas' => $user->empresas,
                'sucursal' => $user->sucursal,
                'sede' => $user->sede,
                'permissions' => $permisosUnicos,
                'users_grups' => $tenantSync['users_grups'],
            ],
            'sidebar' => $sidebar,
            'tenant_sync' => [
                'synced' => $tenantSync['synced'],
                'error'  => $tenantSync['error'],
            ],
        ];
    }

    /**
     * Redirigir a Microsoft para autenticación
     */
    public function redirectToMicrosoft(Request $request): JsonResponse
    {
        try {
            // Determinar el redirect_uri según el origin del frontend que está pidiendo auth.
            // Esto permite que el mismo backend sirva a múltiples frontends
            // (producción jade.medilaser.com.co y tunnel de Cloudflare).
            $origin = $request->header('Origin') ?? $request->header('Referer') ?? '';
            $redirectUri = config('services.microsoft.redirect'); // Default: producción

            // Si el request viene del tunnel de Cloudflare, usar su callback
            if (str_contains($origin, 'trycloudflare.com')) {
                $tunnelHost = parse_url($origin, PHP_URL_HOST);
                $redirectUri = "https://{$tunnelHost}/auth/microsoft/callback";
            } elseif (str_contains($origin, 'localhost:4200')) {
                $redirectUri = 'http://localhost:4200/auth/microsoft/callback';
            }

            $authUrl = Socialite::driver('microsoft')
                ->stateless()
                ->redirectUrl($redirectUri)
                ->redirect()
                ->getTargetUrl();

            return response()->json([
                'auth_url' => $authUrl
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al generar URL de autenticación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Callback de Microsoft
     */
    public function handleMicrosoftCallback(Request $request): JsonResponse
    {
        try {
            // Obtener usuario de Microsoft
            $microsoftUser = Socialite::driver('microsoft')
                ->stateless()
                ->user();

            // Verificar si el dominio está permitido
            $email = $microsoftUser->getEmail();
            $domain = '@' . substr(strrchr($email, "@"), 1);

            $allowedDomain = AllowedDomain::getByEmail($email);

            if (!$allowedDomain) {
                return response()->json([
                    'message' => 'Dominio no autorizado',
                    'error' => "El dominio {$domain} no tiene acceso a esta aplicación"
                ], 403);
            }

            // Buscar usuario existente (NO crear automáticamente)
            $user = User::where('microsoft_id', $microsoftUser->getId())
                ->orWhere('email', $email)
                ->first();

            if (!$user) {
                \Log::warning('🚫 Intento de login con Microsoft de usuario no registrado:', [
                    'email' => $email,
                    'microsoft_id' => $microsoftUser->getId(),
                    'name' => $microsoftUser->getName(),
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent()
                ]);
                
                return response()->json([
                    'message' => 'Usuario no autorizado',
                    'error' => 'Tu cuenta debe ser creada por un administrador antes de poder acceder al sistema. Contacta al administrador para solicitar acceso.'
                ], 403);
            }

            // Actualizar información del usuario existente
            $user->update([
                'microsoft_id' => $microsoftUser->getId(),
                'tenant_id' => $allowedDomain->tenant_id,
                'avatar' => $microsoftUser->getAvatar(),
                'auth_type' => 'microsoft'
            ]);

            // Verificar que el usuario esté activo
            if (!$user->estaActivo()) {
                \Log::warning('🚫 Intento de login con Microsoft de usuario inactivo:', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'ip' => request()->ip()
                ]);
                
                return response()->json([
                    'message' => 'Cuenta inactiva',
                    'error' => 'Tu cuenta ha sido desactivada. Contacta al administrador para más información.'
                ], 403);
            }

            // Generar token JWT
            $token = JWTAuth::fromUser($user);

            // Crear respuesta completa con permisos y sidebar
            $response = $this->createAuthResponse($user, $token);

            return response()->json($response, 200);

        } catch (\Exception $e) {
            \Log::error('❌ Error en autenticación con Microsoft:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Error en autenticación con Microsoft',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verificar configuración de Microsoft
     */
    public function checkConfiguration(): JsonResponse
    {
        $config = [
            'client_id' => config('services.microsoft.client_id'),
            'redirect_uri' => config('services.microsoft.redirect'),
            'tenant' => config('services.microsoft.tenant'),
        ];

        $isConfigured = !empty($config['client_id']) && 
                       $config['client_id'] !== 'your-client-id-here' &&
                       !empty($config['redirect_uri']);

        $allowedDomains = AllowedDomain::activos()->get(['domain', 'tenant_name', 'id_empresa']);

        return response()->json([
            'configured' => $isConfigured,
            'config' => [
                'client_id' => $isConfigured ? substr($config['client_id'], 0, 8) . '...' : 'No configurado',
                'redirect_uri' => $config['redirect_uri'],
                'tenant' => $config['tenant'],
                'multi_tenant' => $config['tenant'] === 'common'
            ],
            'allowed_domains' => $allowedDomains,
            'total_domains' => $allowedDomains->count(),
            'message' => $isConfigured 
                ? 'Microsoft OAuth está configurado correctamente' 
                : 'Microsoft OAuth no está configurado. Actualiza las credenciales en .env'
        ], 200);
    }

    /**
     * Verificar si un email está permitido
     */
    public function checkEmail(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $email = $request->email;
        $domain = '@' . substr(strrchr($email, "@"), 1);
        
        $allowedDomain = AllowedDomain::getByEmail($email);
        $isAllowed = AllowedDomain::isEmailAllowed($email);

        return response()->json([
            'email' => $email,
            'domain' => $domain,
            'is_allowed' => $isAllowed,
            'domain_info' => $allowedDomain ? [
                'tenant_name' => $allowedDomain->tenant_name,
                'tenant_id' => $allowedDomain->tenant_id,
                'empresa' => $allowedDomain->empresa ? $allowedDomain->empresa->nombre : null
            ] : null,
            'message' => $isAllowed 
                ? "El dominio {$domain} está permitido" 
                : "El dominio {$domain} NO está permitido"
        ], $isAllowed ? 200 : 403);
    }

    /**
     * Probar conexión con Microsoft (sin autenticar)
     */
    public function testConnection(): JsonResponse
    {
        try {
            // Intentar generar URL de autenticación
            $authUrl = Socialite::driver('microsoft')
                ->stateless()
                ->redirect()
                ->getTargetUrl();

            return response()->json([
                'status' => 'success',
                'message' => 'Conexión con Microsoft configurada correctamente',
                'auth_url_preview' => substr($authUrl, 0, 100) . '...',
                'can_authenticate' => true
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error en la configuración de Microsoft',
                'error' => $e->getMessage(),
                'can_authenticate' => false,
                'help' => 'Verifica que MICROSOFT_CLIENT_ID, MICROSOFT_CLIENT_SECRET y MICROSOFT_REDIRECT_URI estén configurados en .env'
            ], 500);
        }
    }

    /**
     * Generar URL de consentimiento de administrador
     */
    public function getAdminConsentUrl(): JsonResponse
    {
        try {
            $clientId = config('services.microsoft.client_id');
            $redirectUri = config('services.microsoft.redirect');
            $tenant = config('services.microsoft.tenant', 'common');

            if (empty($clientId) || $clientId === 'your-client-id-here') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Microsoft OAuth no está configurado',
                    'help' => 'Configura MICROSOFT_CLIENT_ID en .env'
                ], 400);
            }

            // URL de consentimiento de administrador
            $adminConsentUrl = "https://login.microsoftonline.com/{$tenant}/adminconsent" .
                "?client_id={$clientId}" .
                "&redirect_uri=" . urlencode($redirectUri);

            return response()->json([
                'status' => 'success',
                'message' => 'URL de consentimiento de administrador generada',
                'admin_consent_url' => $adminConsentUrl,
                'instructions' => [
                    '1. Copia la URL de consentimiento',
                    '2. Ábrela en un navegador',
                    '3. Inicia sesión con una cuenta de Administrador Global del tenant',
                    '4. Revisa y acepta los permisos solicitados',
                    '5. Repite el proceso para cada tenant que quieras autorizar'
                ],
                'tenant_config' => $tenant,
                'is_multi_tenant' => $tenant === 'common',
                'help' => $tenant === 'common' 
                    ? 'Configuración multi-tenant: Debes otorgar consentimiento en cada tenant por separado'
                    : 'Configuración single-tenant: Solo necesitas otorgar consentimiento una vez'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al generar URL de consentimiento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Login con código de autorización (para SPA)
     */
    public function loginWithCode(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string'
        ]);

        try {
            // Determinar redirect_uri según el origin del frontend
            $origin = $request->header('Origin') ?? $request->header('Referer') ?? '';
            $driver = Socialite::driver('microsoft')->stateless();

            if (str_contains($origin, 'trycloudflare.com')) {
                $tunnelHost = parse_url($origin, PHP_URL_HOST);
                $driver = $driver->redirectUrl("https://{$tunnelHost}/auth/microsoft/callback");
            } elseif (str_contains($origin, 'localhost:4200')) {
                $driver = $driver->redirectUrl('http://localhost:4200/auth/microsoft/callback');
            }

            // Obtener usuario usando el código
            $microsoftUser = $driver->user();

            $email = $microsoftUser->getEmail();
            $domain = '@' . substr(strrchr($email, "@"), 1);

            // Verificar dominio permitido
            $allowedDomain = AllowedDomain::getByEmail($email);

            if (!$allowedDomain) {
                return response()->json([
                    'message' => 'Dominio no autorizado',
                    'error' => "El dominio {$domain} no tiene acceso a esta aplicación"
                ], 403);
            }

            // Buscar usuario existente (NO crear automáticamente)
            $user = User::where('microsoft_id', $microsoftUser->getId())
                ->orWhere('email', $email)
                ->first();

            if (!$user) {
                \Log::warning('🚫 Intento de login con Microsoft de usuario no registrado (código):', [
                    'email' => $email,
                    'microsoft_id' => $microsoftUser->getId(),
                    'name' => $microsoftUser->getName(),
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent()
                ]);
                
                return response()->json([
                    'message' => 'Usuario no autorizado',
                    'error' => 'Tu cuenta debe ser creada por un administrador antes de poder acceder al sistema. Contacta al administrador para solicitar acceso.'
                ], 403);
            }

            // Actualizar información del usuario existente
            $user->update([
                'microsoft_id' => $microsoftUser->getId(),
                'tenant_id' => $allowedDomain->tenant_id,
                'avatar' => $microsoftUser->getAvatar(),
                'auth_type' => 'microsoft'
            ]);

            // Verificar que el usuario esté activo
            if (!$user->estaActivo()) {
                \Log::warning('🚫 Intento de login con Microsoft de usuario inactivo:', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'ip' => request()->ip()
                ]);
                
                return response()->json([
                    'message' => 'Cuenta inactiva',
                    'error' => 'Tu cuenta ha sido desactivada. Contacta al administrador para más información.'
                ], 403);
            }

            // Generar token JWT
            $token = JWTAuth::fromUser($user);

            // Crear respuesta completa con permisos y sidebar
            $response = $this->createAuthResponse($user, $token);

            return response()->json($response, 200);

        } catch (\Exception $e) {
            \Log::error('❌ Error en autenticación con Microsoft (código):', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'message' => 'Error en autenticación con Microsoft',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
