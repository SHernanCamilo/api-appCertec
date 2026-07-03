<?php

namespace App\Services\Workflow;

use App\Models\Workflow\WfInstancia;
use App\Models\Workflow\WfNotificacion;
use App\Models\Workflow\WfAprobador;
use App\Models\Empleado;
use App\Models\User;
use App\Models\Config\ConfigUnidadFunResponsable;
use App\Models\ConfigPersonTercero;
use Illuminate\Support\Facades\Log;

/**
 * Gestiona notificaciones del motor de flujos.
 *
 * Responsabilidades:
 *   - Notificar a aprobadores cuando tienen solicitudes pendientes
 *   - Notificar al solicitante sobre cambios de estado
 *   - Resolver quién debe aprobar cada paso
 */
class WorkflowNotifier
{
    /**
     * Notifica al aprobador del paso actual.
     *
     * @param WfInstancia $instancia
     * @return void
     */
    public function notificarAprobador(WfInstancia $instancia): void
    {
        if (!$instancia->estaEnProgreso()) {
            return;
        }

        $aprobadores = $this->resolverAprobadores($instancia);

        foreach ($aprobadores as $aprobador) {
            $this->crearNotificacion(
                $instancia,
                $aprobador->id,
                WfNotificacion::TIPO_PENDIENTE_APROBACION,
                "Tienes una solicitud pendiente de aprobación en el paso: {$instancia->pasoActual->nombre_paso}"
            );
        }

        Log::info("Notificaciones enviadas", [
            'instancia_id' => $instancia->id,
            'paso' => $instancia->pasoActual->nombre_paso,
            'aprobadores' => $aprobadores->pluck('id')->toArray(),
        ]);
    }

    /**
     * Notifica al solicitante sobre aprobación.
     */
    public function notificarAprobacion(WfInstancia $instancia, int $solicitanteId): void
    {
        $mensaje = $instancia->estaCompletado()
            ? "Tu solicitud ha sido aprobada completamente"
            : "Tu solicitud avanzó al paso: {$instancia->pasoActual->nombre_paso}";

        $this->crearNotificacion(
            $instancia,
            $solicitanteId,
            WfNotificacion::TIPO_APROBADO,
            $mensaje
        );
    }

    /**
     * Notifica al solicitante sobre rechazo.
     */
    public function notificarRechazo(WfInstancia $instancia, int $solicitanteId, string $motivo): void
    {
        $this->crearNotificacion(
            $instancia,
            $solicitanteId,
            WfNotificacion::TIPO_RECHAZADO,
            "Tu solicitud fue rechazada en el paso: {$instancia->pasoActual->nombre_paso}. Motivo: {$motivo}"
        );
    }

    /**
     * Resuelve quiénes deben aprobar el paso actual.
     */
    public function resolverAprobadores(WfInstancia $instancia): \Illuminate\Support\Collection
    {
        if (!$instancia->pasoActual) {
            return collect();
        }

        return $this->resolverAprobadoresParaPaso($instancia->pasoActual, $instancia->contexto ?? []);
    }

    /**
     * Resuelve intervinientes de un paso según wf_aprobadores y contexto del evento.
     */
    public function resolverAprobadoresParaPaso(\App\Models\Workflow\WfPaso $paso, array $contexto): \Illuminate\Support\Collection
    {
        if (!empty($contexto['solo_aprobadores_parametrizados'])) {
            return $this->resolverAprobadoresParametrizadosEventos($paso, $contexto);
        }

        $aprobadores = WfAprobador::where('id_paso', $paso->id)
            ->activos()
            ->titulares()
            ->with(['user', 'grupo.cargos'])
            ->get();

        if ($aprobadores->isEmpty()) {
            Log::warning("No hay aprobadores configurados para el paso", [
                'paso_id' => $paso->id,
                'paso_nombre' => $paso->nombre_paso,
            ]);
            return collect();
        }

        $usuarios = collect();

        foreach ($aprobadores as $aprobador) {
            // Filtro por motor de reglas específico de cada aprobador
            if (!$aprobador->evaluarCondiciones($contexto)) {
                continue;
            }

            // Nueva estrategia: Aprobador fijo transversal
            if ($aprobador->tipo_aprobador === 'USER' && $aprobador->id_user) {
                $usuarios->push($aprobador->user);
                continue;
            }

            // Nueva estrategia: Responsable de la Unidad Funcional (dinámico según el contexto del evento)
            if ($aprobador->tipo_aprobador === 'RESPONSABLE_UF') {
                $idUnidadFuncional = $contexto['id_unidad_funcional'] ?? null;
                if ($idUnidadFuncional) {
                    $usuariosUF = $this->obtenerResponsablesPorUnidadFuncional($idUnidadFuncional);
                    $usuarios = $usuarios->merge($usuariosUF);
                }
                continue;
            }

            // Nueva estrategia: Aprobador por WfGrupo (cargos)
            if ($aprobador->tipo_aprobador === 'RESPONSABLE_GRUPO' || $aprobador->tipo_aprobador === 'GRUPO') {
                $idWfGrupo = $aprobador->id_grupo;
                if ($idWfGrupo) {
                    $idEmpresa = $contexto['id_empresa'] ?? null;
                    $usuariosGrupo = $this->obtenerUsuariosPorWfGrupo($idWfGrupo, $idEmpresa);
                    $usuarios = $usuarios->merge($usuariosGrupo);
                }
                continue;
            }

            // Estrategias Legacy de fallback (mezclado con lo de Hernan Camilo)
            if ($aprobador->id_user && $aprobador->tipo_aprobador !== 'USER') {
                $contextUfId = (int)($contexto['id_unidad_funcional'] ?? 0);
                if ($aprobador->id_unidad_funcional !== null) {
                    $cfgUfId = (int)$aprobador->id_unidad_funcional;
                    if ($cfgUfId === 0) {
                        $cfgUfId = $contextUfId;
                    }
                    if ($contextUfId > 0 && $cfgUfId > 0 && $cfgUfId !== $contextUfId) {
                        continue;
                    }
                }

                if ($aprobador->user) {
                    $usuarios->push($aprobador->user);
                }
                continue;
            }

            // UF fija en config, o 0 = UF del contexto del evento
            if ($aprobador->id_unidad_funcional !== null && $aprobador->tipo_aprobador !== 'RESPONSABLE_UF') {
                $ufId = (int)$aprobador->id_unidad_funcional;
                $contextUfId = (int)($contexto['id_unidad_funcional'] ?? 0);
                if ($ufId === 0) {
                    $ufId = $contextUfId;
                } elseif ($contextUfId > 0 && $ufId !== $contextUfId) {
                    continue;
                }
                if ($ufId > 0) {
                    $usuariosUF = $this->obtenerAprobadoresPorUnidadFuncional($ufId, $paso->rol_aprobador);
                    if ($aprobador->permiso_codigo) {
                        $usuariosUF = $this->intersectarConPermiso($usuariosUF, $aprobador->permiso_codigo, $contexto);
                    }
                    $usuarios = $usuarios->merge($usuariosUF);
                }
                continue;
            }

            if ($aprobador->prefijo_sucursal) {
                $usuariosSucursal = $this->obtenerAprobadoresPorPrefijoSucursal(
                    $aprobador->prefijo_sucursal,
                    $contexto,
                    $paso->rol_aprobador
                );
                $usuarios = $usuarios->merge($usuariosSucursal);
                continue;
            }

            // Fallback legacy id_grupo si no es tipo GRUPO
            if ($aprobador->id_grupo && !in_array($aprobador->tipo_aprobador, ['RESPONSABLE_GRUPO', 'GRUPO'])) {
                $usuariosGrupo = $this->obtenerAprobadoresPorGrupo(
                    $aprobador->id_grupo,
                    $contexto,
                    $paso->rol_aprobador
                );
                $usuarios = $usuarios->merge($usuariosGrupo);
                continue;
            }

            if ($aprobador->permiso_codigo) {
                $usuariosPermiso = $this->obtenerAprobadoresPorPermiso(
                    $aprobador->permiso_codigo,
                    $contexto,
                    $aprobador->alcance ?? null
                );
                $usuarios = $usuarios->merge($usuariosPermiso);
                continue;
            }
        }

        return $usuarios->filter()->unique('id')->values();
    }

    /**
     * Solo responsables explícitos parametrizados por UF o por grupo WF (módulo eventos).
     */
    private function resolverAprobadoresParametrizadosEventos(
        \App\Models\Workflow\WfPaso $paso,
        array $contexto
    ): \Illuminate\Support\Collection {
        $modo = $contexto['modo_parametrizacion_eventos'] ?? null;
        $ufId = (int)($contexto['id_unidad_funcional'] ?? 0);
        $grupoId = (int)($contexto['id_grupo'] ?? 0);

        if (!$modo || ($modo === 'uf' && $ufId <= 0) || ($modo === 'grupo' && $grupoId <= 0)) {
            return collect();
        }

        $aprobadores = WfAprobador::where('id_paso', $paso->id)
            ->activos()
            ->titulares()
            ->whereNotNull('id_user')
            ->with('user')
            ->get();

        $filtrados = $aprobadores->filter(function ($a) use ($modo, $ufId, $grupoId) {
            if ($modo === 'uf') {
                return (int)$a->id_unidad_funcional === $ufId;
            }

            $condGrupo = $a->condiciones['id_grupo'] ?? null;

            return $condGrupo !== null && (int)$condGrupo === $grupoId;
        });

        return $filtrados->pluck('user')->filter()->unique('id')->values();
    }

    /**
     * Nombres legibles de intervinientes para UI.
     */
    public function nombresIntervinientes(\Illuminate\Support\Collection $usuarios): string
    {
        return $usuarios->pluck('name')->filter()->unique()->implode(', ');
    }

    /**
     * Primer empleado (config_person_tercero) entre los intervinientes resueltos.
     */
    public function primerEmpleadoIdIntervinientes(\Illuminate\Support\Collection $usuarios): ?int
    {
        foreach ($usuarios as $user) {
            $empleadoId = $this->resolveEmpleadoIdFromUser($user->id);
            if ($empleadoId) {
                return $empleadoId;
            }
        }

        return null;
    }

    /**
     * Estrategia 5: permiso + alcance opcional (uf|sucursal|sede|empresa).
     */
    private function obtenerAprobadoresPorPermiso(
        string $codigoPermiso,
        array $contexto,
        ?string $alcance = null
    ): \Illuminate\Support\Collection {
        $empresaId = $contexto['id_empresa'] ?? null;
        $usuarios = User::conPermiso($codigoPermiso, $empresaId)->get();

        if ($usuarios->isEmpty()) {
            Log::warning("Sin usuarios con el permiso para aprobar", [
                'permiso' => $codigoPermiso,
                'id_empresa' => $empresaId,
                'alcance' => $alcance,
            ]);
            return collect();
        }

        return $this->filtrarUsuariosPorAlcance($usuarios, $alcance, $contexto, $codigoPermiso);
    }

    /**
     * Usuarios con permiso que además cumplen el alcance organizacional.
     */
    private function filtrarUsuariosPorAlcance(
        \Illuminate\Support\Collection $usuarios,
        ?string $alcance,
        array $contexto,
        ?string $codigoPermiso = null
    ): \Illuminate\Support\Collection {
        $alcance = $alcance ?: 'empresa';

        if ($alcance === 'empresa') {
            return $usuarios;
        }

        if ($alcance === 'uf') {
            $ufId = (int)($contexto['id_unidad_funcional'] ?? 0);
            if ($ufId <= 0) {
                return collect();
            }
            $ufUsers = $this->obtenerResponsablesUnidadFuncional($ufId);
            $ids = $ufUsers->pluck('id')->all();
            return $usuarios->whereIn('id', $ids)->values();
        }

        if ($alcance === 'sucursal') {
            $sucursalId = (int)($contexto['id_sucursal'] ?? 0);
            $empresaId = (int)($contexto['id_empresa'] ?? 0);
            if ($sucursalId <= 0 || $empresaId <= 0) {
                return collect();
            }

            return $usuarios->filter(function (User $u) use ($empresaId, $sucursalId) {
                return $u->empresas()
                    ->where('ent_empresas.id', $empresaId)
                    ->where(function ($q) use ($sucursalId) {
                        $q->where('seg_empresa_user.id_sucursal', $sucursalId)
                            ->orWhere('seg_empresa_user.recursivo', true);
                    })
                    ->exists();
            })->values();
        }

        if ($alcance === 'sede') {
            $sedeId = (int)($contexto['id_sede'] ?? 0);
            $empresaId = (int)($contexto['id_empresa'] ?? 0);
            if ($sedeId <= 0 || $empresaId <= 0) {
                return collect();
            }

            return $usuarios->filter(function (User $u) use ($empresaId, $sedeId) {
                return $u->empresas()
                    ->where('ent_empresas.id', $empresaId)
                    ->where('seg_empresa_user.id_sede', $sedeId)
                    ->exists();
            })->values();
        }

        return $usuarios;
    }

    private function intersectarConPermiso(
        \Illuminate\Support\Collection $usuarios,
        string $codigoPermiso,
        array $contexto
    ): \Illuminate\Support\Collection {
        $empresaId = $contexto['id_empresa'] ?? null;
        $conPermiso = User::conPermiso($codigoPermiso, $empresaId)->pluck('id')->all();

        return $usuarios->whereIn('id', $conPermiso)->values();
    }

    /**
     * Verifica si un usuario está autorizado para aprobar un paso.
     */
    public function esUsuarioAutorizado(int $userId, WfInstancia $instancia): bool
    {
        $aprobadores = $this->resolverAprobadores($instancia);
        return $aprobadores->contains('id', $userId);
    }

    /**
     * Obtiene aprobadores de una unidad funcional por rol. (Legacy)
     */
    private function obtenerAprobadoresPorUnidadFuncional(int $idUnidadFuncional, string $rol): \Illuminate\Support\Collection
    {
        // Primero intentamos con el modelo Finance\AntiAprobador (compatibilidad)
        try {
            $modelo = \App\Models\Finance\AntiAprobador::class;
            if (class_exists($modelo)) {
                return $modelo::where('id_unidad_funcional', $idUnidadFuncional)
                    ->where('rol_aprobador', $rol)
                    ->where('es_suplente', false)
                    ->activos()
                    ->with('user')
                    ->get()
                    ->pluck('user');
            }
        } catch (\Exception $e) {
            Log::warning("Modelo AntiAprobador no disponible", ['error' => $e->getMessage()]);
        }

        // Fallback: responsables registrados en config_unidades_fun_responsable
        return $this->obtenerResponsablesUnidadFuncional($idUnidadFuncional);
    }

    /**
     * Obtiene los usuarios responsables de una unidad funcional
     * desde config_unidades_fun_responsable.
     *
     * @param int $idUnidadFuncional ID en config_unidades_funcionales
     * @return \Illuminate\Support\Collection<int, User>
     */
    public function obtenerResponsablesUnidadFuncional(int $idUnidadFuncional): \Illuminate\Support\Collection
    {
        $personas = \App\Models\Config\ConfigUnidadFunResponsable::where('id_unidad_funcional', $idUnidadFuncional)
            ->with('persona:id,email,numero_identificacion')
            ->get()
            ->pluck('persona')
            ->filter();

        if ($personas->isEmpty()) {
            return collect();
        }

        $emails = $personas->pluck('email')->filter()->unique()->values();
        $documentos = $personas->pluck('numero_identificacion')->filter()->unique()->values();

        return User::query()
            ->where(function ($q) use ($emails, $documentos) {
                if ($emails->isNotEmpty()) {
                    $q->whereIn('email', $emails);
                }
                if ($documentos->isNotEmpty()) {
                    $q->orWhereIn('numero_identificacion', $documentos);
                }
            })
            ->get();
    }

    /**
     * Indica si un usuario es responsable de una unidad funcional
     * (puede cargar eventos a empleados de esa unidad).
     */
    public function esResponsableDeUnidad(int $userId, int $idUnidadFuncional): bool
    {
        $empleadoId = $this->resolveEmpleadoIdFromUser($userId);

        if (!$empleadoId) {
            return false;
        }

        return \App\Models\Config\ConfigUnidadFunResponsable::where('id_unidad_funcional', $idUnidadFuncional)
            ->where('id_user', $empleadoId)
            ->exists();
    }

    public function resolveEmpleadoIdFromUser(int $userId): ?int
    {
        $user = User::find($userId);

        if (!$user) {
            return null;
        }

        $query = \App\Models\Empleado::query()->where('estado', true);

        if (!empty($user->numero_identificacion)) {
            $empleado = (clone $query)
                ->where('numero_identificacion', $user->numero_identificacion)
                ->value('id');

            if ($empleado) {
                return (int) $empleado;
            }
        }

        if (!empty($user->email)) {
            $empleado = (clone $query)
                ->where('email', $user->email)
                ->value('id');

            if ($empleado) {
                return (int) $empleado;
            }
        }

        return null;
    }

    /**
     * Obtiene el Responsable actual de la ConfigUnidadFuncional.
     */
    private function obtenerResponsablesPorUnidadFuncional(int $idUnidadFuncional): \Illuminate\Support\Collection
    {
        try {
            $responsables = \App\Models\Config\ConfigUnidadFunResponsable::where('id_unidad_funcional', $idUnidadFuncional)
                ->with('usuario')
                ->get();
                
            return $responsables->pluck('usuario')->filter();
        } catch (\Exception $e) {
            Log::warning("Error al obtener responsable UF", ['error' => $e->getMessage()]);
            return collect();
        }
    }

    /**
     * Obtiene los usuarios que pertenecen a un WfGrupo (basado en sus cargos).
     */
    private function obtenerUsuariosPorWfGrupo(int $idWfGrupo, ?int $idEmpresa = null): \Illuminate\Support\Collection
    {
        try {
            $grupo = \App\Models\Workflow\WfGrupo::with('cargos')->find($idWfGrupo);
            if (!$grupo || $grupo->cargos->isEmpty()) {
                return collect();
            }

            $cargosIds = $grupo->cargos->pluck('id_cargo');

            $query = \App\Models\Empleado::whereIn('id_cargo', $cargosIds)
                ->where('estado', true)
                ->whereNotNull('user_id')
                ->with('user');

            if ($idEmpresa) {
                $query->where('id_empresa', $idEmpresa);
            }

            return $query->get()->pluck('user')->filter();
        } catch (\Exception $e) {
            Log::warning("Error al obtener usuarios por WfGrupo", ['error' => $e->getMessage()]);
            return collect();
        }
    }

    /**
     * Obtiene aprobadores por prefijo de sucursal.
     */
    private function obtenerAprobadoresPorPrefijoSucursal(string $prefijo, array $contexto, string $rol): \Illuminate\Support\Collection
    {
        // Buscar empleados en sucursales con ese prefijo
        $empleados = Empleado::whereHas('empresa', function ($q) use ($prefijo) {
                $q->where('prefijo', $prefijo);
            })
            ->where('estado', true)
            ->whereNotNull('user_id')
            ->with('user')
            ->get()
            ->pluck('user');

        return $empleados;
    }

    /**
     * Obtiene aprobadores por grupo.
     */
    private function obtenerAprobadoresPorGrupo(int $idGrupo, array $contexto, string $rol): \Illuminate\Support\Collection
    {
        // Obtener el grupo con sus cargos
        $grupo = \App\Models\Workflow\WfGrupo::with('cargos')->find($idGrupo);
        
        if (!$grupo) {
            return collect();
        }

        // Obtener IDs de cargos del grupo
        $cargosIds = $grupo->cargos->pluck('id_cargo')->toArray();

        // Buscar empleados con esos cargos
        $empleados = Empleado::whereIn('id_cargo', $cargosIds)
            ->where('estado', true)
            ->whereNotNull('user_id')
            ->with('user')
            ->get()
            ->pluck('user');

        return $empleados;
    }

    /**
     * Crea una notificación en la base de datos.
     */
    private function crearNotificacion(
        WfInstancia $instancia,
        int $userId,
        string $tipo,
        string $mensaje
    ): WfNotificacion {
        return WfNotificacion::create([
            'id_instancia' => $instancia->id,
            'id_user' => $userId,
            'tipo' => $tipo,
            'mensaje' => $mensaje,
            'leida' => false,
        ]);
    }

    /**
     * Marca todas las notificaciones de un usuario como leídas.
     */
    public function marcarTodasComoLeidas(int $userId): int
    {
        return WfNotificacion::porUsuario($userId)
            ->noLeidas()
            ->update([
                'leida' => true,
                'fecha_lectura' => now(),
            ]);
    }

    /**
     * Obtiene notificaciones pendientes de un usuario.
     */
    public function obtenerNotificacionesPendientes(int $userId, int $limit = 50): \Illuminate\Support\Collection
    {
        return WfNotificacion::porUsuario($userId)
            ->noLeidas()
            ->with('instancia.definicion')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
