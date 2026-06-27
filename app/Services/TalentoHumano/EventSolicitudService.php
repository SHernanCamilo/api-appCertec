<?php

namespace App\Services\TalentoHumano;

use App\Models\Empleado;
use App\Models\Modulo;
use App\Models\Config\ConfigUnidadFuncional;
use App\Models\TalentoHumano\EventHoraExtra;
use App\Models\TalentoHumano\EventNovedad;
use App\Models\Workflow\WfAprobador;
use App\Models\Workflow\WfDefinicion;
use App\Models\Workflow\WfInstancia;
use App\Models\Workflow\WfModulo;
use App\Models\Workflow\WfGrupo;
use App\Models\Workflow\WfRegla;
use App\Services\SecuenciaNumericaService;
use App\Services\Workflow\WorkflowResolver;
use App\Services\Workflow\WorkflowExecutor;
use App\Services\Workflow\WorkflowNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EventSolicitudService
{
    private const MSG_UF_NO_PARAMETRIZADA = 'Unidad Funcional No parametrizada para eventos';

    public function __construct(
        private readonly SecuenciaNumericaService $secuenciaNumericaService,
        private readonly WorkflowResolver $workflowResolver,
        private readonly WorkflowExecutor $workflowExecutor,
        private readonly WorkflowNotifier $workflowNotifier,
    ) {}

    public function listar(array $filters = [], ?int $userId = null)
    {
        $query = EventHoraExtra::with([
            'empleado:id,nombre',
            'aprobador:id,nombre',
            'empleadoCubre:id,nombre',
            'novedad:id,codigo,descripcion',
        ])->leftJoin('config_unidades_funcionales as uf', 'uf.id', '=', 'event_horas_extra.id_unidad_funcional')
          ->select('event_horas_extra.*', 'uf.nombre as unidad_funcional');

        if ($userId) {
            $query->where('event_horas_extra.id_user_reg', $userId);
        }

        if (!empty($filters['estado'])) {
            $query->where('event_horas_extra.estado', $filters['estado']);
        }

        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->whereHas('empleado', fn($sub) => $sub->where('nombre', 'like', "%{$term}%"))
                  ->orWhere('event_horas_extra.consecutivo', 'like', "%{$term}%");
            });
        }

        return $query->orderBy('event_horas_extra.fecha_solicitud', 'desc')->paginate($filters['per_page'] ?? 10)
            ->through(fn (EventHoraExtra $evento) => $this->enriquecerEventoConIntervinientes($evento));
    }

    public function crear(array $data, int $userId): EventHoraExtra
    {
        $empresaId = $this->resolverEmpresaId((int)$data['empleado_id']);

        if (!$this->empleadoEnUnidadesResponsable($userId, (int)$data['empleado_id'], $empresaId)) {
            throw new \RuntimeException('El empleado seleccionado no pertenece a sus unidades funcionales a cargo.');
        }

        $contexto = $this->construirContextoEvento($data);
        $validacion = $this->validarParametrizacionEventos($contexto);
        if (!$validacion['ok']) {
            throw new \RuntimeException($validacion['mensaje']);
        }

        $moduloId = $this->resolverModuloEventosId();

        // Generar consecutivo desde la parametrización de secuencias.
        // Si no existe configuración válida, este método lanza RuntimeException.
        $consecutivo = $this->secuenciaNumericaService->generar($empresaId, $moduloId);

        return DB::transaction(function () use ($data, $userId, $empresaId, $consecutivo) {
            $unidadFuncionalId = $this->resolveUnidadFuncionalId($data);

            $solicitud = EventHoraExtra::create([
                'consecutivo'           => $consecutivo,
                'id_user_nov'           => $data['empleado_id'],
                'id_user_aprobador'     => $data['aprobador_id'] ?? null,
                'id_unidad_funcional'   => $unidadFuncionalId,
                'id_motivo_evento'      => $data['novedad_id'] ?? null,
                'id_user_cubre'         => $data['empleado_cubre_id'] ?? null,
                'fecha_nov_ini'         => $data['fecha_inicial'],
                'fecha_nov_fin'         => $data['fecha_final'],
                'fecha_solicitud'       => now(),
                'coment_solicitante'    => $data['descripcion'] ?? '',
                'coment_aprobador'      => '',
                'coment_autorizador'    => '',
                'coment_digitalizador'  => '',
                'fecha_aprobacion'      => null,
                'fecha_autorizacion'    => null,
                'fecha_digitalizacion'  => null,
                'user_digitalizador'    => null,
                'id_motivo_rechazo'     => null,
                'estado'                => isset($data['estado']) ? (int)$data['estado'] : 1,
                'id_user_reg'           => $userId,
            ]);

            $this->iniciarFlujoAprobacion($solicitud, $empresaId, $userId);

            return $solicitud->fresh();
        });
    }

    /**
     * Resuelve e inicia el flujo de aprobación del evento.
     *
     * Si no hay flujo configurado para el contexto, el evento queda registrado
     * sin instancia (estado 1) y se deja traza en el log.
     */
    private function iniciarFlujoAprobacion(EventHoraExtra $solicitud, int $empresaId, int $userId): void
    {
        try {
            $contexto = $this->construirContextoEvento([
                'empleado_id'          => $solicitud->id_user_nov,
                'unidad_funcional_id'  => $solicitud->id_unidad_funcional,
                'novedad_id'           => $solicitud->id_motivo_evento,
                'empresa_id'           => $empresaId,
            ], $solicitud->id);

            $flujo = $this->resolverFlujoParametrizadoEventos($contexto);
            if (!$flujo) {
                throw new \RuntimeException(self::MSG_UF_NO_PARAMETRIZADA);
            }

            $instancia = $this->workflowExecutor->iniciarFlujo(
                $flujo,
                $userId,
                $contexto,
                $solicitud->consecutivo
            );

            $instancia->load('pasoActual');
            $intervinientes = $this->workflowNotifier->resolverAprobadores($instancia);

            $solicitud->update([
                'wf_instancia_id'   => $instancia->id,
                'estado'            => EventoEstadoMapper::desdeInstancia($instancia),
                'id_user_aprobador' => $this->workflowNotifier->primerEmpleadoIdIntervinientes($intervinientes),
            ]);

            Log::info('Flujo de evento iniciado', [
                'evento_id'    => $solicitud->id,
                'instancia_id' => $instancia->id,
                'flujo'        => $flujo->codigo,
            ]);
        } catch (\Throwable $e) {
            // Sin flujo configurado para el contexto: el evento queda registrado.
            Log::warning('Sin flujo de aprobación para el evento', [
                'evento_id'           => $solicitud->id,
                'id_unidad_funcional' => $solicitud->id_unidad_funcional,
                'id_empresa'          => $empresaId,
                'error'               => $e->getMessage(),
            ]);
        }
    }

    public function perteneceAlUsuario(int $id, int $userId): bool
    {
        return EventHoraExtra::where('id', $id)
            ->where('id_user_reg', $userId)
            ->exists();
    }

    /**
     * Unidades funcionales de las que el usuario es responsable (cargador).
     * Son las UF a las que puede cargar eventos.
     */
    public function unidadesFuncionalesPorResponsable(int $userId, ?int $empresaId = null): \Illuminate\Support\Collection
    {
        $empleadoId = $this->workflowNotifier->resolveEmpleadoIdFromUser($userId);

        if (!$empleadoId) {
            return collect();
        }

        return \App\Models\Config\ConfigUnidadFuncional::query()
            ->activas()
            ->whereHas('responsables', fn($q) => $q->where('config_unidades_fun_responsable.id_user', $empleadoId))
            ->when($empresaId, fn($q, $id) => $q->where('id_empresa', $id))
            ->orderBy('nombre')
            ->get(['id', 'codigo', 'nombre', 'id_empresa', 'id_sucursal', 'id_sede']);
    }

    /**
     * Empleados asignados a las unidades funcionales de las que el usuario es responsable.
     */
    public function empleadosPorUnidadesResponsable(
        int $userId,
        ?int $empresaId = null,
        ?string $search = null,
        int $limit = 100,
        int $page = 1
    ): \Illuminate\Support\Collection {
        $unidadIds = $this->unidadesFuncionalesPorResponsable($userId, $empresaId)->pluck('id');

        if ($unidadIds->isEmpty()) {
            return collect();
        }

        $query = Empleado::query()
            ->select('id', 'nombre', 'email', 'numero_identificacion')
            ->where('estado', true)
            ->whereIn('id', function ($sub) use ($unidadIds) {
                $sub->select('id_user')
                    ->from('config_unidades_fun_usuarios')
                    ->whereIn('id_unidad_funcional', $unidadIds);
            })
            ->when($empresaId, fn($q, $id) => $q->where('id_empresa', $id))
            ->orderBy('nombre');

        if ($search && strlen($search) >= 2) {
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'like', '%' . $search . '%')
                    ->orWhere('numero_identificacion', 'like', $search . '%');
            });
        }

        $limit = min(max($limit, 10), 500);
        $page = max($page, 1);
        $offset = ($page - 1) * $limit;

        return $query->limit($limit)->offset($offset)->get();
    }

    public function empleadoEnUnidadesResponsable(int $userId, int $empleadoId, ?int $empresaId = null): bool
    {
        $unidadIds = $this->unidadesFuncionalesPorResponsable($userId, $empresaId)->pluck('id');

        if ($unidadIds->isEmpty()) {
            return false;
        }

        return Empleado::query()
            ->where('id', $empleadoId)
            ->where('estado', true)
            ->when($empresaId, fn($q, $id) => $q->where('id_empresa', $id))
            ->whereIn('id', function ($sub) use ($unidadIds) {
                $sub->select('id_user')
                    ->from('config_unidades_fun_usuarios')
                    ->whereIn('id_unidad_funcional', $unidadIds);
            })
            ->exists();
    }

    /**
     * Indica si el usuario es responsable de la unidad funcional indicada.
     */
    public function esResponsableDeUnidad(int $userId, int $unidadFuncionalId): bool
    {
        return $this->workflowNotifier->esResponsableDeUnidad($userId, $unidadFuncionalId);
    }

    /**
     * Previsualiza el flujo parametrizado para la UF del empleado (UF individual o grupo WF).
     */
    public function previewFlujo(array $data): ?array
    {
        $contexto = $this->construirContextoEvento($data);
        $ufFlujo = $this->resolverUnidadFuncionalFlujo($data, $contexto['id_empresa'] ?? null);
        $uf = $ufFlujo ? ConfigUnidadFuncional::find($ufFlujo) : null;

        $ufPayload = $uf ? [
            'id'     => $uf->id,
            'codigo' => $uf->codigo,
            'nombre' => $uf->nombre,
        ] : null;

        $validacion = $this->validarParametrizacionEventos($contexto);
        if (!$validacion['ok']) {
            return [
                'parametrizada'          => false,
                'mensaje'                => $validacion['mensaje'],
                'unidad_funcional_flujo' => $ufPayload,
                'pasos'                  => [],
            ];
        }

        $flujo = $validacion['flujo'];

        return [
            'parametrizada'          => true,
            'codigo'                 => $flujo->codigo,
            'nombre'                 => $flujo->nombre,
            'unidad_funcional_flujo' => $ufPayload,
            'modo_parametrizacion'   => $contexto['modo_parametrizacion_eventos'] ?? null,
            'pasos'                  => $flujo->pasos()->activos()->ordenados()->get()
                ->map(function ($p) use ($contexto) {
                    $intervinientes = $this->workflowNotifier->resolverAprobadoresParaPaso($p, $contexto);

                    return [
                        'orden'                => $p->orden,
                        'nombre_paso'          => $p->nombre_paso,
                        'rol_aprobador'        => $p->rol_aprobador,
                        'intervinientes'       => $intervinientes->map(fn ($u) => [
                            'id'     => $u->id,
                            'nombre' => $u->name,
                        ])->values()->all(),
                        'intervinientes_texto' => $this->workflowNotifier->nombresIntervinientes($intervinientes),
                    ];
                })->values()->all(),
        ];
    }

    /**
     * Valida que la UF del empleado tenga flujo y responsables parametrizados (UF o grupo WF).
     */
    public function validarParametrizacionEventos(array $contexto): array
    {
        $ufId = $contexto['id_unidad_funcional'] ?? null;
        if (!$ufId) {
            return ['ok' => false, 'mensaje' => self::MSG_UF_NO_PARAMETRIZADA];
        }

        $flujo = $this->resolverFlujoParametrizadoEventos($contexto);
        if (!$flujo) {
            return ['ok' => false, 'mensaje' => self::MSG_UF_NO_PARAMETRIZADA];
        }

        $pasos = $flujo->relationLoaded('pasos')
            ? $flujo->pasos
            : $flujo->pasos()->activos()->ordenados()->get();

        if ($pasos->isEmpty()) {
            return ['ok' => false, 'mensaje' => self::MSG_UF_NO_PARAMETRIZADA];
        }

        foreach ($pasos as $paso) {
            $intervinientes = $this->workflowNotifier->resolverAprobadoresParaPaso($paso, $contexto);
            if ($intervinientes->isEmpty()) {
                return ['ok' => false, 'mensaje' => self::MSG_UF_NO_PARAMETRIZADA];
            }
        }

        return ['ok' => true, 'mensaje' => null, 'flujo' => $flujo];
    }

    /**
     * Flujo de eventos asignado explícitamente por UF o por grupo WF.
     */
    private function resolverFlujoParametrizadoEventos(array $contexto): ?WfDefinicion
    {
        $ufId = (int)($contexto['id_unidad_funcional'] ?? 0);
        if ($ufId <= 0) {
            return null;
        }

        $withPasos = fn ($q) => $q->with(['pasos' => fn ($p) => $p->where('estado', true)->orderBy('orden')]);

        $reglaUf = WfRegla::query()
            ->activos()
            ->whereRaw("JSON_EXTRACT(condiciones, '$.id_unidad_funcional') = ?", [$ufId])
            ->whereHas('definicion', fn ($q) => $this->scopeFlujoEventosActivo($q))
            ->with(['definicion' => $withPasos])
            ->orderBy('prioridad')
            ->first();

        if ($reglaUf?->definicion) {
            return $reglaUf->definicion;
        }

        $grupoId = (int)($contexto['id_grupo'] ?? 0);
        if ($grupoId <= 0) {
            return null;
        }

        $reglaGrupo = WfRegla::query()
            ->activos()
            ->whereRaw("JSON_EXTRACT(condiciones, '$.id_grupo') = ?", [$grupoId])
            ->whereHas('definicion', fn ($q) => $this->scopeFlujoEventosActivo($q))
            ->with(['definicion' => $withPasos])
            ->orderBy('prioridad')
            ->first();

        return $reglaGrupo?->definicion;
    }

    private function scopeFlujoEventosActivo($query): void
    {
        $query->where('estado', true)
            ->whereHas('modulo', fn ($m) => $m->where('codigo', 'eventos'));
    }

    private function resolverModoParametrizacionEventos(int $ufId, ?int $empresaId): ?string
    {
        $tieneReglaUf = WfRegla::query()
            ->activos()
            ->whereRaw("JSON_EXTRACT(condiciones, '$.id_unidad_funcional') = ?", [$ufId])
            ->whereHas('definicion', fn ($q) => $this->scopeFlujoEventosActivo($q))
            ->exists();

        if ($tieneReglaUf) {
            return 'uf';
        }

        $grupo = WfGrupo::obtenerGrupoPorUnidadFuncional($ufId, $empresaId);
        if (!$grupo) {
            return null;
        }

        $tieneReglaGrupo = WfRegla::query()
            ->activos()
            ->whereRaw("JSON_EXTRACT(condiciones, '$.id_grupo') = ?", [$grupo->id])
            ->whereHas('definicion', fn ($q) => $this->scopeFlujoEventosActivo($q))
            ->exists();

        return $tieneReglaGrupo ? 'grupo' : null;
    }

    /**
     * Lista los eventos pendientes de acción para el usuario autenticado,
     * es decir, instancias en progreso cuyo paso actual puede aprobar.
     */
    public function listarPendientes(int $userId, array $filters = [])
    {
        $instancias = WfInstancia::with(['pasoActual', 'definicion'])
            ->enProgreso()
            ->whereHas('modulo', fn($q) => $q->where('codigo', 'eventos'))
            ->get();

        // Mapa record_id => instancia, solo de los que el usuario puede aprobar
        $autorizadas = $instancias->filter(
            fn(WfInstancia $i) => $this->workflowNotifier->esUsuarioAutorizado($userId, $i)
        );

        $recordIds = $autorizadas->pluck('modulo_record_id')->all();

        if (empty($recordIds)) {
            return EventHoraExtra::whereRaw('1 = 0')->paginate($filters['per_page'] ?? 10);
        }

        $pasoPorRecord = $autorizadas->mapWithKeys(
            fn(WfInstancia $i) => [$i->modulo_record_id => optional($i->pasoActual)->nombre_paso]
        );

        $query = EventHoraExtra::with([
            'empleado:id,nombre,numero_identificacion',
            'novedad:id,codigo,descripcion',
            'empleadoCubre:id,nombre',
        ])->leftJoin('config_unidades_funcionales as uf', 'uf.id', '=', 'event_horas_extra.id_unidad_funcional')
          ->select('event_horas_extra.*', 'uf.nombre as unidad_funcional')
          ->whereIn('event_horas_extra.id', $recordIds);

        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->whereHas('empleado', fn($sub) => $sub->where('nombre', 'like', "%{$term}%"))
                  ->orWhere('event_horas_extra.consecutivo', 'like', "%{$term}%");
            });
        }

        $paginado = $query->orderBy('event_horas_extra.fecha_solicitud', 'desc')
            ->paginate($filters['per_page'] ?? 10);

        // Anexar el nombre del paso actual a cada item
        $paginado->getCollection()->transform(function ($evento) use ($pasoPorRecord) {
            $evento->paso_actual = $pasoPorRecord[$evento->id] ?? null;
            return $evento;
        });

        return $paginado;
    }

    /**
     * Aprueba el paso actual del evento y avanza el flujo.
     */
    public function aprobar(int $id, int $userId, ?string $comentario = null): EventHoraExtra
    {
        return DB::transaction(function () use ($id, $userId, $comentario) {
            $solicitud = EventHoraExtra::findOrFail($id);

            if (!$solicitud->wf_instancia_id) {
                throw new \RuntimeException('El evento no tiene un flujo de aprobación asociado.');
            }

            $instancia = $this->workflowExecutor->aprobar($solicitud->wf_instancia_id, $userId, $comentario);
            $instancia->load('pasoActual');
            $intervinientes = $this->workflowNotifier->resolverAprobadores($instancia);

            $solicitud->update([
                'estado'            => EventoEstadoMapper::desdeInstancia($instancia),
                'fecha_aprobacion'  => $solicitud->fecha_aprobacion ?? now(),
                'id_user_aprobador' => $instancia->estaEnProgreso()
                    ? $this->workflowNotifier->primerEmpleadoIdIntervinientes($intervinientes)
                    : $solicitud->id_user_aprobador,
            ]);

            return $solicitud->fresh();
        });
    }

    /**
     * Rechaza el evento y finaliza el flujo.
     */
    public function rechazar(int $id, int $userId, string $motivo): EventHoraExtra
    {
        return DB::transaction(function () use ($id, $userId, $motivo) {
            $solicitud = EventHoraExtra::findOrFail($id);

            if (!$solicitud->wf_instancia_id) {
                throw new \RuntimeException('El evento no tiene un flujo de aprobación asociado.');
            }

            $instancia = $this->workflowExecutor->rechazar($solicitud->wf_instancia_id, $userId, $motivo);

            $solicitud->update([
                'estado'          => EventoEstadoMapper::desdeInstancia($instancia),
                'coment_aprobador' => $motivo,
            ]);

            return $solicitud->fresh();
        });
    }

    /**
     * Historial de aprobaciones del evento.
     */
    public function historial(int $id): array
    {
        $solicitud = EventHoraExtra::findOrFail($id);

        if (!$solicitud->wf_instancia_id) {
            return ['instancia' => null, 'aprobaciones' => []];
        }

        return $this->workflowExecutor->obtenerHistorial($solicitud->wf_instancia_id);
    }

    /**
     * Indica si el usuario puede aprobar/rechazar el evento en su paso actual.
     */
    public function puedeAprobar(int $id, int $userId): bool
    {
        $solicitud = EventHoraExtra::find($id);

        if (!$solicitud || !$solicitud->wf_instancia_id) {
            return false;
        }

        $instancia = WfInstancia::with('pasoActual')->find($solicitud->wf_instancia_id);

        if (!$instancia || !$instancia->estaEnProgreso()) {
            return false;
        }

        return $this->workflowNotifier->esUsuarioAutorizado($userId, $instancia);
    }

    public function actualizar(int $id, array $data): EventHoraExtra
    {
        $solicitud = EventHoraExtra::findOrFail($id);
        $solicitud->update([
            'id_user_nov'         => $data['empleado_id']       ?? $solicitud->id_user_nov,
            'id_user_aprobador'   => $data['aprobador_id']      ?? $solicitud->id_user_aprobador,
            'id_unidad_funcional' => $this->resolveUnidadFuncionalId($data, $solicitud->id_unidad_funcional),
            'id_motivo_evento'    => $data['novedad_id']        ?? $solicitud->id_motivo_evento,
            'id_user_cubre'       => $data['empleado_cubre_id'] ?? $solicitud->id_user_cubre,
            'fecha_nov_ini'       => $data['fecha_inicial']     ?? $solicitud->fecha_nov_ini,
            'fecha_nov_fin'       => $data['fecha_final']       ?? $solicitud->fecha_nov_fin,
            'coment_solicitante'  => $data['descripcion']       ?? $solicitud->coment_solicitante,
            'estado'              => array_key_exists('estado', $data) ? (int)$data['estado'] : $solicitud->estado,
        ]);
        return $solicitud->fresh();
    }

    public function eliminar(int $id): void
    {
        EventHoraExtra::findOrFail($id)->delete();
    }

    public function listarFlujosEventos(): \Illuminate\Support\Collection
    {
        $modulo = WfModulo::where('codigo', 'eventos')->first();
        if (!$modulo) {
            return collect();
        }

        return WfDefinicion::query()
            ->where('id_modulo', $modulo->id)
            ->where('estado', true)
            ->with(['pasos' => fn ($q) => $q->where('estado', true)->orderBy('orden')])
            ->orderBy('nombre')
            ->get(['id', 'codigo', 'nombre', 'descripcion']);
    }

    public function obtenerConfiguracionFlujoUnidad(int $unidadFuncionalId): array
    {
        $uf = ConfigUnidadFuncional::findOrFail($unidadFuncionalId);

        $flujo = $this->resolverFlujoPorUnidad($unidadFuncionalId);
        $responsables = [];

        if ($flujo) {
            $pasoIds = $flujo->pasos->pluck('id');
            $responsables = WfAprobador::query()
                ->whereIn('id_paso', $pasoIds)
                ->where('id_unidad_funcional', $unidadFuncionalId)
                ->whereNotNull('id_user')
                ->where('estado', true)
                ->get(['id_paso', 'id_user'])
                ->groupBy('id_paso')
                ->map(fn ($items) => $items->pluck('id_user')->map(fn ($id) => (int) $id)->values()->all())
                ->all();
        }

        return [
            'unidad_funcional_id' => $unidadFuncionalId,
            'empresa_id'          => (int) $uf->id_empresa,
            'flujo_id'            => $flujo?->id,
            'responsables'        => $responsables,
        ];
    }

    public function guardarConfiguracionFlujoUnidad(
        int $unidadFuncionalId,
        int $flujoId,
        array $responsables
    ): array {
        $uf = ConfigUnidadFuncional::findOrFail($unidadFuncionalId);
        $flujo = WfDefinicion::with(['pasos' => fn ($q) => $q->where('estado', true)->orderBy('orden')])->findOrFail($flujoId);

        if ($flujo->modulo?->codigo !== 'eventos') {
            throw new \RuntimeException('El flujo seleccionado no pertenece al módulo eventos.');
        }

        return DB::transaction(function () use ($uf, $flujo, $unidadFuncionalId, $responsables) {
            $this->desactivarReglasUnidad($unidadFuncionalId);
            $this->activarReglaUnidadEnFlujo($flujo->id, $unidadFuncionalId);

            $responsablesGrouped = collect($responsables)
                ->filter(fn ($r) => !empty($r['id_paso']) && !empty($r['id_user']))
                ->groupBy(fn ($r) => (int) $r['id_paso']);

            foreach ($flujo->pasos as $paso) {
                WfAprobador::query()
                    ->where('id_paso', $paso->id)
                    ->where('id_unidad_funcional', $unidadFuncionalId)
                    ->where('estado', true)
                    ->update(['estado' => false]);

                $usersPaso = $responsablesGrouped->get((int) $paso->id, collect());
                foreach ($usersPaso as $cfg) {
                    WfAprobador::create([
                        'id_paso'              => $paso->id,
                        'tipo_aprobador'       => 'USER',
                        'id_user'              => (int) $cfg['id_user'],
                        'id_unidad_funcional'  => $unidadFuncionalId,
                        'es_suplente'          => false,
                        'estado'               => true,
                    ]);
                }
            }

            return [
                'unidad_funcional_id' => $unidadFuncionalId,
                'empresa_id'          => (int) $uf->id_empresa,
                'flujo_id'            => $flujo->id,
            ];
        });
    }

    private function resolverFlujoPorUnidad(int $unidadFuncionalId): ?WfDefinicion
    {
        $regla = WfRegla::query()
            ->where('estado', true)
            ->whereRaw("JSON_EXTRACT(condiciones, '$.id_unidad_funcional') = ?", [$unidadFuncionalId])
            ->with(['definicion' => fn ($q) => $q->with(['modulo', 'pasos' => fn ($p) => $p->where('estado', true)->orderBy('orden')])])
            ->orderBy('prioridad')
            ->first();

        if ($regla && $regla->definicion && optional($regla->definicion->modulo)->codigo === 'eventos') {
            return $regla->definicion;
        }

        $modulo = WfModulo::where('codigo', 'eventos')->first();
        if (!$modulo) {
            return null;
        }

        return WfDefinicion::query()
            ->where('id_modulo', $modulo->id)
            ->where('estado', true)
            ->whereHas('reglas', fn ($q) => $q->where('estado', true)->whereRaw("JSON_LENGTH(condiciones) = 0"))
            ->with(['pasos' => fn ($q) => $q->where('estado', true)->orderBy('orden')])
            ->orderBy('id')
            ->first();
    }

    private function desactivarReglasUnidad(int $unidadFuncionalId): void
    {
        $modulo = WfModulo::where('codigo', 'eventos')->first();
        if (!$modulo) {
            return;
        }

        $idsEventos = WfDefinicion::where('id_modulo', $modulo->id)->pluck('id');
        if ($idsEventos->isEmpty()) {
            return;
        }

        WfRegla::query()
            ->whereIn('id_definicion', $idsEventos)
            ->where('estado', true)
            ->whereRaw("JSON_EXTRACT(condiciones, '$.id_unidad_funcional') = ?", [$unidadFuncionalId])
            ->update(['estado' => false]);
    }

    private function activarReglaUnidadEnFlujo(int $flujoId, int $unidadFuncionalId): void
    {
        $existente = WfRegla::query()
            ->where('id_definicion', $flujoId)
            ->whereRaw("JSON_EXTRACT(condiciones, '$.id_unidad_funcional') = ?", [$unidadFuncionalId])
            ->first();

        if ($existente) {
            $existente->update([
                'estado' => true,
                'prioridad' => 10,
                'condiciones' => ['id_unidad_funcional' => $unidadFuncionalId],
            ]);
            return;
        }

        WfRegla::create([
            'id_definicion' => $flujoId,
            'prioridad'     => 10,
            'condiciones'   => ['id_unidad_funcional' => $unidadFuncionalId],
            'estado'        => true,
        ]);
    }

    private function resolverEmpresaId(int $empleadoId): int
    {
        $empleado = Empleado::find($empleadoId);

        if (!$empleado || !$empleado->id_empresa) {
            throw new \RuntimeException('No se pudo determinar la empresa del empleado seleccionado.');
        }

        return (int)$empleado->id_empresa;
    }

    private function resolverModuloEventosId(): int
    {
        $modulo = Modulo::query()
            ->where('estado', 1)
            ->where(function ($q) {
                $q->where('ruta', 'like', '%eventos/dashboard%')
                    ->orWhere('nombre', 'Dashboard Evento')
                    ->orWhere('codigo', 'like', '%EVENT%');
            })
            ->orderByRaw("CASE WHEN ruta LIKE '%eventos/dashboard%' THEN 0 ELSE 1 END")
            ->first();

        if (!$modulo) {
            throw new \RuntimeException('No se encontró el módulo de Dashboard Evento para generar consecutivo.');
        }

        return (int)$modulo->id;
    }

    private function resolveUnidadFuncionalId(array $data, ?int $fallback = null): ?int
    {
        $raw = $data['unidad_funcional_id'] ?? $data['unidad_funcional'] ?? null;

        if ($raw === null || $raw === '') {
            return $fallback;
        }

        return is_numeric($raw) ? (int)$raw : $fallback;
    }

    /**
     * Contexto para resolver flujo e intervinientes.
     * El flujo aplica según la UF a la que pertenece el empleado;
     * la UF del formulario (evento) se conserva aparte para registro.
     */
    private function construirContextoEvento(array $data, ?int $recordId = null): array
    {
        $empresaId = isset($data['empresa_id']) ? (int)$data['empresa_id'] : null;

        if (!$empresaId && !empty($data['empleado_id'])) {
            $empresaId = $this->resolverEmpresaId((int)$data['empleado_id']);
        }

        $ufEventoId = $this->resolveUnidadFuncionalId($data);
        $ufFlujoId = $this->resolverUnidadFuncionalFlujo($data, $empresaId);

        $tipoNovedad = !empty($data['novedad_id'])
            ? optional(EventNovedad::find((int)$data['novedad_id']))->codigo
            : null;

        $contexto = [
            'record_id'                  => $recordId,
            'id_empresa'                 => $empresaId,
            'id_unidad_funcional'        => $ufFlujoId,
            'id_unidad_funcional_evento' => $ufEventoId,
            'tipo_novedad'               => $tipoNovedad,
        ];

        if ($ufFlujoId) {
            $uf = ConfigUnidadFuncional::find($ufFlujoId);
            if ($uf) {
                $contexto['id_sucursal'] = $uf->id_sucursal;
                $contexto['id_sede'] = $uf->id_sede;
            }

            $grupo = WfGrupo::obtenerGrupoPorUnidadFuncional($ufFlujoId, $empresaId);
            if ($grupo) {
                $contexto['id_grupo'] = $grupo->id;
            }

            $contexto['solo_aprobadores_parametrizados'] = true;
            $contexto['modo_parametrizacion_eventos'] = $this->resolverModoParametrizacionEventos($ufFlujoId, $empresaId);
        }

        return $contexto;
    }

    /**
     * UF del empleado (config_unidades_fun_usuarios) usada para el flujo de aprobación.
     */
    private function resolverUnidadFuncionalFlujo(array $data, ?int $empresaId = null): ?int
    {
        if (!empty($data['empleado_id'])) {
            $ufEmpleado = $this->resolverUnidadFuncionalEmpleado((int)$data['empleado_id'], $empresaId);
            if ($ufEmpleado) {
                return $ufEmpleado;
            }
        }

        return $this->resolveUnidadFuncionalId($data);
    }

    private function resolverUnidadFuncionalEmpleado(int $empleadoId, ?int $empresaId = null): ?int
    {
        $query = ConfigUnidadFuncional::query()
            ->activas()
            ->whereHas('usuarios', fn ($q) => $q->where('config_person_tercero.id', $empleadoId));

        if ($empresaId) {
            $query->where('id_empresa', $empresaId);
        }

        return $query->orderBy('nombre')->value('id');
    }

    private function enriquecerEventoConIntervinientes(EventHoraExtra $evento): EventHoraExtra
    {
        $evento->paso_actual = null;
        $evento->aprobador_pendiente = null;

        if (!$evento->wf_instancia_id) {
            return $evento;
        }

        $instancia = WfInstancia::with('pasoActual')->find($evento->wf_instancia_id);

        if (!$instancia || !$instancia->pasoActual) {
            return $evento;
        }

        $intervinientes = $this->workflowNotifier->resolverAprobadores($instancia);
        $nombres = $this->workflowNotifier->nombresIntervinientes($intervinientes);

        $evento->paso_actual = $instancia->pasoActual->nombre_paso;
        $evento->aprobador_pendiente = $nombres ?: null;

        if ($nombres) {
            $evento->setRelation('aprobador', (object)['id' => null, 'nombre' => $nombres]);
        }

        return $evento;
    }
}
