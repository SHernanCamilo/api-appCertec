<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting\FichasTecnicas;

use App\Http\Requests\FichasTecnicas\ValidacionFichaRequest;
use App\Models\Accounting\FichasTecnicas\FichFicha;
use App\Services\Accounting\FichasTecnicas\FichFichaService;
use App\Services\Accounting\FichasTecnicas\FichValidacionService;
use App\Services\Accounting\FichasTecnicas\FichVentanaEnvioService;
use App\Services\Accounting\FichasTecnicas\FichWorkflowBridge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Flujo de doble validación de fichas técnicas.
 *
 * Reemplaza `autorizador/validacion.php` + `insert_val.php` y
 * `aprobador/validacion.php` + `insert_aprob.php`.
 */
class FichValidacionController extends BaseFichasController
{
    public function __construct(
        private readonly FichValidacionService $validacion,
        private readonly FichVentanaEnvioService $ventana,
        private readonly FichWorkflowBridge $workflow,
        private readonly FichFichaService $fichas,
    ) {
    }

    /**
     * Endpoint unificado de validación.
     *
     * El estado destino NO se recibe del cliente: la acción es semántica
     * ('enviar'|'autorizar'|'aprobar'|'rechazar'|'reenviar') y el backend aplica
     * la transición que corresponda según el estado actual de la ficha.
     *
     * `POST /fichas/{id}/validar/{accion}`
     */
    public function procesar(ValidacionFichaRequest $request, int $id, string $accion): JsonResponse
    {
        $datos       = $request->validated();
        $observacion = trim((string) ($datos['observacion'] ?? ''));
        $consecutivo = $datos['consecutivo'] ?? null;
        $esAprobador = $this->tieneAlgunRol(auth('api')->user(), [self::ROL_APROBADOR]);

        return $this->ejecutar(
            fn () => match ($accion) {
                'enviar'    => $this->validacion->enviar($id, $this->usuarioId(), $observacion ?: null),
                'reenviar'  => $this->validacion->reenviar($id, $this->usuarioId(), $observacion ?: null),
                'autorizar' => $this->validacion->autorizar($id, $this->usuarioId(), $observacion),
                'aprobar'   => $this->validacion->aprobar($id, $this->usuarioId(), $observacion ?: null, $consecutivo),
                'rechazar'  => $this->validacion->rechazar($id, $this->usuarioId(), $observacion, $esAprobador),
                default     => throw new InvalidArgumentException("Acción no válida: {$accion}"),
            },
            'Error al procesar la validación de la ficha técnica'
        );
    }

    /**
     * El generador solicita modificar una ficha aprobada o vigente.
     *
     * Crea una nueva versión (OS) que recorre el flujo completo de aprobación.
     * La versión actual conserva su vigencia hasta que la nueva sea aprobada.
     *
     * `POST /fichas/{id}/solicitar-modificacion`
     */
    public function solicitarModificacion(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate([
            'motivo'             => ['required', 'string', 'max:500'],
            'fecha_ini'          => ['nullable', 'date'],
            'fecha_fin'          => ['nullable', 'date', 'after_or_equal:fecha_ini'],
            'vlr_contrato'       => ['nullable', 'numeric', 'min:0'],
            'id_agremiacion'     => ['nullable', 'integer', 'exists:fich_agremiaciones,id'],
            'id_objeto_contrato' => ['nullable', 'integer', 'exists:fich_objetos_contrato,id'],
            'id_especialidad'    => ['nullable', 'integer', 'exists:fich_especialidades,id'],
            'profesionales'      => ['nullable', 'array'],
            'profesionales.*'    => ['integer', 'exists:fich_profesionales,id'],
            'detalles'           => ['nullable', 'array'],
        ]);

        return $this->ejecutar(function () use ($datos, $id) {
            $nueva = $this->validacion->solicitarModificacion(
                $id,
                $this->usuarioId(),
                $datos['motivo'],
                $datos,
                $this->fichas
            );

            return [
                'ficha'    => $nueva,
                'alertas'  => $this->fichas->alertasPendientes(),
                'mensaje'  => sprintf(
                    'Se creó la versión %d de la ficha. Complete los ajustes y envíela a autorización '
                    .'para reiniciar el flujo. La versión vigente se mantiene activa hasta que la nueva sea aprobada.',
                    $nueva->version
                ),
            ];
        }, 'Error al solicitar la modificación de la ficha', 201);
    }

    /**
     * Consecutivo sugerido para el formulario de aprobación.
     */
    public function consecutivoSugerido(int $id): JsonResponse
    {
        return $this->ejecutar(
            fn (): JsonResponse => response()->json([
                'success'     => true,
                'consecutivo' => $this->validacion->consecutivoSugerido($id),
            ]),
            'Error al calcular el consecutivo sugerido'
        );
    }

    /**
     * Acciones habilitadas para el usuario autenticado sobre esta ficha.
     *
     * El frontend usa esta respuesta para decidir qué botones mostrar, en lugar
     * de replicar la matriz de estados y roles en TypeScript.
     */
    public function acciones(int $id): JsonResponse
    {
        return $this->ejecutar(function () use ($id) {
            $ficha = FichFicha::query()->findOrFail($id);
            $user  = auth('api')->user();

            $roles = $user !== null && method_exists($user, 'getRoleNames')
                ? $user->getRoleNames()->all()
                : [];

            return [
                'estado'         => $ficha->estadoEnum()->value,
                'estado_label'   => $ficha->estadoEnum()->label(),
                'acciones'       => $this->validacion->accionesDisponibles($ficha, $roles, $this->usuarioId()),
                'workflow'       => $this->workflow->trazabilidad($ficha),
            ];
        }, 'Error al obtener las acciones disponibles');
    }

    /**
     * Estado de la ventana de envío (RN-03).
     *
     * Permite al frontend deshabilitar el botón con el mismo criterio que valida
     * el backend, sin duplicar la regla del día 21.
     */
    public function ventanaEnvio(): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->ventana->estado(),
            'Error al consultar la ventana de envío'
        );
    }

    /**
     * Trazabilidad del flujo: todos los ciclos de aprobación de la ficha.
     */
    public function trazabilidad(int $id): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->workflow->trazabilidad(FichFicha::query()->findOrFail($id)),
            'Error al obtener la trazabilidad del flujo'
        );
    }
}
