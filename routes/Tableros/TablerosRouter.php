<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Tableros\TableroUrgenciasController;

/**
 * Rutas de Tableros (pantallas informativas para sedes).
 *
 * Prefijo: /api/tableros
 * Middleware: auth:api
 *
 * Cada tablero valida internamente que el usuario tenga el rol adecuado
 * y filtra datos por la sucursal del usuario.
 */

Route::get('/urgencias', [TableroUrgenciasController::class, 'index']);
