<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting\FichasTecnicas;

use App\Services\Accounting\FichasTecnicas\FichDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Indicadores del módulo.
 *
 * Reemplaza `generador/index.php`, `aprobador/index.php`,
 * `aprobador/stats.php` y `parametrizador/stats.php`, donde los KPIs se
 * calculaban con consultas independientes embebidas en el HTML.
 */
class FichDashboardController extends BaseFichasController
{
    public function __construct(private readonly FichDashboardService $dashboard)
    {
    }

    /** KPIs + alertas + gráficos en una sola respuesta. */
    public function index(Request $request): JsonResponse
    {
        return $this->ejecutar(function () use ($request) {
            $filtros = $request->all() + $this->contextoAlcance($request);

            return [
                'indicadores'      => $this->dashboard->indicadores($filtros),
                'proximas_vencer'  => $this->dashboard->proximasAVencer($filtros, (int) $request->input('limite', 10)),
                'por_especialidad' => $this->dashboard->porEspecialidad($filtros),
                'por_agremiacion'  => $this->dashboard->porAgremiacion($filtros),
            ];
        }, 'Error al obtener los indicadores del módulo');
    }

    public function indicadores(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->dashboard->indicadores($request->all() + $this->contextoAlcance($request)),
            'Error al obtener los indicadores'
        );
    }

    public function porSucursal(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->dashboard->porSucursal($request->all() + $this->contextoAlcance($request)),
            'Error al obtener el resumen por sucursal'
        );
    }

    public function proximasAVencer(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->dashboard->proximasAVencer(
                $request->all() + $this->contextoAlcance($request),
                (int) $request->input('limite', 10)
            ),
            'Error al obtener las fichas próximas a vencer'
        );
    }
}
