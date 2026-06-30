<?php

namespace App\Services\Turnos;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio único para consultar empleados de una unidad funcional.
 *
 * REGLA: Este es el ÚNICO lugar donde se consultan empleados por unidad.
 * Garantiza que tanto users del sistema como terceros externos
 * siempre aparezcan juntos.
 */
class EmpleadosUnidadService
{
    /**
     * Obtiene TODOS los empleados de una unidad funcional:
     * - Users del sistema (config_unidades_fun_usuarios → users)
     * - Terceros externos (config_unidades_fun_terceros → config_person_tercero)
     */
    public function getEmpleadosPorUnidad(int $unidadId): Collection
    {
        $users    = $this->getUsersPorUnidad($unidadId);
        $terceros = $this->getTercerosPorUnidad($unidadId);

        return $users->concat($terceros)->sortBy('nombre')->values();
    }

    /**
     * Users del sistema asignados a la unidad.
     */
    public function getUsersPorUnidad(int $unidadId): Collection
    {
        return DB::table('config_unidades_fun_usuarios')
            ->where('config_unidades_fun_usuarios.id_unidad_funcional', $unidadId)
            ->join('users', 'config_unidades_fun_usuarios.id_user', '=', 'users.id')
            ->select(
                'users.id',
                'users.name as nombre',
                'users.email',
                'users.numero_identificacion',
                DB::raw("'user' as tipo"),
                DB::raw("NULL as unidad_tercero")
            )
            ->get();
    }

    /**
     * Terceros externos asignados a la unidad.
     */
    public function getTercerosPorUnidad(int $unidadId): Collection
    {
        return DB::table('config_unidades_fun_terceros')
            ->where('config_unidades_fun_terceros.id_unidad_funcional', $unidadId)
            ->join('config_person_tercero', 'config_unidades_fun_terceros.id_tercero', '=', 'config_person_tercero.id')
            ->select(
                'config_person_tercero.id',
                'config_person_tercero.nombre',
                'config_person_tercero.email',
                'config_person_tercero.numero_identificacion',
                DB::raw("'tercero' as tipo"),
                'config_person_tercero.unidad as unidad_tercero'
            )
            ->get();
    }

    /**
     * Asigna un tercero a una unidad funcional.
     */
    public function asignarTerceroAUnidad(int $unidadId, int $terceroId): bool
    {
        $yaExiste = DB::table('config_unidades_fun_terceros')
            ->where('id_unidad_funcional', $unidadId)
            ->where('id_tercero', $terceroId)
            ->exists();

        if ($yaExiste) return false;

        DB::table('config_unidades_fun_terceros')->insert([
            'id_unidad_funcional' => $unidadId,
            'id_tercero'          => $terceroId,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        return true;
    }

    /**
     * Desasigna un tercero de una unidad funcional.
     */
    public function desasignarTerceroDeUnidad(int $unidadId, int $terceroId): bool
    {
        return DB::table('config_unidades_fun_terceros')
            ->where('id_unidad_funcional', $unidadId)
            ->where('id_tercero', $terceroId)
            ->delete() > 0;
    }

    /**
     * Resuelve el id_unidad_funcional desde el texto de unidad del tercero.
     */
    public function resolverUnidadDesdeTextoTercero(string $unidadTerceroTexto, int $empresaId): ?int
    {
        $textoNormalizado = trim(preg_replace('/\s+/', ' ', mb_strtoupper($unidadTerceroTexto)));

        $mapa = DB::table('turnos_tercero_unidad_map')
            ->whereRaw('UPPER(TRIM(unidad_tercero)) = ?', [$textoNormalizado])
            ->where('id_empresa', $empresaId)
            ->first();

        return $mapa ? (int) $mapa->id_unidad_funcional : null;
    }

    /**
     * Guarda un mapeo texto-tercero → unidad funcional propia.
     */
    public function guardarMapeoUnidad(string $unidadTerceroTexto, int $empresaId, int $idUnidadFuncional, int $creadoPor): bool
    {
        $textoNormalizado = trim(preg_replace('/\s+/', ' ', mb_strtoupper($unidadTerceroTexto)));

        try {
            DB::table('turnos_tercero_unidad_map')->updateOrInsert(
                ['unidad_tercero' => $textoNormalizado, 'id_empresa' => $empresaId],
                ['id_unidad_funcional' => $idUnidadFuncional, 'creado_por' => $creadoPor, 'updated_at' => now(), 'created_at' => now()]
            );
            return true;
        } catch (\Exception $e) {
            Log::error('Error guardando mapeo de unidad', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Lista unidades del tenant que aún NO tienen mapeo.
     */
    public function getUnidadesTerceroSinMapeo(int $empresaId): Collection
    {
        $yaMapados = DB::table('turnos_tercero_unidad_map')
            ->where('id_empresa', $empresaId)
            ->pluck('unidad_tercero')
            ->map(fn($u) => mb_strtoupper(trim($u)));

        return DB::table('config_person_tercero')
            ->where('id_empresa', $empresaId)
            ->whereNotNull('unidad')
            ->where('unidad', '!=', '')
            ->select(DB::raw('UPPER(TRIM(unidad)) as unidad_normalizada'), DB::raw('COUNT(*) as total_empleados'))
            ->groupBy('unidad_normalizada')
            ->get()
            ->filter(fn($row) => !$yaMapados->contains($row->unidad_normalizada))
            ->values();
    }
}
