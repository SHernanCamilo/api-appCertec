<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Notificaciones\NotificacionDashboardController;

/**
 * Rutas del módulo de Notificaciones (Interconsultas)
 *
 * Prefijo: /api/notificaciones
 * Middleware: auth:api
 */

Route::middleware(['auth:api'])->group(function () {

    // =========================================================================
    // DASHBOARD — Estadísticas y resumen
    // =========================================================================
    Route::get('/dashboard', [NotificacionDashboardController::class, 'dashboard']);

    // =========================================================================
    // LISTADO DE EMAILS — Con filtros y paginación
    // =========================================================================
    Route::get('/emails', [NotificacionDashboardController::class, 'index']);

    // =========================================================================
    // DETALLE + TRAZABILIDAD — Un email específico con su historial
    // =========================================================================
    Route::get('/emails/{id}', [NotificacionDashboardController::class, 'show']);

    // =========================================================================
    // REBOTADOS — Emails que no llegaron (para acción correctiva)
    // =========================================================================
    Route::get('/rebotados', [NotificacionDashboardController::class, 'rebotados']);

    // =========================================================================
    // VERIFICAR REBOTES — Forzar chequeo manual desde Graph API
    // =========================================================================
    Route::post('/check-bounces', [NotificacionDashboardController::class, 'checkBounces']);
});
