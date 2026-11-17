<?php

declare(strict_types=1);

use App\Http\Controllers\MesaServicio\GlpiPlantillaController;
use App\Http\Controllers\MesaServicio\GlpiTeamsDestinoController;
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
Route::post('/plantillas/{id}/duplicar', [GlpiPlantillaController::class, 'duplicar'])->whereNumber('id');

Route::get('/tablero-tic', [GlpiTicketsTicController::class, 'index'])
    ->middleware('check.permission:mesa-glpi-tablero-ver');

Route::middleware('check.permission:mesa-glpi-tablero-notificar')->group(function () {
    Route::get('/teams-destinos', [GlpiTeamsDestinoController::class, 'index']);
    Route::post('/teams-destinos', [GlpiTeamsDestinoController::class, 'store']);
    Route::post('/teams-destinos/vista-previa', [GlpiTeamsDestinoController::class, 'vistaPrevia']);
    Route::get('/teams-destinos/grupos', [GlpiTeamsDestinoController::class, 'grupos']);
    Route::get('/teams-destinos/chats', [GlpiTeamsDestinoController::class, 'chats']);
    Route::get('/teams-destinos/equipos', [GlpiTeamsDestinoController::class, 'equipos']);
    Route::get('/teams-destinos/equipos/{teamId}/canales', [GlpiTeamsDestinoController::class, 'canales'])
        ->whereUuid('teamId');
    Route::put('/teams-destinos/{id}', [GlpiTeamsDestinoController::class, 'update'])->whereNumber('id');
    Route::delete('/teams-destinos/{id}', [GlpiTeamsDestinoController::class, 'destroy'])->whereNumber('id');
    Route::post('/teams-destinos/{id}/enviar', [GlpiTeamsDestinoController::class, 'enviar'])->whereNumber('id');
});

Route::get('/validador/entidades', [GlpiValidadorController::class, 'entidades']);
Route::post('/validador/comparar', [GlpiValidadorController::class, 'comparar']);
Route::post('/validador/comparar-regla', [GlpiValidadorController::class, 'compararRegla']);
