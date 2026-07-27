<?php

namespace App\Http\Controllers\Tableros;

use App\Http\Controllers\Controller;
use App\Services\Fabric\FabricConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tablero de Urgencias — Vista [UG].[VW_HC_TableroUrgencias]
 *
 * Requiere: auth:api + rol "Tablero"
 * Filtra automáticamente por la sucursal del usuario autenticado.
 */
class TableroUrgenciasController extends Controller
{
    private const VIEW = '[UG].[VW_HC_TableroUrgencias]';
    private const CACHE_TTL = 30; // segundos

    public function __construct(
        private FabricConnectionService $fabric
    ) {}

    /**
     * GET /api/tableros/urgencias
     *
     * Retorna datos filtrados por la sucursal del usuario.
     */
    public function index(): JsonResponse
    {
        $user = auth('api')->user();

        // Validar rol "Tablero"
        $roles = $user->rolesCustom->pluck('nombre')->map(fn($r) => strtolower($r))->toArray();
        if (!in_array('tablero', $roles)) {
            return response()->json([
                'success' => false,
                'message' => 'No tiene permisos para acceder al tablero de urgencias.',
            ], 403);
        }

        // Obtener sucursal del usuario para filtrar
        $sucursal = $user->sucursal;
        $sucursalNombre = $sucursal?->nombre;

        try {
            $cacheKey = 'tablero_urgencias_' . ($sucursalNombre ?? 'all');

            $data = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($sucursalNombre) {
                if ($sucursalNombre) {
                    $sql = "SELECT * FROM " . self::VIEW . " WHERE Sede = ? ORDER BY Unidad";
                    return $this->fabric->query($sql, [$sucursalNombre]);
                }
                return $this->fabric->query("SELECT * FROM " . self::VIEW . " ORDER BY Sede, Unidad");
            });

            return response()->json([
                'success'  => true,
                'data'     => $data,
                'sucursal' => $sucursalNombre,
            ]);
        } catch (\Exception $e) {
            Log::error('TableroUrgencias: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error consultando tablero de urgencias.',
            ], 503);
        }
    }
}
