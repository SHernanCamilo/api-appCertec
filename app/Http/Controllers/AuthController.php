<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SidebarService;
use App\Services\Tenant\UserGrupSyncService;
use Tymon\JWTAuth\JWTGuard;
use Validator;


class AuthController extends Controller
{
    protected $sidebarService;
    protected $userGrupSyncService;

    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct(SidebarService $sidebarService, UserGrupSyncService $userGrupSyncService)
    {
        $this->middleware('auth:api', ['except' => ['login']]);
        $this->sidebarService = $sidebarService;
        $this->userGrupSyncService = $userGrupSyncService;
    }

    /**
     * Obtener permisos de un usuario basados en sus roles y perfiles
     */
    private function getUserPermissions($user): array
        {
            $user->loadMissing(['rolesCustom.perfiles.permisos', 'rolesCustom.perfiles.modulo']);

            // Recopilar todos los id_modulo relevantes
            $moduloIds = $user->rolesCustom
                ->flatMap->perfiles
                ->pluck('id_modulo')
                ->filter()
                ->unique()
                ->values();

            // Una sola query para obtener el primer permiso tipo 'boton' por módulo
            $prefijosModulo = \App\Models\Permiso::whereIn('id_modulo', $moduloIds)
                ->where('tipo', 'boton')
                ->orderBy('orden')
                ->get()
                ->groupBy('id_modulo')
                ->map(function ($permisos) {
                    $codigo = $permisos->first()->codigo;
                    $partes = explode('-', $codigo);
                    if (count($partes) >= 3) {
                        array_pop($partes);
                        return implode('-', $partes);
                    }
                    return $codigo;
                });

            $permisosCodigos = collect();

            foreach ($user->rolesCustom as $rol) {
                foreach ($rol->perfiles as $perfil) {
                    $modulo = $perfil->modulo;
                    $codigoModulo = $modulo
                        ? ($prefijosModulo[$modulo->id]
                            ?? strtolower(str_replace('_', '-', $modulo->codigo)))
                        : 'mod';

                    if ($perfil->puede_crear)    $permisosCodigos->push("{$codigoModulo}-crear");
                    if ($perfil->puede_leer)     $permisosCodigos->push("{$codigoModulo}-ver");
                    if ($perfil->puede_editar)   $permisosCodigos->push("{$codigoModulo}-editar");
                    if ($perfil->puede_eliminar) $permisosCodigos->push("{$codigoModulo}-eliminar");

                    foreach ($perfil->permisos as $permiso) {
                        if ($permiso->estado) {
                            $permisosCodigos->push($permiso->codigo);
                        }
                    }
                }
            }

            return $permisosCodigos->unique()->values()->toArray();
        }


    /**
     * Register a User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function register() {
        return response()->json([
            'success' => false,
            'message' => 'El registro público está deshabilitado. Un administrador debe crear la cuenta.',
        ], 403);
    }


    /**
     * Get a JWT via given credentials.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function login()
    {
        $credentials = request(['email', 'password']);

        if (! $token = auth('api')->attempt($credentials)) {
            return response()->json([
                'error' => 'Credenciales incorrectas',
                'message' => 'El email o la contraseña son incorrectos'
            ], 401);
        }

        // Verificar que el usuario esté activo
        $user = auth('api')->user();
        if (!$user instanceof User || !$user->estaActivo()) {
            // Invalidar el token recién creado
            auth('api')->logout();
            
            Log::warning('🚫 Intento de login de usuario inactivo:', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);
            
            return response()->json([
                'error' => 'Cuenta inactiva',
                'message' => 'Tu cuenta ha sido desactivada. Contacta al administrador para más información.'
            ], 403);
        }

        Log::info('✅ Login exitoso de usuario activo:', [
            'user_id' => $user->id,
            'email' => $user->email,
            'ip' => request()->ip()
        ]);

        return $this->respondWithToken($token);
    }

    /**
     * Get the authenticated User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function me()
        {
            $user = auth('api')->user();

            if (!$user instanceof User || !$user->estaActivo()) {
                auth('api')->logout();
                return response()->json([
                    'error'   => 'Cuenta inactiva',
                    'message' => 'Tu cuenta ha sido desactivada. Por favor, inicia sesión nuevamente.'
                ], 403);
            }

            $user->load(['rolesCustom.perfiles.permisos', 'empresas', 'sucursal', 'sede']);
            $permisosUnicos = $this->getUserPermissions($user);

            return response()->json([
                'id'                    => $user->id,
                'name'                  => $user->name,
                'email'                 => $user->email,
                'tipo_identificacion'   => $user->tipo_identificacion,
                'numero_identificacion' => $user->numero_identificacion,
                'direccion'             => $user->direccion,
                'telefono'              => $user->telefono,
                'estado'                => $user->estado,
                'cargo'                 => $user->cargo,
                'roles'                 => $user->rolesCustom->pluck('nombre'),
                'empresas'              => $user->empresas,
                'sucursal'              => $user->sucursal,
                'sede'                  => $user->sede,
                'permissions'           => $permisosUnicos
            ]);
        }

    /**
     * Obtener módulos del sidebar para el usuario autenticado
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function sidebarModules()
        {
            $user = auth('api')->user();
            $sidebar = $this->sidebarService->getSidebarModules($user);

            return response()->json([
                'success' => true,
                'data'    => $sidebar,
            ]);
        }

    /**
     * Log the user out (Invalidate the token).
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout()
    {
        auth('api')->logout();

        return response()->json(['message' => 'Successfully logged out']);
    }

    /**
     * Refresh a token.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function refresh()
    {
        $guard = auth('api');
        if (!$guard instanceof JWTGuard) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $this->respondWithToken($guard->refresh(), false);
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithToken($token, bool $syncAzure = true)
        {
            $guard = auth('api');
            $user = $guard->user();

            if (!$guard instanceof JWTGuard || !$user instanceof User) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $user->load(['rolesCustom.perfiles.permisos', 'empresas', 'sucursal', 'sede']);

            $permisosUnicos = $this->getUserPermissions($user);
            $sidebar        = $this->sidebarService->getSidebarModules($user);
            $tenantSync     = $syncAzure
                ? $this->userGrupSyncService->syncFromAzureOnLogin($user, false)
                : [
                    'synced'      => false,
                    'users_grups' => $this->userGrupSyncService->currentGrups($user),
                    'error'       => null,
                ];

            // Obtener sedes según el rol del usuario (4-tier logic)
            $sedes = $this->getSedesForUser($user);

            Log::info('✅ Login exitoso', ['user_id' => $user->id, 'email' => $user->email]);

            return response()->json([
                'access_token' => $token,
                'token_type'   => 'bearer',
                'expires_in'   => $guard->factory()->getTTL() * 60,
                'user'         => [
                    'id'                    => $user->id,
                    'name'                  => $user->name,
                    'email'                 => $user->email,
                    'tipo_identificacion'   => $user->tipo_identificacion,
                    'numero_identificacion' => $user->numero_identificacion,
                    'direccion'             => $user->direccion,
                    'telefono'              => $user->telefono,
                    'estado'                => $user->estado,
                    'roles'                 => $user->rolesCustom->pluck('nombre'),
                    'empresas'              => $user->empresas,
                    'sucursal'              => $user->sucursal,
                    'sede'                  => $user->sede,
                    'sedes'                 => $sedes,
                    'permissions'           => $permisosUnicos,
                    'users_grups'           => $tenantSync['users_grups'],
                ],
                'sidebar' => $sidebar,
                'tenant_sync' => [
                    'synced' => $tenantSync['synced'],
                    'error'  => $tenantSync['error'],
                ],
            ]);
        }

    /**
     * Obtiene las sedes disponibles para el usuario según su rol
     */
    private function getSedesForUser($user)
    {
        try {
            // Verificar si es super_admin
            $isAdmin = false;
            if ($user->rolesCustom && $user->rolesCustom->isNotEmpty()) {
                $isAdmin = $user->rolesCustom->whereIn('nombre', ['super_admin'])->isNotEmpty() ||
                           $user->rolesCustom->whereIn('id', [1])->isNotEmpty();
            }

            if ($isAdmin) {
                // Super admin ve todas las sedes
                $sedes = DB::table('config_ubi_sede')
                    ->select('id', 'nombre')
                    ->orderBy('nombre')
                    ->get();
                return $sedes ? $sedes->toArray() : [];
            }

            // Obtener empresas del usuario
            $empresasDelUsuario = DB::table('seg_empresa_user')
                ->where('user_id', $user->id)
                ->pluck('empresa_id')
                ->toArray();

            if (empty($empresasDelUsuario)) {
                // Transversal ve todas las sedes
                $sedes = DB::table('config_ubi_sede')
                    ->select('id', 'nombre')
                    ->orderBy('nombre')
                    ->get();
                return $sedes ? $sedes->toArray() : [];
            }

            // Usuario con empresa asignada: obtener sedes de sus empresas
            // Las sedes están relacionadas con sucursales, que están relacionadas con empresas
            $sedes = DB::table('config_ubi_sede')
                ->join('config_ubi_sucursales', 'config_ubi_sede.id_Sucursal', '=', 'config_ubi_sucursales.id')
                ->whereIn('config_ubi_sucursales.id_empresa', $empresasDelUsuario)
                ->select('config_ubi_sede.id', 'config_ubi_sede.nombre')
                ->distinct()
                ->orderBy('config_ubi_sede.nombre')
                ->get();
                
            return $sedes ? $sedes->toArray() : [];
        } catch (\Exception $e) {
            // Si hay error, retornar array vacío
            return [];
        }
    }
}
