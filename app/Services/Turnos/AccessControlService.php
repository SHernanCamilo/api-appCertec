<?php

namespace App\Services\Turnos;

use App\Models\User;
use App\Models\Turnos\ConfigUnidadFuncional;
use Illuminate\Database\Eloquent\Collection;

/**
 * Servicio de Control de Acceso para Turnos
 * 
 * Implementa lógica de 4 niveles:
 * 1. SUPER_ADMIN: Acceso total a todo
 * 2. TRANSVERSAL: Acceso a todas las sedes/unidades (sin empresa específica)
 * 3. EMPRESA_ADMIN: Acceso solo a su(s) empresa(s) asignada(s)
 * 4. USUARIO_RESPONSABLE_TURNO: Responsable de unidades específicas (puede controlar cuadro de turnos)
 * 
 */
class AccessControlService
{
    protected $user;
    protected $accessLevel;
    protected $empresasAsignadas = [];
    protected $sedesAsignadas = [];
    protected $unidadesAsignadas = [];

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->determineAccessLevel();
    }

    /**
     * Determina el nivel de acceso del usuario
     */
    private function determineAccessLevel(): void
    {
        // Cargar relaciones necesarias
        $this->user->load(['rolesCustom', 'empresas']);

        \Log::info('🔐 Determinando nivel de acceso', [
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
        ]);

        // NIVEL 1: Verificar SUPER_ADMIN
        if ($this->isSuperAdmin()) {
            $this->accessLevel = 'super_admin';
            \Log::info('✅ Usuario es SUPER_ADMIN', ['user_id' => $this->user->id]);
            return;
        }

        // Obtener empresas del usuario
        $this->empresasAsignadas = $this->user->empresas && $this->user->empresas->count() > 0 
            ? $this->user->empresas->pluck('id')->toArray() 
            : [];

        \Log::info('📊 Empresas del usuario', [
            'user_id' => $this->user->id,
            'empresas' => $this->empresasAsignadas,
        ]);

        // NIVEL 2: Verificar TRANSVERSAL (sin empresa asignada)
        if (empty($this->empresasAsignadas)) {
            $this->accessLevel = 'transversal';
            \Log::info('✅ Usuario es TRANSVERSAL (sin empresas)', ['user_id' => $this->user->id]);
            return;
        }

        // NIVEL 4: Verificar si es RESPONSABLE de unidades (tabla pivote config_unidades_fun_responsable)
        $tieneUnidadesResponsable = \DB::table('config_unidades_fun_responsable')
            ->where('id_user', $this->user->id)
            ->exists();

        \Log::info('🔍 Verificando si es responsable de unidades', [
            'user_id' => $this->user->id,
            'es_responsable' => $tieneUnidadesResponsable,
        ]);

        if ($tieneUnidadesResponsable) {
            $this->accessLevel = 'usuario_responsable_turno';
            \Log::info('✅ Usuario es USUARIO_RESPONSABLE_TURNO (responsable de unidades específicas)', ['user_id' => $this->user->id]);
            return;
        }

        // NIVEL 3: EMPRESA_ADMIN (tiene empresas asignadas pero sin unidades específicas)
        if (!empty($this->empresasAsignadas)) {
            $this->accessLevel = 'empresa_admin';
            \Log::info('✅ Usuario es EMPRESA_ADMIN', ['user_id' => $this->user->id]);
            return;
        }

        // Fallback: USUARIO_NORMAL
        $this->accessLevel = 'usuario_normal';
        \Log::warning('⚠️ Fallback a USUARIO_NORMAL', ['user_id' => $this->user->id]);
    }

    /**
     * Verifica si el usuario es SUPER_ADMIN
     */
    private function isSuperAdmin(): bool
    {
        if (!$this->user->rolesCustom || $this->user->rolesCustom->isEmpty()) {
            return false;
        }

        return $this->user->rolesCustom->whereIn('nombre', ['super_admin'])->isNotEmpty() ||
               $this->user->rolesCustom->whereIn('id', [1])->isNotEmpty();
    }

    /**
     * Obtiene el nivel de acceso actual
     */
    public function getAccessLevel(): string
    {
        return $this->accessLevel;
    }

    /**
     * Obtiene las unidades disponibles según el nivel de acceso
     */
    public function getUnidades(): Collection
    {
        return match ($this->accessLevel) {
            'super_admin' => $this->getUnidadesSuperAdmin(),
            'transversal' => $this->getUnidadesTransversal(),
            'empresa_admin' => $this->getUnidadesEmpresaAdmin(),
            default => $this->getUnidadesUsuarioResponsableTurno(),
        };
    }

    /**
     * NIVEL 1: SUPER_ADMIN - Todas las unidades activas
     */
    private function getUnidadesSuperAdmin(): Collection
    {
        return ConfigUnidadFuncional::with(['empresa', 'sede'])
            ->where('estado', true)
            ->orderBy('nombre')
            ->get();
    }

    /**
     * NIVEL 2: TRANSVERSAL - Todas las unidades activas
     */
    private function getUnidadesTransversal(): Collection
    {
        return ConfigUnidadFuncional::with(['empresa', 'sede'])
            ->where('estado', true)
            ->orderBy('nombre')
            ->get();
    }

    /**
     * NIVEL 3: EMPRESA_ADMIN - Solo unidades de sus empresas asignadas
     */
    private function getUnidadesEmpresaAdmin(): Collection
    {
        return ConfigUnidadFuncional::with(['empresa', 'sede'])
            ->whereIn('id_empresa', $this->empresasAsignadas)
            ->where('estado', true)
            ->orderBy('nombre')
            ->get();
    }

    /**
     * NIVEL 4: USUARIO_RESPONSABLE_TURNO - Solo unidades donde es RESPONSABLE (tabla pivote config_unidades_fun_responsable)
     */
    private function getUnidadesUsuarioResponsableTurno(): Collection
    {
        // Obtener unidades donde el usuario es RESPONSABLE desde tabla pivote config_unidades_fun_responsable
        $unidadIds = \DB::table('config_unidades_fun_responsable')
            ->where('id_user', $this->user->id)
            ->pluck('id_unidad_funcional')
            ->toArray();

        \Log::info('🔍 getUnidadesUsuarioResponsableTurno - Debug (Responsable)', [
            'user_id' => $this->user->id,
            'unidad_ids_from_responsable_table' => $unidadIds,
        ]);

        if (empty($unidadIds)) {
            \Log::warning('⚠️ Usuario responsable de 0 unidades', ['user_id' => $this->user->id]);
            return collect();
        }

        $unidades = ConfigUnidadFuncional::with(['empresa', 'sede'])
            ->whereIn('id', $unidadIds)
            ->where('estado', true)
            ->orderBy('nombre')
            ->get();

        \Log::info('✅ Unidades donde usuario es responsable', [
            'user_id' => $this->user->id,
            'total_unidades' => $unidades->count(),
            'unidades' => $unidades->map(fn($u) => [
                'id' => $u->id,
                'nombre' => $u->nombre,
                'id_empresa' => $u->id_empresa,
                'id_sede' => $u->id_sede,
            ])->toArray(),
        ]);

        return $unidades;
    }

    /**
     * Obtiene sedes disponibles según el nivel de acceso y empresa seleccionada
     */
    public function getSedesPorEmpresa(int $empresaId): Collection
    {
        // Validar que el usuario tiene acceso a esta empresa
        if (!$this->tieneAccesoEmpresa($empresaId)) {
            return collect();
        }

        // Obtener sedes de la empresa
        $sedes = \DB::table('config_ubi_sede')
            ->join('config_ubi_sucursales', 'config_ubi_sede.id_Sucursal', '=', 'config_ubi_sucursales.id')
            ->where('config_ubi_sucursales.id_Empresa', $empresaId)
            ->select('config_ubi_sede.id', 'config_ubi_sede.nombre')
            ->distinct()
            ->orderBy('config_ubi_sede.nombre')
            ->get();

        return collect($sedes);
    }

    /**
     * Obtiene unidades de una sede específica
     */
    public function getUnidadesPorSede(int $empresaId, int $sedeId): Collection
    {
        // Validar que el usuario tiene acceso a esta empresa
        if (!$this->tieneAccesoEmpresa($empresaId)) {
            return collect();
        }

        $query = ConfigUnidadFuncional::with(['empresa', 'sede'])
            ->where('id_empresa', $empresaId)
            ->where('id_sede', $sedeId)
            ->where('estado', true);

        // Filtrar por unidades donde es RESPONSABLE si es usuario_responsable_turno
        if ($this->accessLevel === 'usuario_responsable_turno') {
            $query->join('config_unidades_fun_responsable', 'config_unidades_funcionales.id', '=', 'config_unidades_fun_responsable.id_unidad_funcional')
                  ->where('config_unidades_fun_responsable.id_user', $this->user->id)
                  ->select('config_unidades_funcionales.*');
        }

        return $query->orderBy('nombre')->get();
    }

    /**
     * Verifica si el usuario tiene acceso a una empresa específica
     */
    public function tieneAccesoEmpresa(int $empresaId): bool
    {
        return match ($this->accessLevel) {
            'super_admin', 'transversal' => true,
            'empresa_admin' => in_array($empresaId, $this->empresasAsignadas),
            'usuario_responsable_turno' => $this->usuarioResponsableTurnoTieneAccesoEmpresa($empresaId),
            default => false,
        };
    }

    /**
     * Verifica si un USUARIO_RESPONSABLE_TURNO es responsable de una empresa (basado en sus unidades)
     */
    private function usuarioResponsableTurnoTieneAccesoEmpresa(int $empresaId): bool
    {
        // Un usuario responsable tiene acceso a una empresa si es RESPONSABLE de al menos una unidad de esa empresa
        $tieneUnidadEnEmpresa = \DB::table('config_unidades_fun_responsable')
            ->join('config_unidades_funcionales', 'config_unidades_fun_responsable.id_unidad_funcional', '=', 'config_unidades_funcionales.id')
            ->where('config_unidades_fun_responsable.id_user', $this->user->id)
            ->where('config_unidades_funcionales.id_empresa', $empresaId)
            ->exists();

        return $tieneUnidadEnEmpresa;
    }

    /**
     * Verifica si el usuario tiene acceso a una unidad específica
     */
    public function tieneAccesoUnidad(int $unidadId): bool
    {
        $unidad = ConfigUnidadFuncional::find($unidadId);
        
        if (!$unidad) {
            return false;
        }

        return match ($this->accessLevel) {
            'super_admin', 'transversal' => true,
            'empresa_admin' => in_array($unidad->id_empresa, $this->empresasAsignadas),
            'usuario_responsable_turno' => \DB::table('config_unidades_fun_responsable')
                ->where('id_unidad_funcional', $unidadId)
                ->where('id_user', $this->user->id)
                ->exists(),
            default => false,
        };
    }

    /**
     * Obtiene información de debugging del acceso
     */
    public function getDebugInfo(): array
    {
        return [
            'user_id' => $this->user->id,
            'user_name' => $this->user->name,
            'access_level' => $this->accessLevel,
            'is_super_admin' => $this->accessLevel === 'super_admin',
            'is_transversal' => $this->accessLevel === 'transversal',
            'is_empresa_admin' => $this->accessLevel === 'empresa_admin',
            'is_usuario_responsable_turno' => $this->accessLevel === 'usuario_responsable_turno',
            'empresas_asignadas' => $this->empresasAsignadas,
            'total_unidades_accesibles' => $this->getUnidades()->count(),
        ];
    }
}
