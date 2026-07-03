<?php

use Illuminate\Support\Facades\Route;

// =========================================================================
// PRODUCTOS
// =========================================================================
Route::post('productos/bulk-validate', [App\Http\Controllers\Inventory\InvProductoController::class, 'validateBulkProducts']);
Route::apiResource('productos', App\Http\Controllers\Inventory\InvProductoController::class);

// Pedidos
Route::patch('pedidos/{pedido}/estado', [App\Http\Controllers\Inventory\InvPedidoController::class, 'cambiarEstado']);
Route::apiResource('pedidos', App\Http\Controllers\Inventory\InvPedidoController::class);

// Órdenes de Compra
Route::patch('ordenes-compra/{orden}/estado', [App\Http\Controllers\Inventory\InvOrdenCompraController::class, 'cambiarEstado']);
Route::apiResource('ordenes-compra', App\Http\Controllers\Inventory\InvOrdenCompraController::class);

// Recepciones Técnicas
Route::patch('recepciones/{recepcion}/confirmar', [App\Http\Controllers\Inventory\InvRecepcionController::class, 'confirmar']);
Route::apiResource('recepciones', App\Http\Controllers\Inventory\InvRecepcionController::class);

// =========================================================================
// INVIMA (datos.gov.co)
// =========================================================================
Route::prefix('invima')->group(function () {
    Route::get('buscar', [App\Http\Controllers\Inventory\InvProductoController::class, 'searchInvima']);
    Route::get('validar/{code}', [App\Http\Controllers\Inventory\InvProductoController::class, 'validateInvima']);
    Route::get('mvd', [App\Http\Controllers\Inventory\InvProductoController::class, 'searchMvd']);
});

// =========================================================================
// PEDIDOS
// =========================================================================
Route::apiResource('pedidos', App\Http\Controllers\Inventory\InvPedidoController::class);
Route::patch('pedidos/{id}/estado', [App\Http\Controllers\Inventory\InvPedidoController::class, 'cambiarEstado']);

// =========================================================================
// ÓRDENES DE COMPRA
// =========================================================================
Route::apiResource('ordenes-compra', App\Http\Controllers\Inventory\InvOrdenCompraController::class);
Route::patch('ordenes-compra/{id}/estado', [App\Http\Controllers\Inventory\InvOrdenCompraController::class, 'cambiarEstado']);

// =========================================================================
// RECEPCIONES TÉCNICAS
// =========================================================================
Route::apiResource('recepciones', App\Http\Controllers\Inventory\InvRecepcionController::class);
