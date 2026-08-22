<?php

declare(strict_types=1);

use App\Http\Controllers\MesaServicio\GlpiPlantillaController;
use App\Http\Controllers\MesaServicio\GlpiTicketsTicController;
use App\Http\Controllers\MesaServicio\GlpiValidadorController;
use Illuminate\Support\Facades\Route;

/**
 * Rutas del parametrizador GLPI (Mesa de Servicio).
 *
 * Se monta bajo el prefijo `api/mesa-servicio/glpi` con `auth:api` +
 * `check.user.active` aplicados desde `routes/api.php`.
 */

Route::get('/plantillas', [GlpiPlantillaController::class, 'index']);
Route::post('/plantillas', [GlpiPlantillaController::class, 'store']);
Route::get('/plantillas/{id}', [GlpiPlantillaController::class, 'show'])->whereNumber('id');
Route::put('/plantillas/{id}', [GlpiPlantillaController::class, 'update'])->whereNumber('id');
Route::delete('/plantillas/{id}', [GlpiPlantillaController::class, 'destroy'])->whereNumber('id');
Route::patch('/plantillas/{id}/toggle-estado', [GlpiPlantillaController::class, 'toggleEstado'])->whereNumber('id');

Route::get('/tablero-tic', [GlpiTicketsTicController::class, 'index']);

Route::get('/validador/entidades', [GlpiValidadorController::class, 'entidades']);
Route::post('/validador/comparar', [GlpiValidadorController::class, 'comparar']);
Route::post('/validador/comparar-regla', [GlpiValidadorController::class, 'compararRegla']);
