<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Accounting\EmpleadoController;

Route::middleware(['auth:api', 'check.user.active'])->group(function () {
    Route::get('empleados', [EmpleadoController::class, 'index']);
    Route::post('empleados', [EmpleadoController::class, 'store']);
    Route::put('empleados/{id}', [EmpleadoController::class, 'update']);
    Route::delete('empleados/{id}', [EmpleadoController::class, 'destroy']);

    Route::get('personas/empleado-actual', [EmpleadoController::class, 'buscarPorDocumentoActual']);

    // Unidades funcionales únicas de la tabla terceros
    Route::get('terceros/unidades', [EmpleadoController::class, 'unidadesDisponibles']);
    // Empleados de una unidad funcional específica
    Route::get('terceros/por-unidad', [EmpleadoController::class, 'empleadosPorUnidad']);
    // Turnos de todos los empleados de una unidad funcional (grilla mensual)
    Route::get('terceros/turnos-unidad-mes', [EmpleadoController::class, 'turnosUnidadMes']);
});
