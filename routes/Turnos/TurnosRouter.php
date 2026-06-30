<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Turnos\PlantillaController;
use App\Http\Controllers\Turnos\GrupoController;
use App\Http\Controllers\Turnos\CuadroController;
use App\Http\Controllers\Turnos\AsignacionController;
use App\Http\Controllers\Turnos\NovedadController;
use App\Http\Controllers\Turnos\UnidadFuncionalController;
use App\Http\Controllers\Turnos\FestivoController;
use App\Http\Controllers\Turnos\CalculoHorasController;
use App\Http\Controllers\Turnos\TurnosTerceroController;
use App\Http\Controllers\CuadroTurnoPermisoController;

/**
 * Rutas del módulo Cuadro de Turnos
 *
 * Prefijo: /api/turnos
 * Middleware: auth:api, check.user.active
 */

Route::middleware(['auth:api'])->group(function () {

    // =========================================================================
    // PERMISOS DE CUADRO DE TURNOS
    // =========================================================================
    Route::get('cuadro-turno-permisos/usuario-completo/{userId}', [CuadroTurnoPermisoController::class, 'usuarioCompleto']);
    Route::get('cuadro-turno-permisos/debug', [CuadroTurnoPermisoController::class, 'debug']);
    Route::get('cuadro-turno-permisos/usuarios', [CuadroTurnoPermisoController::class, 'listarUsuarios']);
    Route::get('cuadro-turno-permisos/empresas', [CuadroTurnoPermisoController::class, 'listarEmpresas']);
    Route::get('cuadro-turno-permisos/sedes', [CuadroTurnoPermisoController::class, 'listarSedes']);
    Route::get('cuadro-turno-permisos/unidades-funcionales-con-prefijo/{sedeId}', [CuadroTurnoPermisoController::class, 'listarUnidadesPorSedeConPrefijo']);
    Route::get('cuadro-turno-permisos/unidades-funcionales/{sedeId}', [CuadroTurnoPermisoController::class, 'listarUnidadesPorSede']);
    Route::get('cuadro-turno-permisos/usuario/{userId}', [CuadroTurnoPermisoController::class, 'permisosPorUsuario']);
    Route::get('cuadro-turno-permisos', [CuadroTurnoPermisoController::class, 'index']);
    Route::post('cuadro-turno-permisos', [CuadroTurnoPermisoController::class, 'store']);
    Route::delete('cuadro-turno-permisos/{id}', [CuadroTurnoPermisoController::class, 'destroy']);
    Route::post('cuadro-turno-permisos/asignar-multiples', [CuadroTurnoPermisoController::class, 'asignarMultiples']);

    // =========================================================================
    // UNIDADES FUNCIONALES
    // =========================================================================
    Route::get('unidades-funcionales/del-usuario', [UnidadFuncionalController::class, 'delUsuario']);
    Route::get('unidades-funcionales/{id}/empleados', [UnidadFuncionalController::class, 'empleados']);
    Route::apiResource('unidades-funcionales', UnidadFuncionalController::class);

    // =========================================================================
    // NAVEGACIÓN PARA SUPER_ADMIN: EMPRESA → SUCURSAL → SEDE → UNIDAD
    // =========================================================================
    Route::get('empresas/{empresaId}/sucursales', [UnidadFuncionalController::class, 'sucursalesPorEmpresa']);
    Route::get('sucursales/{sucursalId}/sedes', [UnidadFuncionalController::class, 'sedesPorSucursal']);
    Route::get('sucursales/{sucursalId}/unidades-terceros', [UnidadFuncionalController::class, 'unidadesTercerosPorSucursal']);
    Route::get('sedes/{sedeId}/unidades-terceros', [UnidadFuncionalController::class, 'unidadesTercerosPorSede']);
    Route::get('sedes/{sedeId}/empleados-terceros', [UnidadFuncionalController::class, 'empleadosTercerosPorSede']);
    Route::get('empresas/{empresaId}/sedes', [UnidadFuncionalController::class, 'sedesPorEmpresa']);
    Route::get('sedes/{empresaId}/{sedeId}/unidades', [UnidadFuncionalController::class, 'unidadesPorSede']);

    // =========================================================================
    // PLANTILLAS DE TURNO
    // =========================================================================
    Route::apiResource('plantillas', PlantillaController::class);

    // =========================================================================
    // TIPOS DE NOVEDAD (catálogo)
    // =========================================================================
    Route::get('novedad-tipos', [PlantillaController::class, 'indexNovedadTipos']);
    Route::post('novedad-tipos', [PlantillaController::class, 'storeNovedadTipo']);
    Route::put('novedad-tipos/{id}', [PlantillaController::class, 'updateNovedadTipo']);

    // =========================================================================
    // GRUPOS
    // =========================================================================
    Route::apiResource('grupos', GrupoController::class);

    // Encargados del grupo
    Route::post('grupos/{id}/encargado', [GrupoController::class, 'asignarEncargado']);
    Route::get('grupos/{id}/encargado/historial', [GrupoController::class, 'historialEncargados']);

    // Empleados del grupo
    Route::get('grupos/{id}/empleados', [GrupoController::class, 'listarEmpleados']);
    Route::post('grupos/{id}/empleados', [GrupoController::class, 'agregarEmpleado']);
    Route::delete('grupos/{id}/empleados/{idEmpleado}', [GrupoController::class, 'retirarEmpleado']);

    // =========================================================================
    // CUADROS DE TURNO
    // =========================================================================
    Route::apiResource('cuadros', CuadroController::class)->except(['update']);

    // Transiciones de estado
    Route::post('cuadros/{id}/publicar', [CuadroController::class, 'publicar']);
    Route::post('cuadros/{id}/cerrar', [CuadroController::class, 'cerrar']);

    // Grilla y asignación masiva
    Route::get('cuadros/{id}/grilla', [CuadroController::class, 'grilla']);
    Route::post('cuadros/{id}/asignaciones', [CuadroController::class, 'asignarMasivo']);

    // Turnos de un empleado en un período
    Route::get('empleados/{idEmpleado}/turnos', [AsignacionController::class, 'turnosEmpleado']);

    // Vista individual: cuadro completo del empleado (turnos + totales de horas por categoría + festivos)
    Route::get('empleados/{idEmpleado}/cuadro-mes', [AsignacionController::class, 'cuadroMesEmpleado']);
    
    // Eliminar todo el cuadro de turnos del empleado en un mes/año
    Route::delete('empleados/{idEmpleado}/cuadro-mes', [AsignacionController::class, 'eliminarCuadroMesEmpleado']);

    // Asegura que exista un cuadro mensual para una UNIDAD FUNCIONAL (lo crea si no existe)
    Route::post('cuadros/ensure', [AsignacionController::class, 'ensureCuadroUnidad']);

    // =========================================================================
    // ASIGNACIONES INDIVIDUALES
    // =========================================================================
    Route::apiResource('asignaciones', AsignacionController::class)->except(['index']);

    // =========================================================================
    // FESTIVOS
    // =========================================================================
    Route::get('festivos', [FestivoController::class, 'index']);
    Route::post('festivos', [FestivoController::class, 'store']);
    Route::put('festivos/{id}', [FestivoController::class, 'update']);
    Route::delete('festivos/{id}', [FestivoController::class, 'destroy']);
    
    // Nuevos endpoints para API externa
    Route::post('festivos/sincronizar', [FestivoController::class, 'sincronizar']);
    Route::get('festivos/test-conexion', [FestivoController::class, 'testConexion']);

    // =========================================================================
    // CÁLCULO DE HORAS (4 categorías: normales, nocturnas, festivas, festivas_nocturnas)
    // =========================================================================
    Route::get('calculo/empleado/{idEmpleado}', [CalculoHorasController::class, 'porEmpleadoMes']);
    Route::get('calculo/empleado/{idEmpleado}/rango', [CalculoHorasController::class, 'porEmpleadoRango']);

    // =========================================================================
    // NOVEDADES
    // =========================================================================
    Route::apiResource('novedades', NovedadController::class);

    // Aprobación / rechazo
    Route::post('novedades/{id}/aprobar', [NovedadController::class, 'aprobar']);
    Route::post('novedades/{id}/rechazar', [NovedadController::class, 'rechazar']);

    // =========================================================================
    // TERCEROS: Mapeo + Asignación a Unidades Funcionales (CAPA ADICIONAL)
    // =========================================================================
    Route::get('unidades/{unidadId}/todos-empleados', [TurnosTerceroController::class, 'getEmpleadosPorUnidad']);
    Route::post('unidades/{unidadId}/terceros', [TurnosTerceroController::class, 'asignarTercero']);
    Route::delete('unidades/{unidadId}/terceros/{terceroId}', [TurnosTerceroController::class, 'desasignarTercero']);
    Route::get('terceros/por-empresa/{empresaId}', [TurnosTerceroController::class, 'getTercerosPorEmpresa']);
    Route::get('mapeo-unidades/pendientes/{empresaId}', [TurnosTerceroController::class, 'getUnidadesSinMapeo']);
    Route::post('mapeo-unidades', [TurnosTerceroController::class, 'guardarMapeoUnidad']);
});