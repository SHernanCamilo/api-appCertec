<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SidebarService;
use Validator;


class AuthController extends Controller
{
    protected $sidebarService;

    /**
     * Create a new AuthController instance.
     *
     * @return void
     */
    public function __construct(SidebarService $sidebarService)
    {
        $this->middleware('auth:api', ['except' => ['login', 'register']]);
        $this->sidebarService = $sidebarService;
    }

    /**
     * Obtener permisos de un usuario basados en sus roles y perfiles
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
     * Register a User.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function register() {
        //$this->authorize('create', User::class); UserPolicy
        $validator = Validator::make(request()->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users|max:255',
            'password' => 'required|string|min:5|',
        ]);

        if($validator->fails()){
            return response()->json($validator->errors()->toJson(), 400);
        }

        $user = new User;
        $user->name = request()->name;
        $user->email = request()->email;
        $user->password = bcrypt(request()->password);
        $user->save();

        return response()->json($user, 201);
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
        if (!$user->estaActivo()) {
            // Invalidar el token recién creado
            auth('api')->logout();
            
            \Log::warning('🚫 Intento de login de usuario inactivo:', [
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

        \Log::info('✅ Login exitoso de usuario activo:', [
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
        
        // Verificar que el usuario siga activo
        if (!$user->estaActivo()) {
            \Log::warning('🚫 Usuario inactivo intentando acceder a /me:', [
                'user_id' => $user->id,
                'email' => $user->email,
                'ip' => request()->ip()
            ]);
            
            // Invalidar la sesión
            auth('api')->logout();
            
            return response()->json([
                'error' => 'Cuenta inactiva',
                'message' => 'Tu cuenta ha sido desactivada. Por favor, inicia sesión nuevamente.'
            ], 403);
        }
        
        $user->load(['rolesCustom.perfiles.permisos', 'empresas', 'sucursal', 'sede']);
        
        \Log::info('🔍 Usuario autenticado:', [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email
        ]);
        
        \Log::info('📋 Roles del usuario:', [
            'count' => $user->rolesCustom->count(),
            'roles' => $user->rolesCustom->pluck('nombre')->toArray()
        ]);
        
        // Obtener todos los permisos del usuario
        $permisosUnicos = $this->getUserPermissions($user);
        
        \Log::info('✅ Permisos finales:', [
            'total' => count($permisosUnicos),
            'permisos' => $permisosUnicos
        ]);
        
        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'tipo_identificacion' => $user->tipo_identificacion,
            'numero_identificacion' => $user->numero_identificacion,
            'direccion' => $user->direccion,
            'telefono' => $user->telefono,
            'estado' => $user->estado,
            'roles' => $user->rolesCustom->pluck('nombre'),
            'empresas' => $user->empresas,
            'sucursal' => $user->sucursal,
            'sede' => $user->sede,
            'permissions' => $permisosUnicos
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
        $user->load(['rolesCustom.perfiles.modulo', 'empresas']);
        
        $sidebar = $this->sidebarService->getSidebarModules($user);
        
        \Log::info('📋 Módulos del sidebar solicitados:', [
            'user' => $user->name,
            'modulos_count' => count($sidebar),
            'sidebar_data' => json_encode($sidebar)
        ]);
        
        return response()->json([
            'success' => true,
            'data' => $sidebar,
            'debug' => [
                'user' => $user->name,
                'roles' => $user->rolesCustom->pluck('nombre'),
                'perfiles_count' => $user->rolesCustom->flatMap->perfiles->count(),
                'empresas_count' => $user->empresas->count()
            ]
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
        return $this->respondWithToken(auth('api')->refresh());
    }

    /**
     * Get the token array structure.
     *
     * @param  string $token
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function respondWithToken($token)
    {
        $user = auth('api')->user();
        $user->load(['rolesCustom.perfiles.permisos', 'empresas', 'sucursal', 'sede']);
        
        // Obtener todos los permisos del usuario
        $permisosUnicos = $this->getUserPermissions($user);
        
        // Obtener módulos del sidebar con permisos básicos
        $sidebar = $this->sidebarService->getSidebarModules($user);
        
        \Log::info('🔐 Login exitoso:', [
            'user' => $user->name,
            'roles' => $user->rolesCustom->pluck('nombre')->toArray(),
            'permisos_count' => count($permisosUnicos),
            'modulos_sidebar' => count($sidebar)
        ]);
        
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'tipo_identificacion' => $user->tipo_identificacion,
                'numero_identificacion' => $user->numero_identificacion,
                'direccion' => $user->direccion,
                'telefono' => $user->telefono,
                'estado' => $user->estado,
                'roles' => $user->rolesCustom->pluck('nombre'),
                'empresas' => $user->empresas,
                'sucursal' => $user->sucursal,
                'sede' => $user->sede,
                'permissions' => $permisosUnicos
            ],
            'sidebar' => $sidebar
        ]);
    }
}
