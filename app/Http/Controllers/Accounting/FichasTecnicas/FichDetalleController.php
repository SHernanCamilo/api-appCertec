<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting\FichasTecnicas;

use App\DTO\FichasTecnicas\DetalleFichaDTO;
use App\Http\Requests\FichasTecnicas\StoreDetalleRequest;
use App\Services\Accounting\FichasTecnicas\FichFichaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Servicios/ítems y observaciones de una ficha (pasos 2 y 3 del generador).
 *
 * Reemplaza `generador/form2.php`, `form3.php` y sus acciones
 * (`insertar2.php`, `insertar3.php`, `eliminar*.php`).
 */
class FichDetalleController extends BaseFichasController
{
    public function __construct(private readonly FichFichaService $fichas)
    {
    }

    /** Detalles enriquecidos (CUPS, homólogo y observación ya resueltos). */
    public function index(int $idFicha): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->fichas->detallesEnriquecidos($idFicha),
            'Error al obtener los servicios de la ficha'
        );
    }

    /**
     * Crea uno o varios servicios.
     *
     * Acepta un objeto simple o `{ "items": [...] }` para guardar la tabla
     * completa en una sola petición.
     */
    public function store(StoreDetalleRequest $request, int $idFicha): JsonResponse
    {
        return $this->ejecutar(function () use ($request, $idFicha) {
            $dtos = DetalleFichaDTO::collection($request->items());

            return count($dtos) === 1
                ? $this->fichas->agregarDetalle($idFicha, $dtos[0], $this->usuarioId())
                : $this->fichas->agregarDetalles($idFicha, $dtos, $this->usuarioId());
        }, 'Error al agregar los servicios a la ficha', 201);
    }

    public function update(StoreDetalleRequest $request, int $idFicha, int $idDetalle): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->fichas->actualizarDetalle(
                $idDetalle,
                DetalleFichaDTO::fromArray($request->validated()),
                $this->usuarioId()
            ),
            'Error al actualizar el servicio'
        );
    }

    public function destroy(int $idFicha, int $idDetalle): JsonResponse
    {
        return $this->ejecutar(function () use ($idDetalle): JsonResponse {
            $this->fichas->eliminarDetalle($idDetalle, $this->usuarioId());

            return response()->json(['success' => true, 'message' => 'Servicio eliminado.']);
        }, 'Error al eliminar el servicio');
    }

    // ── Observaciones generales de la ficha ──────────────────────────────

    public function storeObservacion(Request $request, int $idFicha): JsonResponse
    {
        $datos = $request->validate([
            'desc_obs' => ['required', 'string', 'max:500'],
        ]);

        return $this->ejecutar(
            fn () => $this->fichas->agregarObservacion($idFicha, $datos['desc_obs'], $this->usuarioId()),
            'Error al agregar la observación',
            201
        );
    }

    public function destroyObservacion(int $idFicha, int $idObservacion): JsonResponse
    {
        return $this->ejecutar(function () use ($idObservacion): JsonResponse {
            $this->fichas->eliminarObservacion($idObservacion);

            return response()->json(['success' => true, 'message' => 'Observación eliminada.']);
        }, 'Error al eliminar la observación');
    }
}
