<?php

declare(strict_types=1);

use App\Http\Controllers\Accounting\FichasTecnicas\FichCupsController;
use App\Http\Controllers\Accounting\FichasTecnicas\FichDashboardController;
use App\Http\Controllers\Accounting\FichasTecnicas\FichDetalleController;
use App\Http\Controllers\Accounting\FichasTecnicas\FichFichaController;
use App\Http\Controllers\Accounting\FichasTecnicas\FichParametroController;
use App\Http\Controllers\Accounting\FichasTecnicas\FichPdfController;
use App\Http\Controllers\Accounting\FichasTecnicas\FichValidacionController;
use Illuminate\Support\Facades\Route;

/**
 * Rutas del módulo Fichas Técnicas Médicas (Contabilidad).
 *
 * Se monta bajo el prefijo `api/fichas-tecnicas` con `auth:api` +
 * `check.user.active` aplicados desde `routes/api.php`.
 *
 * Sustituye la navegación por archivos del sistema JADE legacy, donde cada
 * pantalla era un .php con su propia consulta y control de acceso por
 * comparación de cadenas sobre `$_SESSION['rol']`.
 */

// ── Dashboard e indicadores ─────────────────────────────────────────────
Route::prefix('dashboard')->group(function (): void {
    Route::get('/',                [FichDashboardController::class, 'index']);
    Route::get('/indicadores',     [FichDashboardController::class, 'indicadores']);
    Route::get('/por-sucursal',    [FichDashboardController::class, 'porSucursal']);
    Route::get('/proximas-vencer', [FichDashboardController::class, 'proximasAVencer']);
});

// ── Catálogos y cascadas de formulario ──────────────────────────────────
// Deben declararse antes de `fichas/{id}` para no ser capturadas por el patrón.
Route::prefix('parametros')->group(function (): void {
    Route::get('/opciones', [FichParametroController::class, 'opciones']);

    Route::get('/especialidades/{idEspecialidad}/profesionales', [FichParametroController::class, 'profesionalesPorEspecialidad'])
        ->whereNumber('idEspecialidad');
    Route::get('/tipos-servicio/{idTipoServicio}/observaciones', [FichParametroController::class, 'observacionesPorTipoServicio'])
        ->whereNumber('idTipoServicio');

    Route::post('/profesionales/{idProfesional}/especialidades', [FichParametroController::class, 'asignarEspecialidades'])
        ->whereNumber('idProfesional');
    Route::post('/obs-items/{idObsItem}/tipos-servicio', [FichParametroController::class, 'asignarTiposServicio'])
        ->whereNumber('idObsItem');

    // CRUD genérico: agremiaciones | profesionales | especialidades |
    // tipos-servicio | objetos-contrato | obs-items | homologos
    $catalogos = 'agremiaciones|profesionales|especialidades|tipos-servicio|objetos-contrato|obs-items|homologos';

    Route::get('/{catalogo}',                    [FichParametroController::class, 'index'])->where('catalogo', $catalogos);
    Route::post('/{catalogo}',                   [FichParametroController::class, 'store'])->where('catalogo', $catalogos);
    Route::put('/{catalogo}/{id}',               [FichParametroController::class, 'update'])->where('catalogo', $catalogos)->whereNumber('id');
    Route::patch('/{catalogo}/{id}/estado',      [FichParametroController::class, 'cambiarEstado'])->where('catalogo', $catalogos)->whereNumber('id');
});

// ── Tarifarios: CUPS, homólogos y SOAT ──────────────────────────────────
Route::prefix('cups')->group(function (): void {
    Route::get('/',              [FichCupsController::class, 'buscarCups']);
    Route::get('/autocompletar', [FichCupsController::class, 'autocompletarCups']);
    Route::get('/grupos',        [FichCupsController::class, 'grupos']);
    Route::get('/subgrupos',     [FichCupsController::class, 'subgrupos']);
    Route::get('/{codeCups}/homologos', [FichCupsController::class, 'homologosDeCups'])
        ->where('codeCups', '[0-9A-Za-z\.\-]{1,10}');
    Route::get('/{cups}/fichas', [FichCupsController::class, 'fichasPorCups'])
        ->where('cups', '[0-9A-Za-z\.\-]{1,10}');
});

Route::prefix('homologos')->group(function (): void {
    Route::get('/',              [FichCupsController::class, 'buscarHomologos']);
    Route::get('/autocompletar', [FichCupsController::class, 'autocompletarHomologos']);
});

Route::get('tarifarios/{manual}', [FichCupsController::class, 'tarifario'])
    ->where('manual', 'iss|soat|institucional');

Route::prefix('soat')->group(function (): void {
    Route::get('/',           [FichCupsController::class, 'buscarSoat']);
    Route::get('/vigencias',  [FichCupsController::class, 'vigenciasSoat']);
});

// ── Verificación previa de conflictos de profesionales ──────────────────
// RN-01 devuelve alertas informativas; RN-02 devuelve bloqueos.
Route::post('fichas/verificar-conflictos', [FichFichaController::class, 'verificarConflictos']);

// ── RN-03: estado de la ventana de envío a autorización ─────────────────
// El frontend la consulta para deshabilitar el botón con el mismo criterio
// que valida el backend, en lugar de replicar la regla del día 21.
Route::get('flujo/ventana-envio', [FichValidacionController::class, 'ventanaEnvio']);

// ── Fichas técnicas ─────────────────────────────────────────────────────
Route::prefix('fichas')->group(function (): void {
    Route::get('/',      [FichFichaController::class, 'index']);
    Route::post('/',     [FichFichaController::class, 'store']);

    Route::prefix('{id}')->whereNumber('id')->group(function (): void {
        Route::get('/',    [FichFichaController::class, 'show']);
        Route::put('/',    [FichFichaController::class, 'update']);
        Route::delete('/', [FichFichaController::class, 'destroy']);

        Route::get('/historial', [FichFichaController::class, 'historial']);
        Route::get('/versiones', [FichFichaController::class, 'versiones']);
        Route::put('/profesionales', [FichFichaController::class, 'sincronizarProfesionales']);

        // Servicios / ítems (paso 2)
        Route::get('/detalles',                [FichDetalleController::class, 'index']);
        Route::post('/detalles',               [FichDetalleController::class, 'store']);
        Route::put('/detalles/{idDetalle}',    [FichDetalleController::class, 'update'])->whereNumber('idDetalle');
        Route::delete('/detalles/{idDetalle}', [FichDetalleController::class, 'destroy'])->whereNumber('idDetalle');

        // Observaciones generales (paso 3)
        Route::post('/observaciones',                     [FichDetalleController::class, 'storeObservacion']);
        Route::delete('/observaciones/{idObservacion}',    [FichDetalleController::class, 'destroyObservacion'])->whereNumber('idObservacion');

        // Flujo de doble validación — endpoint unificado.
        // El estado destino lo resuelve el backend según el estado actual.
        Route::post('/validar/{accion}', [FichValidacionController::class, 'procesar'])
            ->where('accion', 'enviar|autorizar|aprobar|rechazar|reenviar');

        Route::get('/consecutivo-sugerido', [FichValidacionController::class, 'consecutivoSugerido']);

        // Acciones habilitadas + trazabilidad del flujo para el usuario actual.
        Route::get('/acciones',     [FichValidacionController::class, 'acciones']);
        Route::get('/trazabilidad', [FichValidacionController::class, 'trazabilidad']);

        // Modificación de una ficha aprobada/vigente: crea nueva versión (OS)
        // y reinicia el flujo de aprobación.
        Route::post('/solicitar-modificacion', [FichValidacionController::class, 'solicitarModificacion']);

        // Actualizaciones (OS) — creación directa
        Route::post('/actualizaciones', [FichFichaController::class, 'crearActualizacion']);

        // PDF
        Route::get('/pdf',         [FichPdfController::class, 'generar']);
        Route::get('/pdf/base64',  [FichPdfController::class, 'base64']);
        Route::get('/pdf/preview', [FichPdfController::class, 'preview']);
    });
});
