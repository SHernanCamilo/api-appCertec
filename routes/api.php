<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Accounting\EmpleadoController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Ruta comentada - la aplicación usa JWT (auth:api), no Sanctum
// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

Route::group([
    'prefix' => 'auth'
], function ($router) {
    // Rutas públicas (sin autenticación)
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    
    // Rutas de Microsoft OAuth
    Route::get('/microsoft', [App\Http\Controllers\MicrosoftAuthController::class, 'redirectToMicrosoft']);
    Route::get('/microsoft/callback', [App\Http\Controllers\MicrosoftAuthController::class, 'handleMicrosoftCallback']);
    Route::post('/microsoft/token', [App\Http\Controllers\MicrosoftAuthController::class, 'loginWithCode']);

    
    // Rutas protegidas (requieren autenticación)
    Route::middleware(['auth:api', 'check.user.active'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('/refresh', [AuthController::class, 'refresh'])->name('refresh');
        Route::post('/me', [AuthController::class, 'me'])->name('me');
        Route::get('/sidebar-modules', [AuthController::class, 'sidebarModules'])->name('sidebar.modules');
        Route::get('/modulos', [App\Http\Controllers\ModuloController::class, 'getModulosUsuario'])->name('modulos.usuario');
        
        // Rutas de validación (requieren autenticación)
        Route::get('/microsoft/check-config', [App\Http\Controllers\MicrosoftAuthController::class, 'checkConfiguration']);
        Route::post('/microsoft/check-email', [App\Http\Controllers\MicrosoftAuthController::class, 'checkEmail']);
        Route::get('/microsoft/test-connection', [App\Http\Controllers\MicrosoftAuthController::class, 'testConnection']);
        Route::get('/microsoft/admin-consent-url', [App\Http\Controllers\MicrosoftAuthController::class, 'getAdminConsentUrl']);    
    });
});

// Rutas de gestión de usuarios (protegidas)
Route::middleware(['auth:api', 'check.user.active'])->group(function () {
    // Rutas de contexto de usuario
    Route::get('contexto', [App\Http\Controllers\ContextoController::class, 'obtenerContexto']);
    Route::post('contexto/cambiar', [App\Http\Controllers\ContextoController::class, 'cambiarContexto']);
    Route::get('contexto/empresas-disponibles', [App\Http\Controllers\ContextoController::class, 'obtenerEmpresasDisponibles']);
    Route::delete('contexto', [App\Http\Controllers\ContextoController::class, 'limpiarContexto']);
    
    // Rutas específicas de usuarios (deben ir antes del apiResource)
    Route::get('users/tenant/obtener', [App\Http\Controllers\UserController::class, 'obtenerUsuariosTenant']);
    Route::post('users/tenant/sincronizar', [App\Http\Controllers\UserController::class, 'sincronizarUsuariosTenant']);
    Route::post('users/check-email', [App\Http\Controllers\UserController::class, 'checkEmail']);
    Route::get('users-por-empresa/{empresaId}', [App\Http\Controllers\UserController::class, 'porEmpresa']);
    
    // 🔍 RUTAS DE DEBUG - REMOVER EN PRODUCCIÓN
    // Route::get('debug/usuarios', [App\Http\Controllers\DebugPermisosController::class, 'listarUsuariosConPermisos']);
    // Route::get('debug/usuario/{userId}', [App\Http\Controllers\DebugPermisosController::class, 'debugUsuario']);
    // Route::get('debug/comparar/{userId1}/{userId2}', [App\Http\Controllers\DebugPermisosController::class, 'compararUsuarios']);
    
    // Rutas para gestionar dominios permitidos
    Route::apiResource('allowed-domains', App\Http\Controllers\AllowedDomainController::class);
    Route::post('allowed-domains/check-email', [App\Http\Controllers\AllowedDomainController::class, 'checkEmail']);
    Route::patch('allowed-domains/{id}/toggle-status', [App\Http\Controllers\AllowedDomainController::class, 'toggleStatus']);
    
    Route::apiResource('users', App\Http\Controllers\UserController::class);
    Route::patch('users/{id}/cambiar-estado', [App\Http\Controllers\UserController::class, 'cambiarEstado']);

    // Rutas de empleados → routes/Accounting/EmpleadosRouter.php // Rutas de empleados → routes/Accounting/EmpleadosRouter.php

    // Rutas de empresas
    Route::get('empresas-activas', [App\Http\Controllers\EmpresaController::class, 'activas']);
    Route::get('empresas/{id}/logo-base64', [App\Http\Controllers\EmpresaController::class, 'logoBase64']);
    Route::apiResource('empresas', App\Http\Controllers\EmpresaController::class);
    Route::patch('empresas/{id}/toggle-estado', [App\Http\Controllers\EmpresaController::class, 'toggleEstado']);
    
    // Rutas de sucursales
    Route::apiResource('sucursales', App\Http\Controllers\SucursalController::class);
    Route::get('sucursales-por-empresa/{empresaId}', [App\Http\Controllers\SucursalController::class, 'porEmpresa']);
    
    // Rutas de sedes
    Route::apiResource('sedes', App\Http\Controllers\SedeController::class);
    Route::get('sedes-por-sucursal/{sucursalId}', [App\Http\Controllers\SedeController::class, 'porSucursal']);
    Route::get('sedes-por-empresa/{empresaId}', [App\Http\Controllers\SedeController::class, 'porEmpresa']);

    // Unidades funcionales (organización)
    Route::get('unidades-funcionales/buscar', [App\Http\Controllers\UnidadFuncionalController::class, 'buscar']);
    Route::apiResource('unidades-funcionales', App\Http\Controllers\UnidadFuncionalController::class);
    
    // Rutas de módulos
    Route::apiResource('modulos', App\Http\Controllers\Api\ModuloController::class);
    Route::get('modulos-tree', [App\Http\Controllers\Api\ModuloController::class, 'tree']);
    
    // Rutas de módulos-empresa
    Route::get('modulos-empresa/{idEmpresa}', [App\Http\Controllers\Api\ModuloEmpresaController::class, 'getModulosByEmpresa']);
    Route::get('empresas-modulo/{idModulo}', [App\Http\Controllers\Api\ModuloEmpresaController::class, 'getEmpresasByModulo']);
    Route::get('matriz-permisos', [App\Http\Controllers\Api\ModuloEmpresaController::class, 'matrizPermisos']);
    Route::post('asignar-modulo', [App\Http\Controllers\Api\ModuloEmpresaController::class, 'asignarModulo']);
    Route::post('remover-modulo', [App\Http\Controllers\Api\ModuloEmpresaController::class, 'removerModulo']);
    Route::post('actualizar-configuracion-modulo', [App\Http\Controllers\Api\ModuloEmpresaController::class, 'actualizarConfiguracion']);
    
    // Rutas de permisos
    Route::apiResource('permisos', App\Http\Controllers\PermisoController::class);
    Route::get('permisos-por-modulo', [App\Http\Controllers\PermisoController::class, 'porModulo']);
    
    // Rutas de roles
    Route::apiResource('roles', App\Http\Controllers\RolController::class);
    Route::post('roles/{id}/asignar-perfiles', [App\Http\Controllers\RolController::class, 'asignarPerfiles']);
    Route::get('roles/{id}/permisos', [App\Http\Controllers\RolController::class, 'obtenerPermisos']);
    Route::get('roles-por-empresa/{idEmpresa}', [App\Http\Controllers\RolController::class, 'porEmpresa']);
    Route::get('roles-por-empresa-modulos/{idEmpresa}', [App\Http\Controllers\RolController::class, 'rolesPorEmpresaConModulos']);
    Route::post('roles-por-multiples-empresas', [App\Http\Controllers\RolController::class, 'rolesPorMultiplesEmpresas']);
    
    // Rutas de perfiles
    Route::apiResource('perfiles', App\Http\Controllers\PerfilController::class);
    Route::get('perfiles-por-modulo', [App\Http\Controllers\PerfilController::class, 'porModulo']);
    Route::get('perfiles-modulo/{idModulo}', [App\Http\Controllers\PerfilController::class, 'perfilesPorModulo']);
    Route::get('permisos-disponibles/{idModulo}', [App\Http\Controllers\PerfilController::class, 'permisosDisponibles']);
    
    // Rutas de personificación
    Route::get('personificar/usuarios-disponibles', [App\Http\Controllers\PersonificarController::class, 'getUsuariosDisponibles']);
    Route::post('personificar/iniciar', [App\Http\Controllers\PersonificarController::class, 'iniciarPersonificacion']);
    Route::post('personificar/finalizar', [App\Http\Controllers\PersonificarController::class, 'finalizarPersonificacion']);
    Route::get('personificar/estado', [App\Http\Controllers\PersonificarController::class, 'getEstadoPersonificacion']);
    Route::get('personificar/historial', [App\Http\Controllers\PersonificarController::class, 'getHistorialPersonificaciones']);
    
    // Rutas de relación Perfil-Permiso (seg_perfil_permisos)
    Route::prefix('perfiles/{idPerfil}/permisos')->group(function () {
        Route::get('/', [App\Http\Controllers\PerfilPermisoController::class, 'index']); // Listar permisos del perfil
        Route::post('/', [App\Http\Controllers\PerfilPermisoController::class, 'store']); // Agregar un permiso
        Route::post('/sync', [App\Http\Controllers\PerfilPermisoController::class, 'sync']); // Sincronizar múltiples
        Route::get('/disponibles', [App\Http\Controllers\PerfilPermisoController::class, 'disponibles']); // Permisos disponibles
        Route::delete('/clear', [App\Http\Controllers\PerfilPermisoController::class, 'clear']); // Eliminar todos
        Route::delete('/{idPermiso}', [App\Http\Controllers\PerfilPermisoController::class, 'destroy']); // Eliminar uno
    });
});

// Rutas de Accounting (Empleados) → routes/Accounting/EmpleadosRouter.php
require __DIR__ . '/Accounting/EmpleadosRouter.php';

// Rutas de Fichas Técnicas Médicas (Contabilidad) → routes/Accounting/FichasTecnicasRouter.php
Route::middleware(['auth:api', 'check.user.active'])->prefix('fichas-tecnicas')->group(function () {
    require __DIR__ . '/Accounting/FichasTecnicasRouter.php';
});

// Rutas de Matriz de Obsolescencia - Parámetros
Route::middleware(['auth:api', 'check.user.active'])->prefix('matriz-obsolescencia')->group(function () {
    // Grupos de parámetros
    Route::get('/grupos', [App\Http\Controllers\MatrizObsParametroController::class, 'index']);
    Route::get('/grupos/{id}', [App\Http\Controllers\MatrizObsParametroController::class, 'getGrupo']);
    Route::post('/grupos', [App\Http\Controllers\MatrizObsParametroController::class, 'storeGrupo']);
    
    // Parámetros
    Route::get('/grupos/{grupoId}/parametros', [App\Http\Controllers\MatrizObsParametroController::class, 'getParametrosByGrupo']);
    Route::post('/parametros', [App\Http\Controllers\MatrizObsParametroController::class, 'storeParametro']);
    Route::put('/parametros/{id}', [App\Http\Controllers\MatrizObsParametroController::class, 'updateParametro']);
    Route::delete('/parametros/{id}', [App\Http\Controllers\MatrizObsParametroController::class, 'deleteParametro']);
    
    // Cálculos automáticos
    Route::post('/calcular-valores', [App\Http\Controllers\MatrizObsParametroController::class, 'ejecutarCalculos']);
    
    // Estadísticas por tipo
    Route::get('/estadisticas-por-tipo', [App\Http\Controllers\MatrizObsParametroController::class, 'getEstadisticasPorTipo']);
    
    // Estadísticas por ubicación
    Route::get('/estadisticas-por-ubicacion', [App\Http\Controllers\MatrizObsParametroController::class, 'getEstadisticasPorUbicacion']);
    
    // Equipos filtrados (para modales de gráficas)
    Route::get('/equipos-por-filtro', [App\Http\Controllers\MatrizObsParametroController::class, 'getEquiposPorFiltro']);
    
    // Agentes
    Route::get('/agentes', [App\Http\Controllers\MatrizObsAgenteController::class, 'index']);
    Route::post('/agentes', [App\Http\Controllers\MatrizObsAgenteController::class, 'store']);
    Route::get('/agentes/{id}', [App\Http\Controllers\MatrizObsAgenteController::class, 'show']);
    Route::put('/agentes/{id}', [App\Http\Controllers\MatrizObsAgenteController::class, 'update']);
    Route::delete('/agentes/{id}', [App\Http\Controllers\MatrizObsAgenteController::class, 'destroy']);
    
    // Parámetro de sincronización
    Route::get('/sincronizacion-parametro', [App\Http\Controllers\MatrizObsAgenteController::class, 'getSincronizacionParametro']);
    Route::put('/sincronizacion-parametro', [App\Http\Controllers\MatrizObsAgenteController::class, 'updateSincronizacionParametro']);
    
    // Procesadores
    Route::get('/procesadores', [App\Http\Controllers\MatrizObsolescencia\ProcesadorController::class, 'index']);
    Route::post('/procesadores', [App\Http\Controllers\MatrizObsolescencia\ProcesadorController::class, 'store']);
    Route::get('/procesadores/{id}', [App\Http\Controllers\MatrizObsolescencia\ProcesadorController::class, 'show']);
    Route::put('/procesadores/{id}', [App\Http\Controllers\MatrizObsolescencia\ProcesadorController::class, 'update']);
    Route::delete('/procesadores/{id}', [App\Http\Controllers\MatrizObsolescencia\ProcesadorController::class, 'destroy']);
    Route::get('/procesadores-desde-activos', [App\Http\Controllers\MatrizObsolescencia\ProcesadorController::class, 'getProcesadoresDesdeActivos']);
    Route::post('/procesadores-importar', [App\Http\Controllers\MatrizObsolescencia\ProcesadorController::class, 'importarProcesadoresDesdeActivos']);
    
    // Tipos de RAM
    Route::get('/tipos-ram', [App\Http\Controllers\MatrizObsolescencia\TipoRamController::class, 'index']);
    Route::post('/tipos-ram', [App\Http\Controllers\MatrizObsolescencia\TipoRamController::class, 'store']);
    Route::get('/tipos-ram/{id}', [App\Http\Controllers\MatrizObsolescencia\TipoRamController::class, 'show']);
    Route::put('/tipos-ram/{id}', [App\Http\Controllers\MatrizObsolescencia\TipoRamController::class, 'update']);
    Route::delete('/tipos-ram/{id}', [App\Http\Controllers\MatrizObsolescencia\TipoRamController::class, 'destroy']);
    Route::get('/tipos-ram-desde-activos', [App\Http\Controllers\MatrizObsolescencia\TipoRamController::class, 'getTiposRamDesdeActivos']);
    Route::post('/tipos-ram-importar', [App\Http\Controllers\MatrizObsolescencia\TipoRamController::class, 'importarTiposRamDesdeActivos']);
});

// Rutas de Matriz de Obsolescencia - Activos
Route::middleware(['auth:api', 'check.user.active'])->prefix('matriz-obs-activos')->group(function () {
    // Estadísticas
    Route::get('/estadisticas', [App\Http\Controllers\MatrizObsActivoController::class, 'getEstadisticas']);

    // Datos agregados para el dashboard (conteos por estado/tipo/ubicación)
    Route::get('/dashboard', [App\Http\Controllers\MatrizObsActivoController::class, 'dashboard']);

    // Opciones para los dropdowns de filtros (empresa/sucursal/sede)
    Route::get('/filtros', [App\Http\Controllers\MatrizObsActivoController::class, 'opcionesFiltros']);
    
    // Activos por permisos del usuario
    Route::get('/por-permisos', [App\Http\Controllers\MatrizObsActivoController::class, 'getActivosPorPermisos']);
    
    // Activos por empresa, sucursal, sede
    Route::get('/empresa/{empresaId}', [App\Http\Controllers\MatrizObsActivoController::class, 'getActivosPorEmpresa']);
    Route::get('/sucursal/{sucursalId}', [App\Http\Controllers\MatrizObsActivoController::class, 'getActivosPorSucursal']);
    Route::get('/sede/{sedeId}', [App\Http\Controllers\MatrizObsActivoController::class, 'getActivosPorSede']);
    
    // Exportaciones
    Route::get('/exportar', [App\Http\Controllers\MatrizObsActivoController::class, 'exportarActivos']);
    Route::get('/exportar-estadisticas', [App\Http\Controllers\MatrizObsActivoController::class, 'exportarEstadisticas']);
    
    // CRUD básico
    Route::get('/', [App\Http\Controllers\MatrizObsActivoController::class, 'index']);
    Route::get('/{id}', [App\Http\Controllers\MatrizObsActivoController::class, 'show']);
    Route::put('/{id}', [App\Http\Controllers\MatrizObsActivoController::class, 'update']);
});

// ─── Rutas de Cierre de Inventario - Matriz de Obsolescencia ─────────────────
Route::middleware(['auth:api', 'check.user.active'])->prefix('cierre-inventario')->group(function () {

    // Configuración del proceso (rutas específicas ANTES del {id} para evitar conflictos)
    Route::get('/config',    [App\Http\Controllers\MatrizObsolescencia\CierreInventarioController::class, 'getConfig']);
    Route::put('/config',    [App\Http\Controllers\MatrizObsolescencia\CierreInventarioController::class, 'updateConfig']);

    // Comparación entre dos cierres
    Route::get('/comparar',  [App\Http\Controllers\MatrizObsolescencia\CierreInventarioController::class, 'comparar']);

    // CRUD de cierres
    Route::get('/',          [App\Http\Controllers\MatrizObsolescencia\CierreInventarioController::class, 'index']);
    Route::post('/',         [App\Http\Controllers\MatrizObsolescencia\CierreInventarioController::class, 'store']);
    Route::get('/{id}',      [App\Http\Controllers\MatrizObsolescencia\CierreInventarioController::class, 'show']);
    Route::delete('/{id}',   [App\Http\Controllers\MatrizObsolescencia\CierreInventarioController::class, 'destroy']);

    // Sub-recursos del cierre
    Route::get('/{id}/detalle',         [App\Http\Controllers\MatrizObsolescencia\CierreInventarioController::class, 'detalle']);
    Route::get('/{id}/resumen-empresa', [App\Http\Controllers\MatrizObsolescencia\CierreInventarioController::class, 'resumenPorEmpresa']);
});

// Rutas de Plantillas de Documentos
Route::middleware(['auth:api', 'check.user.active'])->prefix('templates')->group(function () {
    Route::get('/', [App\Http\Controllers\TemplateController::class, 'index']);
    Route::get('/category/{category}', [App\Http\Controllers\TemplateController::class, 'byCategory']);
    Route::get('/{id}', [App\Http\Controllers\TemplateController::class, 'show']);
    Route::post('/', [App\Http\Controllers\TemplateController::class, 'store']);
    Route::put('/{id}', [App\Http\Controllers\TemplateController::class, 'update']);
    Route::delete('/{id}', [App\Http\Controllers\TemplateController::class, 'destroy']);
});

// Rutas de Variables de Plantillas
Route::middleware(['auth:api', 'check.user.active'])->prefix('variables')->group(function () {
    Route::get('/', [App\Http\Controllers\VariableController::class, 'index']);
});

// Rutas de Anticipos → routes/Finance/AnticiposRouter.php
Route::middleware(['auth:api', 'check.user.active'])->prefix('anticipos')->group(function () {
    require __DIR__ . '/Finance/AnticiposRouter.php';
});

// Rutas de Workflow (Administración de Flujos) → routes/Workflow/WorkflowRouter.php
Route::middleware(['auth:api', 'check.user.active'])->prefix('workflow')->group(function () {
    require __DIR__ . '/Workflow/WorkflowRouter.php';
});

// Rutas de Cuadro de Turnos → routes/TalentoHumano/CuadroTurnos/CuadroTurnosRouter.php
Route::middleware(['auth:api', 'check.user.active'])->prefix('turnos')->group(function () {
    require __DIR__ . '/TalentoHumano/CuadroTurnos/CuadroTurnosRouter.php';
});

// Rutas de Microsoft Fabric / Analytics → routes/Fabric/FabricRouter.php
Route::middleware(['auth:api', 'check.user.active'])->prefix('fabric')->group(function () {
    require __DIR__ . '/Fabric/FabricRouter.php';
});

// ── Dashboard de Rendimiento API (Organización > Servicios > Rendimiento)
Route::middleware(['auth:api'])->prefix('system/performance')->group(function () {
    Route::get('/', [\App\Http\Controllers\System\ApiPerformanceController::class, 'index']);
    Route::get('/horizon', [\App\Http\Controllers\System\ApiPerformanceController::class, 'horizon']);
    Route::get('/jobs', [\App\Http\Controllers\System\ApiPerformanceController::class, 'jobs']);
    Route::get('/failed-jobs', [\App\Http\Controllers\System\ApiPerformanceController::class, 'failedJobs']);
});

// ── SSE Export Stream — PÚBLICO (sin JWT, jobId es token implícito)
// EventSource no soporta headers Authorization → ruta sin auth middleware.
Route::get('/fabric/viewer/export/stream/{jobId}', [\App\Http\Controllers\Fabric\FabricViewerController::class, 'exportStream'])
    ->where('jobId', '[a-zA-Z0-9\-_]{10,80}');

// ── OData Endpoint PÚBLICO — sin auth:api (tiene su propia autenticación por token/Azure AD)
// Excel/Power Query se conecta directamente a esta URL.
Route::prefix('fabric/odata')->group(function () {
    // Service document (Excel lo pide al conectar como fuente OData)
    Route::get('/link/{code}/$metadata', [\App\Http\Controllers\Fabric\ODataController::class, 'metadata'])
        ->where('code', '[a-f0-9]{32}');
    // EntitySet "value" — Excel accede aquí para los datos (agrega /value al base)
    Route::get('/link/{code}/value', [\App\Http\Controllers\Fabric\ODataController::class, 'queryByLink'])
        ->where('code', '[a-f0-9]{32}');
    // Service document (raíz)
    Route::get('/link/{code}', [\App\Http\Controllers\Fabric\ODataController::class, 'queryByLink'])
        ->where('code', '[a-f0-9]{32}');
});

// Rutas de Notificaciones (Interconsultas) → routes/Notificaciones/NotificacionesRouter.php
Route::middleware(['auth:api', 'check.user.active'])->prefix('notificaciones')->group(function () {
    require __DIR__ . '/Notificaciones/NotificacionesRouter.php';
});

// ─── Rutas de Talento Humano - Eventos ───────────────────────────────────────
Route::middleware(['auth:api', 'check.user.active'])->prefix('talento-humano/eventos')->group(function () {
    require __DIR__ . '/TalentoHumano/EventosRouter.php';
});

// Rutas de GLPI API Integration
Route::middleware(['auth:api', 'check.user.active'])->prefix('glpi')->group(function () {
    // Rutas de sesión GLPI
    Route::post('/session/init', [App\Http\Controllers\GLPI\GLPIController::class, 'initSession']);
    Route::delete('/session/kill', [App\Http\Controllers\GLPI\GLPIController::class, 'killSession']);
    Route::get('/session/profiles', [App\Http\Controllers\GLPI\GLPIController::class, 'getMyProfiles']);
    Route::get('/session/active-profile', [App\Http\Controllers\GLPI\GLPIController::class, 'getActiveProfile']);
    Route::post('/session/change-profile', [App\Http\Controllers\GLPI\GLPIController::class, 'changeActiveProfile']);
    Route::get('/session/entities', [App\Http\Controllers\GLPI\GLPIController::class, 'getMyEntities']);
    Route::post('/session/change-entities', [App\Http\Controllers\GLPI\GLPIController::class, 'changeActiveEntities']);
    Route::get('/session/full', [App\Http\Controllers\GLPI\GLPIController::class, 'getFullSession']);
    
    // Rutas de sincronización de activos
    Route::prefix('sync')->group(function () {
        Route::get('/test', function() {
            return response()->json([
                'success' => true,
                'message' => 'Ruta de sincronización funcionando correctamente',
                'user' => auth()->user() ? auth()->user()->name : 'No autenticado',
                'timestamp' => now()->toISOString()
            ]);
        });
        Route::post('/force-all', [App\Http\Controllers\GLPI\SyncActivosController::class, 'forceSyncAll']);
        Route::post('/cancel', [App\Http\Controllers\GLPI\SyncActivosController::class, 'cancelSync']);
        Route::get('/status', [App\Http\Controllers\GLPI\SyncActivosController::class, 'getSyncStatus']);
        Route::post('/single-asset', [App\Http\Controllers\GLPI\SyncActivosController::class, 'syncSingleAsset']);
        Route::post('/auto', [App\Http\Controllers\GLPI\SyncActivosController::class, 'autoSync']);
        Route::get('/stats', [App\Http\Controllers\GLPI\SyncActivosController::class, 'getSyncStats']);
        Route::get('/last-status', [App\Http\Controllers\GLPI\SyncActivosController::class, 'getLastSyncStatus']);
    });
    
    // Rutas de computadoras GLPI
    Route::prefix('computers')->group(function () {
        Route::get('/', [App\Http\Controllers\GLPI\ComputerController::class, 'index']);
        Route::post('/', [App\Http\Controllers\GLPI\ComputerController::class, 'store']);
        Route::get('/search', [App\Http\Controllers\GLPI\ComputerController::class, 'search']);
        Route::get('/{id}', [App\Http\Controllers\GLPI\ComputerController::class, 'show']);
        Route::put('/{id}', [App\Http\Controllers\GLPI\ComputerController::class, 'update']);
        Route::delete('/{id}', [App\Http\Controllers\GLPI\ComputerController::class, 'destroy']);
        Route::get('/{id}/devices', [App\Http\Controllers\GLPI\ComputerController::class, 'getDevices']);
        Route::get('/{id}/software', [App\Http\Controllers\GLPI\ComputerController::class, 'getSoftware']);
        
        // Rutas específicas para datos detallados
        Route::get('/{id}/validate', [App\Http\Controllers\GLPI\ComputerDetailController::class, 'validateComputer']);
        Route::get('/{id}/basic-info', [App\Http\Controllers\GLPI\ComputerDetailController::class, 'getBasicInfo']);
        Route::get('/{id}/memory', [App\Http\Controllers\GLPI\ComputerDetailController::class, 'getMemoryInfo']);
        Route::get('/{id}/processor', [App\Http\Controllers\GLPI\ComputerDetailController::class, 'getProcessorInfo']);
        Route::get('/{id}/disks', [App\Http\Controllers\GLPI\ComputerDetailController::class, 'getDiskInfo']);
        Route::get('/{id}/operating-system', [App\Http\Controllers\GLPI\ComputerDetailController::class, 'getOperatingSystemInfo']);
        Route::get('/{id}/financial', [App\Http\Controllers\GLPI\ComputerDetailController::class, 'getFinancialInfo']);
        Route::get('/{id}/tags', [App\Http\Controllers\GLPI\ComputerDetailController::class, 'getTagInfo']);
        Route::get('/{id}/complete', [App\Http\Controllers\GLPI\ComputerDetailController::class, 'getCompleteInfo']);
    });
});

// ─── Rutas de Tareas Programadas (Task Scheduler) ────────────────────────────
Route::middleware(['auth:api', 'check.user.active'])->prefix('v1')->group(function () {
    // Rutas específicas (deben ir ANTES del apiResource)
    Route::get('scheduled-tasks/stats/dashboard', [App\Http\Controllers\Api\TaskSchedulerController::class, 'dashboardStats']);
    Route::get('scheduled-tasks/types', [App\Http\Controllers\Api\TaskSchedulerController::class, 'types']);
    Route::get('scheduled-tasks/recurrence-types', [App\Http\Controllers\Api\TaskSchedulerController::class, 'recurrenceTypes']);
    Route::post('scheduled-tasks/{id}/execute', [App\Http\Controllers\Api\TaskSchedulerController::class, 'execute']);
    Route::post('scheduled-tasks/{id}/cancel', [App\Http\Controllers\Api\TaskSchedulerController::class, 'cancel']);
    Route::post('scheduled-tasks/{id}/retry', [App\Http\Controllers\Api\TaskSchedulerController::class, 'retry']);
    Route::post('scheduled-tasks/{id}/toggle', [App\Http\Controllers\Api\TaskSchedulerController::class, 'toggle']);
    
    // CRUD estándar
    Route::apiResource('scheduled-tasks', App\Http\Controllers\Api\TaskSchedulerController::class);
});

// ─── Rutas de Configuración - Secuencias Numéricas ───────────────────────────
Route::middleware(['auth:api', 'check.user.active'])->prefix('config/secuencias')->group(function () {

    // Catálogo de patrones
    Route::prefix('patrones')->group(function () {
        Route::get('/',        [App\Http\Controllers\Config\SecPatronController::class, 'index']);
        Route::post('/',       [App\Http\Controllers\Config\SecPatronController::class, 'store']);
        Route::get('/{id}',    [App\Http\Controllers\Config\SecPatronController::class, 'show']);
        Route::put('/{id}',    [App\Http\Controllers\Config\SecPatronController::class, 'update']);
        Route::delete('/{id}', [App\Http\Controllers\Config\SecPatronController::class, 'destroy']);
    });

    // Cabeceras de secuencia
    Route::get('/previsualizar', [App\Http\Controllers\Config\SecSecuenciaController::class, 'previsualizar']);
    Route::get('/',              [App\Http\Controllers\Config\SecSecuenciaController::class, 'index']);
    Route::post('/',             [App\Http\Controllers\Config\SecSecuenciaController::class, 'store']);
    Route::get('/{id}',          [App\Http\Controllers\Config\SecSecuenciaController::class, 'show']);
    Route::put('/{id}',          [App\Http\Controllers\Config\SecSecuenciaController::class, 'update']);
    Route::delete('/{id}',       [App\Http\Controllers\Config\SecSecuenciaController::class, 'destroy']);

    // Detalles anidados bajo la secuencia
    Route::prefix('/{secuenciaId}/detalles')->group(function () {
        Route::get('/',              [App\Http\Controllers\Config\SecDetalleController::class, 'index']);
        Route::post('/',             [App\Http\Controllers\Config\SecDetalleController::class, 'store']);
        Route::put('/{detalleId}',   [App\Http\Controllers\Config\SecDetalleController::class, 'update']);
        Route::delete('/{detalleId}',[App\Http\Controllers\Config\SecDetalleController::class, 'destroy']);
    });
});

// ─── Rutas de Inventario (Migración JadeInventory) ───────────────────────────
Route::middleware(['auth:api', 'check.user.active'])->prefix('inventario')->group(function () {
    require __DIR__ . '/Inventory/InventoryRouter.php';
});

// ── Tableros Públicos (SSE para TVs, sin login) ─────────────────────────────
// Token secreto por sede: la seguridad está en el token, no en la sesión.
Route::prefix('public/tableros/urgencias')->group(function () {
    Route::get('/stream', [\App\Http\Controllers\Tableros\TableroPublicController::class, 'stream']);
    Route::get('/data', [\App\Http\Controllers\Tableros\TableroPublicController::class, 'data']);
    Route::post('/pair', [\App\Http\Controllers\Tableros\TableroPublicController::class, 'pair']);
    Route::post('/reconnect', [\App\Http\Controllers\Tableros\TableroPublicController::class, 'reconnect']);
});

// ── Tableros (Urgencias, etc.) — Requiere auth + rol "Tablero"
Route::middleware(['auth:api'])->prefix('tableros')->group(function () {
    require __DIR__ . '/Tableros/TablerosRouter.php';
});

// Rutas de Mesa de Servicio — Parametrizador GLPI
Route::middleware(['auth:api', 'check.user.active'])->prefix('mesa-servicio/glpi')->group(function () {
    require __DIR__ . '/MesaServicio/GlpiParamRouter.php';
});