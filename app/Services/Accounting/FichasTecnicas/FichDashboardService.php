<?php

declare(strict_types=1);

namespace App\Services\Accounting\FichasTecnicas;

use App\Enums\FichasTecnicas\EstadoFicha;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores del módulo Fichas Técnicas.
 *
 * Refactor respecto al legacy: `config_finalizadas.php::stats()` construía
 * DIEZ subconsultas `LEFT JOIN (SELECT COUNT(*) ... GROUP BY sucursal)` sobre
 * la misma tabla `ficha`, es decir diez recorridos completos para obtener
 * conteos por estado. Aquí se consulta la vista `v_fich_dashboard_sucursal`,
 * que resuelve todo con un único `GROUP BY`.
 */
final class FichDashboardService
{
    /**
     * KPIs agregados.
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, int|float>
     */
    public function indicadores(array $filtros = []): array
    {
        $query = DB::table('v_fich_dashboard_sucursal');

        $this->aplicarAlcance($query, $filtros);

        $fila = $query->selectRaw('
            COALESCE(SUM(total), 0)                    AS total,
            COALESCE(SUM(borradores), 0)               AS borradores,
            COALESCE(SUM(pendientes_autorizacion), 0)  AS pendientes_autorizacion,
            COALESCE(SUM(pendientes_financiera), 0)    AS pendientes_financiera,
            COALESCE(SUM(en_proceso), 0)               AS en_proceso,
            COALESCE(SUM(por_aprobar), 0)              AS por_aprobar,
            COALESCE(SUM(rechazadas), 0)               AS rechazadas,
            COALESCE(SUM(aprobadas), 0)                AS aprobadas,
            COALESCE(SUM(en_vigencia), 0)              AS en_vigencia,
            COALESCE(SUM(finalizadas), 0)              AS finalizadas,
            COALESCE(SUM(canceladas), 0)               AS canceladas,
            COALESCE(SUM(vigentes), 0)                 AS vigentes,
            COALESCE(SUM(vencidas), 0)                 AS vencidas,
            COALESCE(SUM(proximas_vencer), 0)          AS proximas_vencer,
            COALESCE(SUM(valor_contratado), 0)         AS valor_contratado
        ')->first();

        return [
            'total'                   => (int) ($fila->total ?? 0),
            'borradores'              => (int) ($fila->borradores ?? 0),
            'pendientes_autorizacion' => (int) ($fila->pendientes_autorizacion ?? 0),
            'pendientes_financiera'   => (int) ($fila->pendientes_financiera ?? 0),
            'en_proceso'              => (int) ($fila->en_proceso ?? 0),
            'por_aprobar'             => (int) ($fila->por_aprobar ?? 0),
            // Devoluciones pendientes de corrección por el generador.
            'rechazadas'              => (int) ($fila->rechazadas ?? 0),
            'correccion_requerida'    => (int) ($fila->rechazadas ?? 0),
            'aprobadas'               => (int) ($fila->aprobadas ?? 0),
            'en_vigencia'             => (int) ($fila->en_vigencia ?? 0),
            'finalizadas'             => (int) ($fila->finalizadas ?? 0),
            'canceladas'              => (int) ($fila->canceladas ?? 0),
            'vigentes'                => (int) ($fila->vigentes ?? 0),
            'vencidas'                => (int) ($fila->vencidas ?? 0),
            'proximas_vencer'         => (int) ($fila->proximas_vencer ?? 0),
            'valor_contratado'        => (float) ($fila->valor_contratado ?? 0),
        ];
    }

    /**
     * Desglose por sucursal (tabla del dashboard gerencial).
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, object>
     */
    public function porSucursal(array $filtros = []): Collection
    {
        $query = DB::table('v_fich_dashboard_sucursal');
        $this->aplicarAlcance($query, $filtros);

        return $query->orderBy('sucursal_legacy')->get();
    }

    /**
     * Fichas próximas a vencer, con color de alerta ya resuelto en SQL.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, object>
     */
    public function proximasAVencer(array $filtros = [], int $limite = 10): Collection
    {
        $query = DB::table('v_fich_proximos_vencer');

        if (! empty($filtros['id_empresa'])) {
            $query->where('id_empresa', (int) $filtros['id_empresa']);
        }

        if (! empty($filtros['id_sucursal'])) {
            $query->where('id_sucursal', (int) $filtros['id_sucursal']);
        } elseif (! empty($filtros['sucursal_legacy'])) {
            $query->where('sucursal_legacy', (string) $filtros['sucursal_legacy']);
        }

        if (! empty($filtros['solo_propias']) && ! empty($filtros['user_id'])) {
            $query->where('id_user_reg', (int) $filtros['user_id']);
        }

        return $query->orderBy('fecha_fin')->limit(min(max($limite, 1), 100))->get();
    }

    /**
     * Distribución del valor contratado por especialidad (gráfico gerencial).
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, object>
     */
    public function porEspecialidad(array $filtros = [], int $limite = 15): Collection
    {
        $query = DB::table('v_fich_fichas_listado')
            ->whereIn('estado_codigo', EstadoFicha::codigos(EstadoFicha::finalizadas()));

        if (! empty($filtros['id_empresa'])) {
            $query->where('id_empresa', (int) $filtros['id_empresa']);
        }

        if (! empty($filtros['id_sucursal'])) {
            $query->where('id_sucursal', (int) $filtros['id_sucursal']);
        }

        return $query
            ->selectRaw('especialidad_descripcion, COUNT(*) AS total, COALESCE(SUM(vlr_contrato), 0) AS valor')
            ->groupBy('especialidad_descripcion')
            ->orderByDesc('valor')
            ->limit($limite)
            ->get();
    }

    /**
     * Top de agremiaciones por valor contratado.
     *
     * @param  array<string, mixed>  $filtros
     * @return Collection<int, object>
     */
    public function porAgremiacion(array $filtros = [], int $limite = 10): Collection
    {
        $query = DB::table('v_fich_fichas_listado')
            ->whereIn('estado_codigo', EstadoFicha::codigos(EstadoFicha::finalizadas()));

        if (! empty($filtros['id_empresa'])) {
            $query->where('id_empresa', (int) $filtros['id_empresa']);
        }

        if (! empty($filtros['id_sucursal'])) {
            $query->where('id_sucursal', (int) $filtros['id_sucursal']);
        }

        return $query
            ->selectRaw('agremiacion_nombre, COUNT(*) AS total, COALESCE(SUM(vlr_contrato), 0) AS valor')
            ->groupBy('agremiacion_nombre')
            ->orderByDesc('valor')
            ->limit($limite)
            ->get();
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array<string, mixed>  $filtros
     */
    private function aplicarAlcance($query, array $filtros): void
    {
        if (! empty($filtros['id_empresa'])) {
            $query->where('id_empresa', (int) $filtros['id_empresa']);
        }

        if (! empty($filtros['id_sucursal'])) {
            $query->where('id_sucursal', (int) $filtros['id_sucursal']);
        } elseif (! empty($filtros['sucursal_legacy'])) {
            $query->where('sucursal_legacy', (string) $filtros['sucursal_legacy']);
        }
    }
}
