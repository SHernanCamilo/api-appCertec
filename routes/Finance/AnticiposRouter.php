<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Finance\AnticipoConceptoController;

Route::middleware(['auth:api', 'check.user.active'])->prefix('anticipos')->group(function () {
    // Tipos de anticipos
    Route::get('/tipos', [AnticipoConceptoController::class, 'getTipos']);

    // Clases por tipo
    Route::get('/tipos/{tipoId}/clases', [AnticipoConceptoController::class, 'getClasesPorTipo']);

    // Modalidades por clase
    Route::get('/clases/{claseId}/modalidades', [AnticipoConceptoController::class, 'getModalidadesPorClase']);

    // CRUD de conceptos
    Route::get('/conceptos', [AnticipoConceptoController::class, 'index']);
    Route::post('/conceptos', [AnticipoConceptoController::class, 'store']);
    Route::get('/conceptos/{id}', [AnticipoConceptoController::class, 'show']);
    Route::put('/conceptos/{id}', [AnticipoConceptoController::class, 'update']);
    Route::delete('/conceptos/{id}', [AnticipoConceptoController::class, 'destroy']);
    Route::patch('/conceptos/{id}/toggle-estado', [AnticipoConceptoController::class, 'toggleEstado']);
});
