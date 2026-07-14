<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

// =============================================================================
// OData Link — Redirect para compatibilidad con links antiguos generados sin /api/fabric
// Si algún link viejo usa /odata/link/{code}, redirigir a la URL correcta.
// =============================================================================
Route::prefix('odata/link/{code}')->where(['code' => '[a-f0-9]{32}'])->group(function () {
    Route::get('/{any?}', function ($code, $any = '') {
        $query = request()->getQueryString();
        $redirect = "/api/fabric/odata/link/{$code}" . ($any ? "/{$any}" : '') . ($query ? "?{$query}" : '');
        return redirect($redirect, 301);
    })->where('any', '.*');
});
