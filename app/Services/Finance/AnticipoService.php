<?php

namespace App\Services\Finance;

use App\Models\AntiTipo;
use App\Models\AntiClase;
use App\Models\AntiModalidad;
use App\Models\AntiConcepto;
use App\Models\AntiRegla;
use App\Models\Empleado;
use App\Models\Finance\AntiSolicitud;
use App\Models\Finance\AntiSolicitudItem;
use App\Models\Finance\AntiCiudad;
use App\Models\Workflow\WfInstancia;
use App\Services\Workflow\WorkflowResolver;
use App\Services\Workflow\WorkflowExecutor;
use App\Services\Workflow\WorkflowNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio consolidado de Anticipos.
 *
 * Responsabilidades:
 *   - Gestión de catálogos (Tipos, Clases, Modalidades, Conceptos, Reglas)
 *   - Cálculo de topes según nivel jerárquico y tipo de ciudad
 *   - Creación y gestión de solicitudes
 *   - Integración con motor de flujos
 *   - Legalización y reintegros
 */
class AnticipoService
{
    public function __construct(
        private WorkflowResolver $workflowResolver,
        private WorkflowExecutor $workflowExecutor,
        private WorkflowNotifier $workflowNotifier,
    ) {}

    // ========================================================================
    // CATÁLOGOS
    // ========================================================================

    /**
     * Obtiene todos los tipos de anticipo activos.
     */
    public function obtenerTipos(): \Illuminate\Support\Collection
    {
        return AntiTipo::activos()->orderBy('nombre')->get();
    }

    /**
     * Obtiene clases por tipo.
     */
    public function obtenerClasesPorTipo(int $idTipo): \Illuminate\Support\Collection
    {
        return AntiClase::where('id_tipo', $idTipo)->activos()->orderBy('nombre')->get();
    }

    /**
     * Obtiene modalidades por clase.
     */
    public function obtenerModalidadesPorClase(int $idClase): \Illuminate\Support\Collection
    {
        return AntiModalidad::where('id_clase', $idClase)->activos()->orderBy('nombre')->get();
    }

    /**
     * Obtiene conceptos por modalidad.
     */
    public function obtenerConceptosPorModalidad(int $idModalidad): \Illuminate\Support\Collection
    {
        return AntiConcepto::where('id_modalidad', $idModalidad)
            ->activos()
            ->with(['modalidad.clase.tipo'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Obtiene reglas de un concepto para un nivel jerárquico específico.
     */
    public function obtenerReglasPorConcepto(int $idConcepto, int $nivelJerarquico): \Illuminate\Support\Collection
    {
        return AntiRegla::where('id_concepto', $idConcepto)
            ->paraNivel($nivelJerarquico)
            ->activos()
            ->get();
    }

    // ========================================================================
    // CÁLCULO DE TOPES
    // ========================================================================

    /**
     * Calcula los topes de anticipo para un empleado y destino.
     *
     * @param int $idEmpleado
     * @param int $idCiudadDestino
     * @param string $fechaSalida
     * @param string $fechaRegreso
     *
     * @return array [
     *   'alimentacion_diario' => float,
     *   'alimentacion_total' => float,
     *   'transporte_diario' => float,
     *   'transporte_total' => float,
     *   'total' => float,
     *   'dias' => int,
     *   'nivel_jerarquico' => int,
     *   'tipo_ciudad' => string,
     *   'items' => array
     * ]
     */
    public function calcularTopes(
        int $idEmpleado,
        int $idCiudadDestino,
        string $fechaSalida,
        string $fechaRegreso
    ): array {
        $empleado = Empleado::with('cargoRelacion')->findOrFail($idEmpleado);
        $ciudad = AntiCiudad::findOrFail($idCiudadDestino);
        
        $nivel = $empleado->getNivelJerarquico();
        $dias = $this->calcularDias($fechaSalida, $fechaRegreso);

        // Obtener reglas de alimentación (concepto ID 1, ajustar según tu BD)
        $reglasAlimentacion = AntiRegla::where('id_concepto', 1)
            ->paraNivel($nivel)
            ->activos()
            ->get();

        $topeAlimentacionDiario = $reglasAlimentacion->sum('valor_tope');

        // Obtener regla de transporte según tipo de ciudad (concepto ID 2)
        // Las reglas de transporte tienen nivel=0 y descripción "Transporte Tipo A/B/C"
        $reglaTransporte = AntiRegla::where('id_concepto', 2)
            ->where('descripcion', 'like', "%Tipo {$ciudad->tipo_ciudad}%")
            ->activos()
            ->first();

        $topeTransporteDiario = $reglaTransporte ? $reglaTransporte->valor_tope : 0;

        // Construir detalle de items
        $items = [];
        
        foreach ($reglasAlimentacion as $regla) {
            $items[] = [
                'id_concepto' => $regla->id_concepto,
                'id_regla' => $regla->id,
                'descripcion' => $regla->descripcion,
                'cantidad' => $dias,
                'valor_unitario' => $regla->valor_tope,
                'valor_total' => $regla->valor_tope * $dias,
            ];
        }

        if ($reglaTransporte) {
            $items[] = [
                'id_concepto' => $reglaTransporte->id_concepto,
                'id_regla' => $reglaTransporte->id,
                'descripcion' => "Transporte Interno - Ciudad Tipo {$ciudad->tipo_ciudad}",
                'cantidad' => $dias,
                'valor_unitario' => $reglaTransporte->valor_tope,
                'valor_total' => $reglaTransporte->valor_tope * $dias,
            ];
        }

        return [
            'alimentacion_diario' => $topeAlimentacionDiario,
            'alimentacion_total' => $topeAlimentacionDiario * $dias,
            'transporte_diario' => $topeTransporteDiario,
            'transporte_total' => $topeTransporteDiario * $dias,
            'total' => ($topeAlimentacionDiario + $topeTransporteDiario) * $dias,
            'dias' => $dias,
            'nivel_jerarquico' => $nivel,
            'tipo_ciudad' => $ciudad->tipo_ciudad,
            'items' => $items,
        ];
    }

    private function calcularDias(string $fechaSalida, string $fechaRegreso): int
    {
        $salida = new \DateTime($fechaSalida);
        $regreso = new \DateTime($fechaRegreso);
        $diff = $salida->diff($regreso);
        return max(1, $diff->days + 1); // Mínimo 1 día
    }

    // ========================================================================
    // GESTIÓN DE SOLICITUDES
    // ========================================================================

    /**
     * Crea una nueva solicitud de anticipo.
     *
     * @param array $data [
     *   'id_empleado' => int,
     *   'id_unidad_funcional' => int,
     *   'id_sede_origen' => int,
     *   'id_ciudad_destino' => int,
     *   'fecha_salida' => string,
     *   'fecha_regreso' => string,
     *   'motivo' => string,
     *   'cobertura' => string (nacional|internacional),
     *   'radicado_por' => int (user_id),
     * ]
     *
     * @return AntiSolicitud
     */
    public function crearSolicitud(array $data): AntiSolicitud
    {
        return DB::transaction(function () use ($data) {
            $empleado = Empleado::with(['cargoRelacion', 'empresa'])->findOrFail($data['id_empleado']);

            // Calcular topes
            $topes = $this->calcularTopes(
                $data['id_empleado'],
                $data['id_ciudad_destino'],
                $data['fecha_salida'],
                $data['fecha_regreso']
            );

            // Generar número de solicitud
            $numeroSolicitud = $this->generarNumeroSolicitud();

            // Crear solicitud
            $solicitud = AntiSolicitud::create([
                'numero_solicitud' => $numeroSolicitud,
                'id_empleado' => $data['id_empleado'],
                'id_unidad_funcional' => $data['id_unidad_funcional'],
                'id_sede_origen' => $data['id_sede_origen'],
                'id_ciudad_destino' => $data['id_ciudad_destino'],
                'fecha_salida' => $data['fecha_salida'],
                'fecha_regreso' => $data['fecha_regreso'],
                'motivo' => $data['motivo'],
                'cobertura' => $data['cobertura'],
                'monto_solicitado' => $topes['total'],
                'radicado_por' => $data['radicado_por'],
                'estado' => 'borrador',
            ]);

            // Crear items
            foreach ($topes['items'] as $item) {
                AntiSolicitudItem::create([
                    'id_solicitud' => $solicitud->id,
                    'id_concepto' => $item['id_concepto'],
                    'id_regla' => $item['id_regla'],
                    'descripcion' => $item['descripcion'],
                    'cantidad' => $item['cantidad'],
                    'valor_unitario' => $item['valor_unitario'],
                    'valor_total' => $item['valor_total'],
                ]);
            }

            // Asignar flujo
            $flujo = $this->workflowResolver->resolverFlujo('anticipos', [
                'nivel_jerarquico' => $topes['nivel_jerarquico'],
                'prefijo_sucursal' => $empleado->empresa->sucursales->first()->prefijo ?? 'MA',
                'monto' => $topes['total'],
                'cobertura' => $data['cobertura'],
                'id_empresa' => $empleado->id_empresa,
            ]);

            // Iniciar flujo
            $instancia = $this->workflowExecutor->iniciarFlujo($flujo, 'anticipos', $solicitud->id);

            // Actualizar solicitud con flujo
            $solicitud->update([
                'estado' => 'pendiente_' . $instancia->pasoActual->rol_aprobador,
            ]);

            // Notificar aprobador
            $this->workflowNotifier->notificarAprobador($instancia);

            Log::info("Solicitud de anticipo creada", [
                'solicitud_id' => $solicitud->id,
                'numero' => $numeroSolicitud,
                'empleado' => $empleado->nombre,
                'monto' => $topes['total'],
                'flujo' => $flujo->codigo,
            ]);

            return $solicitud->load(['items', 'empleado', 'ciudadDestino']);
        });
    }

    private function generarNumeroSolicitud(): string
    {
        $year = date('Y');
        $ultimo = AntiSolicitud::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->first();

        $consecutivo = $ultimo ? (int) substr($ultimo->numero_solicitud, -5) + 1 : 1;

        return sprintf('ANT-%s-%05d', $year, $consecutivo);
    }

    /**
     * Aprueba una solicitud.
     */
    public function aprobar(int $id, int $userId, ?string $comentario = null, ?float $montoAutorizado = null): AntiSolicitud
    {
        return DB::transaction(function () use ($id, $userId, $comentario, $montoAutorizado) {
            $solicitud = AntiSolicitud::findOrFail($id);

            // Buscar la instancia de workflow activa para esta solicitud
            $instancia = WfInstancia::where('modulo_record_id', $solicitud->id)
                ->enProgreso()
                ->firstOrFail();

            // Aprobar en el motor de flujos
            $instancia = $this->workflowExecutor->aprobar(
                $instancia->id,
                $userId,
                $comentario,
                $montoAutorizado
            );

            // Actualizar estado de solicitud
            if ($instancia->estaCompletado()) {
                $solicitud->update([
                    'estado' => 'autorizado',
                    'monto_autorizado' => $montoAutorizado ?? $solicitud->monto_solicitado,
                ]);
                $this->workflowNotifier->notificarAprobacion($instancia, $solicitud->radicado_por);
            } else {
                $solicitud->update([
                    'estado' => 'pendiente_' . $instancia->pasoActual->rol_aprobador,
                ]);
                $this->workflowNotifier->notificarAprobador($instancia);
            }

            return $solicitud->fresh();
        });
    }

    /**
     * Rechaza una solicitud.
     */
    public function rechazar(int $id, int $userId, string $comentario): AntiSolicitud
    {
        return DB::transaction(function () use ($id, $userId, $comentario) {
            $solicitud = AntiSolicitud::findOrFail($id);

            // Buscar la instancia de workflow activa para esta solicitud
            $instancia = WfInstancia::where('modulo_record_id', $solicitud->id)
                ->enProgreso()
                ->firstOrFail();

            // Rechazar en el motor de flujos
            $instancia = $this->workflowExecutor->rechazar(
                $instancia->id,
                $userId,
                $comentario
            );

            // Actualizar estado
            $solicitud->update([
                'estado' => 'rechazado_' . $instancia->pasoActual->rol_aprobador,
            ]);

            $this->workflowNotifier->notificarRechazo($instancia, $solicitud->radicado_por, $comentario);

            return $solicitud->fresh();
        });
    }

    /**
     * Lista solicitudes con filtros.
     */
    public function listarSolicitudes(array $filtros = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = AntiSolicitud::with(['empleado', 'ciudadDestino', 'sedeOrigen']);

        if (isset($filtros['estado'])) {
            $query->where('estado', $filtros['estado']);
        }

        if (isset($filtros['id_empleado'])) {
            $query->where('id_empleado', $filtros['id_empleado']);
        }

        if (isset($filtros['fecha_desde'])) {
            $query->whereDate('fecha_salida', '>=', $filtros['fecha_desde']);
        }

        if (isset($filtros['fecha_hasta'])) {
            $query->whereDate('fecha_salida', '<=', $filtros['fecha_hasta']);
        }

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }
}
