<?php

namespace App\Services\TalentoHumano\CuadroTurnos;

use App\Models\User;
use App\Models\Config\ConfigUnidadFuncional;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

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
    protected $empresasHabilitadas = [];

    /**
     * Cache en memoria por request: evita recalcular el nivel de acceso
     * cuando el servicio se instancia varias veces para el mismo usuario.
     * [userId => ['accessLevel' => ..., 'empresasAsignadas' => [...]]]
     */
    private static array $cachePorUsuario = [];

    /**
     * TTL (segundos) del cache persistente del nivel de acceso.
     */
    private const CACHE_TTL = 300;

    /**
     * Devuelve la clave de cache persistente para un usuario.
     */
    public static function cacheKey(int $userId): string
    {
        return "ct_access_level_{$userId}";
    }

    /**
     * Invalida el cache persistente del nivel de acceso de un usuario.
     * Llamar cuando cambien sus roles, empresas o unidades responsables.
     */
    public static function invalidarCache(int $userId): void
    {
        unset(self::$cachePorUsuario[$userId]);
        Cache::forget(self::cacheKey($userId));
    }

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->empresasHabilitadas = config('cuadro_turnos.empresas_habilitadas', []);

        // 1) Cache en memoria del request actual (múltiples instanciaciones)
        if (isset(self::$cachePorUsuario[$user->id])) {
            $cache = self::$cachePorUsuario[$user->id];
            $this->accessLevel = $cache['accessLevel'];
            $this->empresasAsignadas = $cache['empresasAsignadas'];
            return;
        }

        // 2) Cache persistente entre requests (Laravel Cache, TTL corto).
        //    Evita recalcular load(rolesCustom, empresas) + query de unidades
        //    responsables en cada petición al reentrar al módulo.
        $cache = Cache::remember(
            self::cacheKey($user->id),
            self::CACHE_TTL,
            function () {
                $this->determineAccessLevel();
                return [
                    'accessLevel' => $this->accessLevel,
                    'empresasAsignadas' => $this->empresasAsignadas,
                ];
            }
        );

        $this->accessLevel = $cache['accessLevel'];
        $this->empresasAsignadas = $cache['empresasAsignadas'];

        // Guardar tambien en memoria para el resto del request
        self::$cachePorUsuario[$user->id] = $cache;
    }

    /**
     * Determina el nivel de acceso del usuario
     */
    private function determineAccessLevel(): void
    {
        // Cargar relaciones necesarias
        $this->user->load(['rolesCustom', 'empresas']);

        \Log::debug('Determinando nivel de acceso', ['user_id' => $this->user->id]);

        // NIVEL 1: Verificar SUPER_ADMIN
        if ($this->isSuperAdmin()) {
            $this->accessLevel = 'super_admin';
            return;
        }

        // Obtener empresas del usuario
        $this->empresasAsignadas = $this->user->empresas && $this->user->empresas->count() > 0 
            ? $this->user->empresas->pluck('id')->toArray() 
            : [];

        // NIVEL 2: Verificar TRANSVERSAL (sin empresa asignada)
        if (empty($this->empresasAsignadas)) {
            $this->accessLevel = 'transversal';
            return;
        }

        // NIVEL 4: Verificar si es RESPONSABLE de unidades (tabla pivote config_unidades_fun_responsable)
        $tieneUnidadesResponsable = \DB::table('config_unidades_fun_responsable')
            ->where('id_user', $this->user->id)
            ->exists();

        if ($tieneUnidadesResponsable) {
            $this->accessLevel = 'usuario_responsable_turno';
            return;
        }

        // NIVEL 3: EMPRESA_ADMIN (tiene empresas asignadas pero sin unidades específicas)
        if (!empty($this->empresasAsignadas)) {
            $this->accessLevel = 'empresa_admin';
            return;
        }

        // Fallback: USUARIO_NORMAL
        $this->accessLevel = 'usuario_normal';
        \Log::debug('Fallback a USUARIO_NORMAL', ['user_id' => $this->user->id]);
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
     * NIVEL 1: SUPER_ADMIN - Todas las unidades activas (filtradas por empresas habilitadas)
     */
    private function getUnidadesSuperAdmin(): Collection
    {
        $query = ConfigUnidadFuncional::with(['empresa', 'sede'])
            ->where('estado', true);

        if (!empty($this->empresasHabilitadas)) {
            $query->whereIn('id_empresa', $this->empresasHabilitadas);
        }

        return $query->orderBy('nombre')->get();
    }

    /**
     * NIVEL 2: TRANSVERSAL - Todas las unidades activas (filtradas por empresas habilitadas)
     */
    private function getUnidadesTransversal(): Collection
    {
        $query = ConfigUnidadFuncional::with(['empresa', 'sede'])
            ->where('estado', true);

        if (!empty($this->empresasHabilitadas)) {
            $query->whereIn('id_empresa', $this->empresasHabilitadas);
        }

        return $query->orderBy('nombre')->get();
    }

    /**
     * NIVEL 3: EMPRESA_ADMIN - Solo unidades de sus empresas asignadas (intersectadas con habilitadas)
     */
    private function getUnidadesEmpresaAdmin(): Collection
    {
        $empresas = $this->empresasAsignadas;

        // Intersectar con empresas habilitadas para el módulo
        if (!empty($this->empresasHabilitadas)) {
            $empresas = array_intersect($empresas, $this->empresasHabilitadas);
        }

        if (empty($empresas)) {
            return collect();
        }

        return ConfigUnidadFuncional::with(['empresa', 'sede'])
            ->whereIn('id_empresa', $empresas)
            ->where('estado', true)
            ->orderBy('nombre')
            ->get();
    }

    /**
     * NIVEL 4: USUARIO_RESPONSABLE_TURNO - Solo unidades donde es RESPONSABLE (filtradas por empresas habilitadas)
     */
    private function getUnidadesUsuarioResponsableTurno(): Collection
    {
        // Obtener unidades donde el usuario es RESPONSABLE desde tabla pivote config_unidades_fun_responsable
        $unidadIds = \DB::table('config_unidades_fun_responsable')
            ->where('id_user', $this->user->id)
            ->pluck('id_unidad_funcional')
            ->toArray();

        if (empty($unidadIds)) {
            return collect();
        }

        $query = ConfigUnidadFuncional::with(['empresa', 'sede'])
            ->whereIn('id', $unidadIds)
            ->where('estado', true);

        // Filtrar por empresas habilitadas
        if (!empty($this->empresasHabilitadas)) {
            $query->whereIn('id_empresa', $this->empresasHabilitadas);
        }

        return $query->orderBy('nombre')->get();
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
        // Si hay empresas habilitadas configuradas, verificar que la empresa esté en la lista
        if (!empty($this->empresasHabilitadas) && !in_array($empresaId, $this->empresasHabilitadas)) {
            return false;
        }

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
