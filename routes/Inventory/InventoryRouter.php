<?php

use Illuminate\Support\Facades\Route;

// =========================================================================
// PRODUCTOS (Farmacia)
// =========================================================================
Route::post('productos/bulk-validate', [App\Http\Controllers\Inventory\Pharmacy\InvProductoController::class, 'validateBulkProducts']);
Route::apiResource('productos', App\Http\Controllers\Inventory\Pharmacy\InvProductoController::class);

// Dashboard
Route::get('dashboard/stats', [App\Http\Controllers\Inventory\Pharmacy\InvDashboardController::class, 'getStats']);

// Pedidos
Route::patch('pedidos/{pedido}/estado', [App\Http\Controllers\Inventory\Pharmacy\InvPedidoController::class, 'cambiarEstado']);
Route::apiResource('pedidos', App\Http\Controllers\Inventory\Pharmacy\InvPedidoController::class);

// Órdenes de Compra
Route::post('ordenes-compra/sync', [App\Http\Controllers\Inventory\Pharmacy\InvOrdenCompraController::class, 'sync']);
Route::patch('ordenes-compra/{orden}/estado', [App\Http\Controllers\Inventory\Pharmacy\InvOrdenCompraController::class, 'cambiarEstado']);
Route::apiResource('ordenes-compra', App\Http\Controllers\Inventory\Pharmacy\InvOrdenCompraController::class);

// Recepciones Técnicas
Route::patch('recepciones/{recepcion}/confirmar', [App\Http\Controllers\Inventory\Pharmacy\InvRecepcionController::class, 'confirmar']);
Route::apiResource('recepciones', App\Http\Controllers\Inventory\Pharmacy\InvRecepcionController::class);

// =========================================================================
// ACTIVOS FIJOS — Toma de inventario y trazabilidad
//
// El maestro de activos es de solo lectura (vista Fabric ra.VW_Fixed_DetalleActivos).
// Lo que se escribe son las novedades encontradas en sitio (inv_traz_activo).
//
// Las rutas específicas van ANTES de /{placa} para que no las capture el wildcard.
// =========================================================================
Route::prefix('activos-fijos')->group(function () {
    $controller = App\Http\Controllers\Inventory\InvActivoFijoController::class;

    Route::get('columnas', [$controller, 'columnas']);
    Route::get('opciones', [$controller, 'opciones']);
    Route::get('buscar', [$controller, 'buscar']);

    Route::get('trazabilidad/resumen', [$controller, 'resumen']);
    Route::get('trazabilidad', [$controller, 'trazabilidad']);

    Route::post('novedad', [$controller, 'registrarNovedad']);

    Route::get('{placa}/historial', [$controller, 'historial'])->where('placa', '[A-Za-z0-9\-_.]+');
    Route::get('{placa}', [$controller, 'show'])->where('placa', '[A-Za-z0-9\-_.]+');
});

// =========================================================================
// INVIMA (datos.gov.co)
// =========================================================================
Route::prefix('invima')->group(function () {
    Route::get('buscar', [App\Http\Controllers\Inventory\Pharmacy\InvProductoController::class, 'searchInvima']);
    Route::get('validar/{code}', [App\Http\Controllers\Inventory\Pharmacy\InvProductoController::class, 'validateInvima']);
    Route::get('mvd', [App\Http\Controllers\Inventory\Pharmacy\InvProductoController::class, 'searchMvd']);
});

