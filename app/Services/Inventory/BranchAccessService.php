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
     * Limpiar cache de permisos de un usuario (llamar al cambiar sucursal).
     */
    public function clearCache(int $userId): void
    {
        Cache::forget("inv_branches_user:{$userId}");
    }
}
