<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting\FichasTecnicas;

use App\DTO\FichasTecnicas\CrearFichaDTO;
use App\Http\Requests\FichasTecnicas\StoreFichaRequest;
use App\Http\Requests\FichasTecnicas\UpdateFichaRequest;
use App\Services\Accounting\FichasTecnicas\FichAuditoriaService;
use App\Services\Accounting\FichasTecnicas\FichConflictoService;
use App\Services\Accounting\FichasTecnicas\FichFichaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD de fichas técnicas y consultas de bandeja.
 *
 * Reemplaza `generador/borradores.php`, `procesando.php`, `rechazados.php`,
 * `finalizadas.php`, `vencidas.php`, `form1..3.php` y `acciones/*.php`.
 */
class FichFichaController extends BaseFichasController
{
    public function __construct(
        private readonly FichFichaService $fichas,
        private readonly FichConflictoService $conflictos,
        private readonly FichAuditoriaService $auditoria,
    ) {
    }

    /**
     * Listado por bandeja: borradores, procesando, por-autorizar, por-aprobar,
     * rechazados, finalizadas, vencidas, proximas-vencer.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->ejecutar(
            fn (): JsonResponse => $this->paginado(
                $this->fichas->listar(
                    $request->all() + $this->contextoAlcance($request)
                )
            ),
            'Error al obtener el listado de fichas técnicas'
        );
    }

    public function show(int $id): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->fichas->obtener($id),
            'Error al obtener la ficha técnica'
        );
    }

    public function store(StoreFichaRequest $request): JsonResponse
    {
        return $this->ejecutar(function () use ($request) {
            $contexto = $this->contextoAlcance($request);

            $dto = CrearFichaDTO::fromArray(
                $request->validated() + [
                    'id_user_reg' => $this->usuarioId(),
                    'id_empresa'  => $request->input('id_empresa', $contexto['id_empresa'] ?? null),
                    'id_sucursal' => $request->input('id_sucursal', $contexto['id_sucursal'] ?? null),
                ]
            );

            $ficha = $this->fichas->crear($dto);

            // Las alertas RN-01 acompañan la respuesta: no bloquean, informan.
            return [
                'ficha'   => $ficha,
                'alertas' => $this->fichas->alertasPendientes(),
            ];
        }, 'Error al crear la ficha técnica', 201);
    }

    public function update(UpdateFichaRequest $request, int $id): JsonResponse
    {
        return $this->ejecutar(function () use ($request, $id) {
            $ficha = $this->fichas->actualizar($id, $request->validated(), $this->usuarioId());

            return [
                'ficha'   => $ficha,
                'alertas' => $this->fichas->alertasPendientes(),
            ];
        }, 'Error al actualizar la ficha técnica');
    }

    /** Cancelación lógica (legacy: estado 7 ELIMINADA). */
    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->fichas->cancelar($id, $this->usuarioId(), $request->input('motivo')),
            'Error al cancelar la ficha técnica'
        );
    }

    /** Crea una actualización (OS) a partir de una ficha finalizada. */
    public function crearActualizacion(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'obs_os'          => ['required', 'string', 'max:500'],
            'fecha_ini'       => ['nullable', 'date'],
            'fecha_fin'       => ['nullable', 'date', 'after_or_equal:fecha_ini'],
            'vlr_contrato'    => ['nullable', 'numeric', 'min:0'],
            'profesionales'   => ['nullable', 'array'],
            'profesionales.*' => ['integer', 'exists:fich_profesionales,id'],
            'detalles'        => ['nullable', 'array'],
        ]);

        return $this->ejecutar(
            fn () => $this->fichas->crearActualizacion($id, $request->all(), $this->usuarioId()),
            'Error al crear la actualización de la ficha',
            201
        );
    }

    /** Bitácora de cambios de estado (alimentada por trigger). */
    public function historial(int $id): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->auditoria->historial($id),
            'Error al obtener el historial de la ficha'
        );
    }

    /**
     * Verificación previa de conflictos de profesionales.
     *
     * Permite al frontend avisar antes de enviar el formulario, en lugar de
     * descubrirlo al guardar como ocurría en el legacy.
     */
    public function verificarConflictos(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'profesionales'   => ['required', 'array', 'min:1'],
            'profesionales.*' => ['integer'],
            'fecha_ini'       => ['required', 'date'],
            'fecha_fin'       => ['required', 'date', 'after_or_equal:fecha_ini'],
            'excluir_ficha'   => ['nullable', 'integer'],
            'id_agremiacion'  => ['nullable', 'integer', 'exists:fich_agremiaciones,id'],
        ]);

        return $this->ejecutar(function () use ($datos): JsonResponse {
            $resumen = $this->conflictos->resumen(
                $datos['profesionales'],
                $datos['fecha_ini'],
                $datos['fecha_fin'],
                isset($datos['excluir_ficha']) ? (int) $datos['excluir_ficha'] : null,
                isset($datos['id_agremiacion']) ? (int) $datos['id_agremiacion'] : null
            );

            return response()->json([
                'success' => true,
                // RN-02: hay bloqueos → no se puede continuar.
                'puede_continuar'  => $resumen['puede_continuar'],
                'tiene_conflictos' => $resumen['total'] > 0,
                'bloqueos'         => $resumen['bloqueos'],
                // RN-01: alertas informativas, la operación puede continuar.
                'alertas'          => $resumen['alertas'],
            ]);
        }, 'Error al verificar conflictos de profesionales');
    }

    /** Sincroniza los profesionales vinculados a la ficha. */
    public function sincronizarProfesionales(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate([
            'profesionales'   => ['present', 'array'],
            'profesionales.*' => ['integer', 'exists:fich_profesionales,id'],
        ]);

        return $this->ejecutar(function () use ($id, $datos) {
            $ficha = $this->fichas->sincronizarProfesionales($id, $datos['profesionales'], $this->usuarioId());

            return [
                'ficha'   => $ficha,
                'alertas' => $this->fichas->alertasPendientes(),
            ];
        }, 'Error al actualizar los profesionales de la ficha');
    }

    /**
     * Versiones de la ficha: original, actualizaciones y su estado.
     *
     * Permite ver la cadena completa de modificaciones con su vigencia, que es
     * la trazabilidad que el legacy solo insinuaba con `id_padre`.
     */
    public function versiones(int $id): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->fichas->cadenaDeVersiones($id),
            'Error al obtener las versiones de la ficha'
        );
    }
}
