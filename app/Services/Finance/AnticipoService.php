<?php

namespace App\Services\Finance;

use App\Models\Finance\AntiTipo;
use App\Models\Finance\AntiClase;
use App\Models\Finance\AntiModalidad;
use App\Models\Finance\AntiConcepto;
use App\Models\Finance\AntiRegla;
use App\Models\Empleado;
use App\Models\Finance\AntiSolicitud;
use App\Models\Finance\AntiSolicitudItem;
use App\Models\Finance\AntiSolicitudDocumento;
use App\Models\Finance\AntiCiudad;
use App\Models\Workflow\WfInstancia;
use App\Models\Workflow\WfGrupo;
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
                'unidad_funcional' => $empleado->unidad,
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

            // Crear items desde los topes calculados O desde los items enviados por frontend
            if (!empty($data['items'])) {
                // Items enviados desde frontend
                foreach ($data['items'] as $item) {
                    AntiSolicitudItem::create([
                        'id_solicitud' => $solicitud->id,
                        'id_concepto' => $item['id_concepto'] ?? null,
                        'id_regla' => !empty($item['id_regla']) ? $item['id_regla'] : null,
                        'descripcion' => $item['descripcion'],
                        'cantidad' => $item['cantidad'],
                        'valor_unitario' => $item['valor_unitario'],
                        'valor_total' => $item['valor_total'],
                    ]);
                }
                // Recalcular monto solicitado desde items
                $solicitud->update([
                    'monto_solicitado' => collect($data['items'])->sum('valor_total'),
                ]);
            } else {
                // Items calculados automáticamente
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
            }

            // Intentar asignar flujo de aprobación
            try {
                $grupo = WfGrupo::obtenerGrupoPorCargo(
                    $empleado->id_cargo,
                    $empleado->id_empresa
                );

                $flujo = $this->workflowResolver->resolverFlujo('anticipos', [
                    'nivel' => $topes['nivel_jerarquico'],
                    'prefijo' => $empleado->empresa->sucursales->first()->prefijo ?? 'MA',
                    'monto' => $solicitud->monto_solicitado,
                    'cobertura' => $data['cobertura'],
                    'id_empresa' => $empleado->id_empresa,
                    'id_grupo' => $grupo?->id,
                ]);

                $instancia = $this->workflowExecutor->iniciarFlujo($flujo, 'anticipos', $solicitud->id);

                $solicitud->update([
                    'estado' => 'pendiente_' . $instancia->pasoActual->rol_aprobador,
                ]);

                $this->workflowNotifier->notificarAprobador($instancia);
            } catch (\Exception $e) {
                // Sin flujo configurado, queda en borrador
                Log::warning("Sin flujo de aprobación configurado para esta solicitud", [
                    'solicitud_id' => $solicitud->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info("Solicitud de anticipo creada", [
                'solicitud_id' => $solicitud->id,
                'numero' => $numeroSolicitud,
                'empleado' => $empleado->nombre,
                'monto' => $solicitud->monto_solicitado,
                'estado' => $solicitud->estado,
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
     * Filtra automáticamente por la empresa del contexto del usuario.
     */
    public function listarSolicitudes(array $filtros = []): \Illuminate\Pagination\LengthAwarePaginator
    {
        $query = AntiSolicitud::with(['empleado', 'ciudadDestino', 'sedeOrigen']);

        // Filtro obligatorio por empresa del contexto del usuario
        $contexto = \App\Models\UsuarioContexto::obtenerContexto(auth()->user());
        if ($contexto && $contexto->empresa_id) {
            $query->whereHas('empleado', fn($q) => $q->where('id_empresa', $contexto->empresa_id));
        }

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

    // ========================================================================
    // FASE POST-VIAJE
    // ========================================================================

    /**
     * Tesorería desembolsa el anticipo.
     * autorizado → en_viaje
     */
    public function desembolsar(int $id, int $userId): AntiSolicitud
    {
        return DB::transaction(function () use ($id, $userId) {
            $solicitud = AntiSolicitud::findOrFail($id);

            if (!$solicitud->estaEnEstado(AntiSolicitud::ESTADO_AUTORIZADO)) {
                throw new \Exception("La solicitud debe estar en estado 'autorizado' para desembolsar");
            }

            $solicitud->update(['estado' => AntiSolicitud::ESTADO_EN_VIAJE]);

            Log::info("Anticipo desembolsado", [
                'solicitud_id' => $solicitud->id,
                'monto' => $solicitud->monto_autorizado,
                'desembolsado_por' => $userId,
            ]);

            return $solicitud->fresh();
        });
    }

    /**
     * Habilita la legalización después del viaje.
     * en_viaje → pendiente_legalizacion
     *
     * Se puede llamar automáticamente cuando fecha_regreso <= hoy.
     */
    public function habilitarLegalizacion(int $id): AntiSolicitud
    {
        $solicitud = AntiSolicitud::findOrFail($id);

        if (!$solicitud->estaEnEstado(AntiSolicitud::ESTADO_EN_VIAJE)) {
            throw new \Exception("La solicitud debe estar en estado 'en_viaje'");
        }

        $solicitud->update(['estado' => AntiSolicitud::ESTADO_PENDIENTE_LEGALIZACION]);

        return $solicitud->fresh();
    }

    /**
     * Solicitante sube soportes y legaliza.
     * pendiente_legalizacion → legalizado
     *
     * @param array $data [
     *   'monto_legalizado' => float (lo que realmente gastó),
     *   'observaciones' => string,
     * ]
     */
    public function legalizar(int $id, int $userId, array $data): AntiSolicitud
    {
        return DB::transaction(function () use ($id, $userId, $data) {
            $solicitud = AntiSolicitud::findOrFail($id);

            if (!$solicitud->estaEnEstado(AntiSolicitud::ESTADO_PENDIENTE_LEGALIZACION)) {
                throw new \Exception("La solicitud debe estar en estado 'pendiente_legalizacion'");
            }

            $montoLegalizado = $data['monto_legalizado'];
            $montoAutorizado = $solicitud->monto_autorizado;

            $updateData = [
                'monto_legalizado' => $montoLegalizado,
                'estado' => AntiSolicitud::ESTADO_LEGALIZADO,
                'observaciones' => $data['observaciones'] ?? null,
            ];

            // Calcular diferencia
            if ($montoLegalizado < $montoAutorizado) {
                $updateData['monto_reintegro'] = $montoAutorizado - $montoLegalizado;
            } elseif ($montoLegalizado > $montoAutorizado) {
                $updateData['monto_excedente'] = $montoLegalizado - $montoAutorizado;
            }

            $solicitud->update($updateData);

            Log::info("Anticipo legalizado", [
                'solicitud_id' => $solicitud->id,
                'monto_autorizado' => $montoAutorizado,
                'monto_legalizado' => $montoLegalizado,
                'diferencia' => $montoLegalizado - $montoAutorizado,
            ]);

            return $solicitud->fresh();
        });
    }

    /**
     * Contabilidad decide qué hacer con la solicitud legalizada.
     * legalizado → cerrado | pendiente_reintegro | pendiente_excedente
     *
     * @param string $decision 'aceptar' | 'sobrante' | 'excedente'
     */
    public function decidirContabilidad(int $id, int $userId, string $decision, ?string $comentario = null): AntiSolicitud
    {
        return DB::transaction(function () use ($id, $userId, $decision, $comentario) {
            $solicitud = AntiSolicitud::findOrFail($id);

            if (!$solicitud->estaEnEstado(AntiSolicitud::ESTADO_LEGALIZADO)) {
                throw new \Exception("La solicitud debe estar en estado 'legalizado'");
            }

            switch ($decision) {
                case 'aceptar':
                    // Gastó exacto o contabilidad acepta sin más trámite
                    $solicitud->update(['estado' => AntiSolicitud::ESTADO_CERRADO]);
                    break;

                case 'sobrante':
                    // Gastó menos → solicitante debe devolver sobrante
                    if (!$solicitud->tieneSobrante()) {
                        throw new \Exception("La solicitud no tiene sobrante registrado");
                    }
                    $solicitud->update(['estado' => AntiSolicitud::ESTADO_PENDIENTE_REINTEGRO]);
                    break;

                case 'excedente':
                    // Gastó más → Dir. Financiera debe aprobar reintegro
                    if (!$solicitud->tieneExcedente()) {
                        throw new \Exception("La solicitud no tiene excedente registrado");
                    }
                    $solicitud->update(['estado' => AntiSolicitud::ESTADO_PENDIENTE_EXCEDENTE]);
                    break;

                default:
                    throw new \Exception("Decisión no válida: {$decision}");
            }

            Log::info("Contabilidad decidió", [
                'solicitud_id' => $solicitud->id,
                'decision' => $decision,
                'usuario' => $userId,
            ]);

            return $solicitud->fresh();
        });
    }

    /**
     * Solicitante registra devolución del sobrante.
     * pendiente_reintegro → reintegrado
     *
     * El solicitante adjunta el soporte de la consignación (fuera del flujo,
     * es comunicación directa con tesorería).
     */
    public function registrarDevolucion(int $id, int $userId): AntiSolicitud
    {
        return DB::transaction(function () use ($id, $userId) {
            $solicitud = AntiSolicitud::findOrFail($id);

            if (!$solicitud->estaEnEstado(AntiSolicitud::ESTADO_PENDIENTE_REINTEGRO)) {
                throw new \Exception("La solicitud debe estar en estado 'pendiente_reintegro'");
            }

            $solicitud->update(['estado' => AntiSolicitud::ESTADO_REINTEGRADO]);

            return $solicitud->fresh();
        });
    }

    /**
     * Dir. Financiera aprueba el reintegro del excedente.
     * pendiente_excedente → aprobado_excedente
     */
    public function aprobarExcedente(int $id, int $userId, ?string $comentario = null): AntiSolicitud
    {
        return DB::transaction(function () use ($id, $userId, $comentario) {
            $solicitud = AntiSolicitud::findOrFail($id);

            if (!$solicitud->estaEnEstado(AntiSolicitud::ESTADO_PENDIENTE_EXCEDENTE)) {
                throw new \Exception("La solicitud debe estar en estado 'pendiente_excedente'");
            }

            $solicitud->update(['estado' => AntiSolicitud::ESTADO_APROBADO_EXCEDENTE]);

            Log::info("Excedente aprobado", [
                'solicitud_id' => $solicitud->id,
                'monto_excedente' => $solicitud->monto_excedente,
                'aprobado_por' => $userId,
            ]);

            return $solicitud->fresh();
        });
    }

    /**
     * Dir. Financiera rechaza el reintegro del excedente.
     * pendiente_excedente → rechazado_excedente
     */
    public function rechazarExcedente(int $id, int $userId, string $comentario): AntiSolicitud
    {
        return DB::transaction(function () use ($id, $userId, $comentario) {
            $solicitud = AntiSolicitud::findOrFail($id);

            if (!$solicitud->estaEnEstado(AntiSolicitud::ESTADO_PENDIENTE_EXCEDENTE)) {
                throw new \Exception("La solicitud debe estar en estado 'pendiente_excedente'");
            }

            $solicitud->update(['estado' => AntiSolicitud::ESTADO_RECHAZADO_EXCEDENTE]);

            return $solicitud->fresh();
        });
    }

    /**
     * Contabilidad cierra la solicitud (estado final).
     * reintegrado | aprobado_excedente | rechazado_excedente → cerrado
     */
    public function cerrar(int $id, int $userId, ?string $comentario = null): AntiSolicitud
    {
        return DB::transaction(function () use ($id, $userId, $comentario) {
            $solicitud = AntiSolicitud::findOrFail($id);

            $estadosPermitidos = [
                AntiSolicitud::ESTADO_REINTEGRADO,
                AntiSolicitud::ESTADO_APROBADO_EXCEDENTE,
                AntiSolicitud::ESTADO_RECHAZADO_EXCEDENTE,
            ];

            if (!in_array($solicitud->estado, $estadosPermitidos)) {
                throw new \Exception("La solicitud no puede cerrarse desde el estado '{$solicitud->estado}'");
            }

            $solicitud->update(['estado' => AntiSolicitud::ESTADO_CERRADO]);

            Log::info("Anticipo cerrado", [
                'solicitud_id' => $solicitud->id,
                'cerrado_por' => $userId,
            ]);

            return $solicitud->fresh();
        });
    }
}
