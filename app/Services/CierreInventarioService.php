<?php

namespace App\Services;

use App\Models\MatrizObsolescencia\MatzobsCierre;
use App\Models\MatrizObsolescencia\MatzobsCierreDetalle;
use App\Models\MatrizObsolescencia\MatzobsCierreConfig;
use App\Models\MatrizObsActivoC;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service: CierreInventarioService
 *
 * Contiene toda la lógica de negocio del proceso de cierre de inventario.
 * El Job lo llama con $service->ejecutar($cierreId).
 * El Controller también puede llamarlo directamente (con queue=sync).
 */
class CierreInventarioService
{
    // Tamaño del lote para inserts masivos (evita queries enormes)
    private const BATCH_SIZE = 200;

    public function __construct(
        private readonly MatrizObsolescenciaCalculatorService $calculator
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // PUNTO DE ENTRADA PRINCIPAL
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Ejecuta el cierre completo para el ID dado.
     * Actualiza el estado del cierre en cada etapa.
     */
    public function ejecutar(int $cierreId): MatzobsCierre
    {
        $cierre = MatzobsCierre::findOrFail($cierreId);

        // Sólo se puede ejecutar un cierre en estado pendiente
        if ($cierre->estado !== 'pendiente') {
            throw new \RuntimeException(
                "El cierre #{$cierreId} no está en estado 'pendiente' (estado actual: {$cierre->estado})"
            );
        }

        $inicio = now();

        try {
            // ── 1. Marcar como procesando ─────────────────────────────────────
            $cierre->update([
                'estado'               => 'procesando',
                'fecha_inicio_proceso' => $inicio,
                'mensaje_error'        => null,
            ]);

            // ── 2. Leer configuración ─────────────────────────────────────────
            $config = MatzobsCierreConfig::config();

            // ── 3. Recalcular puntajes si la config lo indica ─────────────────
            if ($config->recalcular_antes_de_cerrar) {
                $this->recalcularPuntajes();
            }

            // ── 4. Obtener activos a incluir ──────────────────────────────────
            $activos = $this->obtenerActivos($config);

            // ── 5. Generar snapshot en lotes ──────────────────────────────────
            $resumen = $this->generarSnapshot($cierre->id, $activos);

            // ── 6. Actualizar cabecera con resumen ────────────────────────────
            $fin = now();
            $cierre->update([
                'estado'                     => 'cerrado',
                'fecha_fin_proceso'          => $fin,
                'duracion_segundos'          => $fin->diffInSeconds($inicio),
                'total_activos'              => $resumen['total'],
                'total_optimo'               => $resumen['optimo'],
                'total_funcional'            => $resumen['funcional'],
                'total_potencial'            => $resumen['potencial'],
                'total_obsoleto'             => $resumen['obsoleto'],
                'puntaje_promedio'           => $resumen['puntaje_promedio'],
                'config_recalculo_aplicado'  => $config->recalcular_antes_de_cerrar,
                'config_incluyo_sin_puntaje' => $config->incluir_sin_puntaje,
                'config_incluyo_inactivos'   => $config->incluir_inactivos,
            ]);

            // ── 7. Limpiar cierres antiguos si hay límite ─────────────────────
            if ($config->max_cierres_a_conservar > 0) {
                $this->limpiarCierresAntiguos($config->max_cierres_a_conservar);
            }

            Log::channel('glpi_sync')->info('Cierre de inventario completado', [
                'cierre_id'        => $cierre->id,
                'total_activos'    => $resumen['total'],
                'puntaje_promedio' => $resumen['puntaje_promedio'],
                'duracion_seg'     => $fin->diffInSeconds($inicio),
            ]);

            return $cierre->fresh();

        } catch (\Throwable $e) {
            // Marcar como error y relanzar para que el Job lo registre
            $cierre->update([
                'estado'            => 'error',
                'fecha_fin_proceso' => now(),
                'duracion_segundos' => now()->diffInSeconds($inicio),
                'mensaje_error'     => $e->getMessage(),
            ]);

            Log::error('Error en CierreInventarioService::ejecutar', [
                'cierre_id' => $cierreId,
                'error'     => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PASOS INTERNOS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Recalcula puntajes de todos los activos usando el servicio existente.
     */
    private function recalcularPuntajes(): void
    {
        $ids = MatrizObsActivoC::pluck('id')->toArray();

        if (empty($ids)) return;

        $this->calculator->calcularValoresLote($ids, 50);

        Log::channel('glpi_sync')->info('Recálculo previo al cierre completado', [
            'total_activos' => count($ids),
        ]);
    }

    /**
     * Obtiene la colección de activos a incluir en el cierre
     * según la configuración activa.
     */
    private function obtenerActivos(MatzobsCierreConfig $config): \Illuminate\Database\Eloquent\Collection
    {
        $query = MatrizObsActivoC::with([
            'detalle',
            'empresa:id,nombre',
            'sucursal:id,nombre',
            'sede:id,nombre',
        ]);

        // Excluir inactivos si la config lo indica
        if (!$config->incluir_inactivos) {
            // La columna 'estado' existe en matzobs_activos_c
            $query->where(function ($q) {
                $q->where('estado', true)
                  ->orWhereNull('estado'); // compatibilidad con registros sin estado
            });
        }

        // Excluir activos sin puntaje si la config lo indica
        if (!$config->incluir_sin_puntaje) {
            $query->where('puntaje', '>', 0)->whereNotNull('puntaje');
        }

        return $query->get();
    }

    /**
     * Inserta el snapshot de activos en matzobs_cierre_detalle por lotes.
     * Devuelve el resumen estadístico.
     */
    private function generarSnapshot(int $cierreId, \Illuminate\Database\Eloquent\Collection $activos): array
    {
        $resumen = [
            'total'           => 0,
            'optimo'          => 0,
            'funcional'       => 0,
            'potencial'       => 0,
            'obsoleto'        => 0,
            'suma_puntajes'   => 0.0,
            'puntaje_promedio' => 0.0,
        ];

        $lote = [];

        foreach ($activos as $activo) {
            $fila = MatzobsCierreDetalle::fromActivo($cierreId, $activo, $activo->detalle);

            // Acumular resumen
            $resumen['total']++;
            $resumen['suma_puntajes'] += (float) ($activo->puntaje ?? 0);
            $resumen[$fila['estado_obsolescencia']]++;

            $lote[] = $fila;

            // Insertar cuando el lote alcanza el tamaño máximo
            if (count($lote) >= self::BATCH_SIZE) {
                DB::table('matzobs_cierre_detalle')->insert($lote);
                $lote = [];
            }
        }

        // Insertar el último lote (puede ser < BATCH_SIZE)
        if (!empty($lote)) {
            DB::table('matzobs_cierre_detalle')->insert($lote);
        }

        // Calcular promedio
        if ($resumen['total'] > 0) {
            $resumen['puntaje_promedio'] = round($resumen['suma_puntajes'] / $resumen['total'], 2);
        }

        return $resumen;
    }

    /**
     * Elimina los cierres más antiguos si se supera el límite configurado.
     * Solo elimina cierres en estado 'cerrado' (nunca los que están en error).
     */
    private function limpiarCierresAntiguos(int $maxConservar): void
    {
        $totalCerrados = MatzobsCierre::cerrados()->count();

        if ($totalCerrados <= $maxConservar) return;

        $aEliminar = $totalCerrados - $maxConservar;

        $idsAEliminar = MatzobsCierre::cerrados()
            ->orderBy('created_at', 'asc')
            ->limit($aEliminar)
            ->pluck('id');

        if ($idsAEliminar->isEmpty()) return;

        // El cascade en la migración elimina también matzobs_cierre_detalle
        MatzobsCierre::whereIn('id', $idsAEliminar)->delete();

        Log::channel('glpi_sync')->info('Cierres antiguos eliminados', [
            'eliminados' => $idsAEliminar->count(),
            'ids'        => $idsAEliminar->toArray(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MÉTODOS PÚBLICOS DE CONSULTA (usados por el Controller)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Devuelve el resumen comparativo entre dos cierres.
     * Útil para la vista de comparación histórica.
     */
    public function compararCierres(int $cierreIdA, int $cierreIdB): array
    {
        $a = MatzobsCierre::findOrFail($cierreIdA);
        $b = MatzobsCierre::findOrFail($cierreIdB);

        $diff = fn(int $va, int $vb): array => [
            'anterior' => $va,
            'actual'   => $vb,
            'delta'    => $vb - $va,
            'pct'      => $va > 0 ? round((($vb - $va) / $va) * 100, 1) : null,
        ];

        return [
            'cierre_anterior' => $a->only(['id', 'nombre', 'periodo', 'created_at', 'puntaje_promedio']),
            'cierre_actual'   => $b->only(['id', 'nombre', 'periodo', 'created_at', 'puntaje_promedio']),
            'comparacion'     => [
                'total_activos'   => $diff($a->total_activos,  $b->total_activos),
                'total_optimo'    => $diff($a->total_optimo,   $b->total_optimo),
                'total_funcional' => $diff($a->total_funcional,$b->total_funcional),
                'total_potencial' => $diff($a->total_potencial,$b->total_potencial),
                'total_obsoleto'  => $diff($a->total_obsoleto, $b->total_obsoleto),
                'puntaje_promedio'=> $diff(
                    (int) round($a->puntaje_promedio),
                    (int) round($b->puntaje_promedio)
                ),
            ],
        ];
    }

    /**
     * Devuelve estadísticas del detalle de un cierre agrupadas por empresa.
     */
    public function resumenPorEmpresa(int $cierreId): array
    {
        return DB::table('matzobs_cierre_detalle')
            ->where('cierre_id', $cierreId)
            ->select(
                'id_empresa',
                'nombre_empresa',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN estado_obsolescencia = 'optimo'    THEN 1 ELSE 0 END) as optimo"),
                DB::raw("SUM(CASE WHEN estado_obsolescencia = 'funcional' THEN 1 ELSE 0 END) as funcional"),
                DB::raw("SUM(CASE WHEN estado_obsolescencia = 'potencial' THEN 1 ELSE 0 END) as potencial"),
                DB::raw("SUM(CASE WHEN estado_obsolescencia = 'obsoleto'  THEN 1 ELSE 0 END) as obsoleto"),
                DB::raw('ROUND(AVG(puntaje), 2) as puntaje_promedio')
            )
            ->groupBy('id_empresa', 'nombre_empresa')
            ->orderByDesc('total')
            ->get()
            ->toArray();
    }
}
