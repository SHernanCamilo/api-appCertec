<?php

namespace App\Services\Inventory;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Servicio de control de acceso por sucursal/almacén para Inventario.
 *
 * Determina qué sucursales y almacenes puede ver/operar un usuario
 * basado en su sucursal asignada y permisos específicos.
 */
class BranchAccessService
{
    /**
     * Mapa Sucursal (prefijo) → Almacén físico principal en Indigo.
     * Portado del legacy (orders.js BRANCH_WAREHOUSE_RULES). El pedido se hace
     * contra el almacén principal de la sucursal a la que pertenece el usuario.
     */
    public const BRANCH_WAREHOUSE_RULES = [
        'NVA' => ['warehouse' => 'ALMACEN NVA PPAL',              'code' => '100'],
        'FLA' => ['warehouse' => 'ALMACEN PPAL FLA P-1',          'code' => '102'],
        'TJA' => ['warehouse' => 'ALMACEN PPAL TJA SEDE EXTERNA', 'code' => '104'],
        'KTA' => ['warehouse' => 'ALMACEN PPAL KTA P-1',          'code' => '600'],
        'DTA' => ['warehouse' => 'ALMACEN HEMODINAMIA DTA P1',    'code' => '700'],
        'PTO' => ['warehouse' => 'ALMACEN PPAL PTO P1',           'code' => '103'],
    ];

    /**
     * Devuelve el almacén asociado a una sucursal según su prefijo.
     * @return array{warehouse:string, code:string}|null
     */
    public function getAlmacenPorSucursal(int $sucursalId): ?array
    {
        $prefijo = strtoupper(trim($this->getBranchCode($sucursalId)));
        if ($prefijo === '') {
            return null;
        }

        return self::BRANCH_WAREHOUSE_RULES[$prefijo] ?? null;
    }

    /**
     * Obtener las sucursales permitidas para un usuario.
     * Un usuario puede ver su propia sucursal + sucursales delegadas.
     */
    public function getAllowedBranches(int $userId): array
    {
        return Cache::remember("inv_branches_user:{$userId}", 300, function () use ($userId) {
            $user = User::find($userId);
            if (!$user) return [];

            $branches = [];

            // Sucursal principal del usuario
            if ($user->id_sucursal) {
                $branches[] = (int) $user->id_sucursal;
            }

            // Sucursales adicionales por empresa (pivote seg_empresa_user).
            // La columna real en el pivote es 'id_sucursal' (ver User::empresas()).
            $empresaSucursales = DB::table('seg_empresa_user')
                ->where('user_id', $userId)
                ->pluck('id_sucursal')
                ->filter()
                ->map(fn($id) => (int) $id)
                ->toArray();

            $branches = array_unique(array_merge($branches, $empresaSucursales));

            return $branches;
        });
    }

    /**
     * Verificar si un usuario tiene acceso a una sucursal específica.
     */
    public function hasAccessToBranch(int $userId, int $sucursalId): bool
    {
        $allowed = $this->getAllowedBranches($userId);
        return in_array($sucursalId, $allowed);
    }

    /**
     * Obtener la sucursal activa del usuario (la principal).
     */
    public function getActiveBranchId(int $userId): int
    {
        $user = User::find($userId);
        return $user ? (int) ($user->id_sucursal ?? 0) : 0;
    }

    /**
     * Obtener código de sucursal (ej: "FLA", "NVA", "TJA").
     */
    public function getBranchCode(int $sucursalId): string
    {
        // La tabla real de sucursales es config_ubi_sucursales y el código está en 'prefijo'.
        $sucursal = DB::table('config_ubi_sucursales')->where('id', $sucursalId)->first();
        return $sucursal->prefijo ?? '';
    }

    /**
     * Verificar si un usuario puede operar en un almacén específico.
     * Los almacenes están asociados a sucursales.
     */
    public function canAccessWarehouse(int $userId, string $codigoAlmacen): bool
    {
        $branches = $this->getAllowedBranches($userId);
        if (empty($branches)) return false;

        // El código de almacén típicamente inicia con el código de sucursal
        // Ej: "FLA-FARMACIA", "NVA-BODEGA"
        $branchCodes = [];
        foreach ($branches as $branchId) {
            $code = $this->getBranchCode($branchId);
            if ($code) $branchCodes[] = strtoupper($code);
        }

        $almacenUpper = strtoupper($codigoAlmacen);
        foreach ($branchCodes as $code) {
            if (str_starts_with($almacenUpper, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indica si el usuario tiene un rol administrador (es_admin) activo.
     * Los admin no tienen filas en seg_empresa_user pero acceden a todo.
     */
    public function esUsuarioAdmin(int $userId): bool
    {
        return DB::table('seg_rol_user as ru')
            ->join('seg_roles_custom as r', 'r.id', '=', 'ru.rol_id')
            ->where('ru.user_id', $userId)
            ->where('r.estado', true)
            ->where('r.es_admin', true)
            ->exists();
    }

    /**
     * Lista las sucursales disponibles para el usuario en el módulo de inventario,
     * respetando sus permisos (seg_empresa_user) e incluyendo el almacén asociado.
     *
     * Reglas:
     *   - Admin (es_admin) o fila con id_sucursal=NULL+recursivo=1 → todas las sucursales.
     *   - Filas con id_sucursal=X → solo esas sucursales.
     *   - Solo se ofrecen sucursales con secuencia de inventario parametrizada.
     *
     * @return array{success:bool, data:array, meta:array}
     */
    public function getSucursalesDisponibles(int $userId): array
    {
        $empresaId = (int) config('inventory.empresa_id', 1);

        // Sucursales con secuencia de inventario parametrizada (fuente de verdad).
        $conSecuencia = DB::table('config_sec_detalles as d')
            ->join('config_sec_secuencias as s', 's.id', '=', 'd.secuencia_id')
            ->join('seg_modulos as m', 'm.id', '=', 's.modulo_id')
            ->where('s.empresa_id', $empresaId)
            ->where('m.codigo', 'INV')
            ->whereNull('d.deleted_at')
            ->where('d.estado', true)
            ->whereNotNull('d.sucursal_id')
            ->pluck('d.sucursal_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->all();

        $query = \App\Models\Sucursal::where('id_Empresa', $empresaId);
        if (!empty($conSecuencia)) {
            $query->whereIn('id', $conSecuencia);
        } else {
            $query->whereNotNull('prefijo')->whereRaw("TRIM(prefijo) <> ''");
        }

        // Permisos del usuario.
        $accesoTotal = $this->esUsuarioAdmin($userId);
        $permitidas = [];
        if (!$accesoTotal) {
            $filas = DB::table('seg_empresa_user')
                ->where('user_id', $userId)
                ->where('empresa_id', $empresaId)
                ->get(['id_sucursal', 'recursivo']);
            foreach ($filas as $fila) {
                if ($fila->id_sucursal === null && (int) $fila->recursivo === 1) {
                    $accesoTotal = true;
                    break;
                }
                if ($fila->id_sucursal !== null) {
                    $permitidas[] = (int) $fila->id_sucursal;
                }
            }
        }
        if (!$accesoTotal) {
            $query->whereIn('id', $permitidas ?: [0]);
        }

        $sucursales = $query->orderBy('nombre')->get(['id', 'nombre', 'prefijo', 'id_Empresa']);

        $user = User::find($userId);
        $principal = (int) ($user->id_sucursal ?? 0);

        $data = $sucursales->map(function ($s) use ($principal) {
            $almacen = $this->getAlmacenPorSucursal((int) $s->id);
            return [
                'id'             => (int) $s->id,
                'nombre'         => $s->nombre,
                'prefijo'        => $s->prefijo,
                'principal'      => (int) $s->id === $principal,
                'almacen'        => $almacen['warehouse'] ?? null,
                'almacen_codigo' => $almacen['code'] ?? null,
            ];
        })->values();

        return [
            'success' => true,
            'data'    => $data,
            'meta'    => ['acceso_total' => $accesoTotal, 'total' => $data->count()],
        ];
    }

    /**
     * Devuelve los ids de sucursal a los que el usuario tiene acceso para filtrar
     * listados (pedidos, OC, recepciones). Retorna null cuando el usuario tiene
     * acceso total (admin o recursivo), es decir, no se debe filtrar.
     *
     * @return array<int>|null
     */
    public function getSucursalIdsPermitidas(int $userId): ?array
    {
        if ($this->esUsuarioAdmin($userId)) {
            return null; // acceso total
        }

        $empresaId = (int) config('inventory.empresa_id', 1);
        $permitidas = [];
        $filas = DB::table('seg_empresa_user')
            ->where('user_id', $userId)
            ->where('empresa_id', $empresaId)
            ->get(['id_sucursal', 'recursivo']);

        foreach ($filas as $fila) {
            if ($fila->id_sucursal === null && (int) $fila->recursivo === 1) {
                return null; // acceso total (recursivo)
            }
            if ($fila->id_sucursal !== null) {
                $permitidas[] = (int) $fila->id_sucursal;
            }
        }

        // Incluir la sucursal principal del usuario, por si no está en el pivote.
        $user = User::find($userId);
        if ($user && $user->id_sucursal) {
            $permitidas[] = (int) $user->id_sucursal;
        }

        return array_values(array_unique($permitidas));
    }

    /**
     * Verifica si un usuario tiene acceso a una sucursal (admin, recursivo total, o explícita).
     */
    public function usuarioTieneAccesoSucursal(int $userId, int $sucursalId): bool
    {
        if ($this->esUsuarioAdmin($userId)) {
            return true;
        }
        $empresaId = (int) config('inventory.empresa_id', 1);
        $filas = DB::table('seg_empresa_user')
            ->where('user_id', $userId)
            ->where('empresa_id', $empresaId)
            ->get(['id_sucursal', 'recursivo']);
        foreach ($filas as $fila) {
            if ($fila->id_sucursal === null && (int) $fila->recursivo === 1) {
                return true;
            }
            if ((int) $fila->id_sucursal === $sucursalId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Limpiar cache de permisos de un usuario (llamar al cambiar sucursal).
     */
    public function clearCache(int $userId): void
    {
        Cache::forget("inv_branches_user:{$userId}");
    }
}
