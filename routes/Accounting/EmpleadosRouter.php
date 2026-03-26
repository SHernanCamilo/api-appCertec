<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Accounting\EmpleadoController;

Route::middleware(['auth:api', 'check.user.active'])->group(function () {
    Route::get('empleados', [EmpleadoController::class, 'index']);
    Route::post('empleados', [EmpleadoController::class, 'store']);
    Route::put('empleados/{id}', [EmpleadoController::class, 'update']);
    Route::delete('empleados/{id}', [EmpleadoController::class, 'destroy']);

    Route::get('personas/empleado-actual', [EmpleadoController::class, 'buscarPorDocumentoActual']);
});
