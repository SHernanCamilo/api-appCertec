<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tableros;

use App\Http\Controllers\Controller;
use App\Models\TableroDevice;
use App\Models\TableroToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD de dispositivos de tablero (requiere auth:api).
 *
 * Permite crear tableros (genera código de emparejamiento), listar TVs
 * conectadas, y revocar acceso.
 */
final class TableroTokenController extends Controller
{
    /**
     * GET /api/tableros/tokens — Listar todos los dispositivos
     */
    public function index(): JsonResponse
    {
        $devices = TableroDevice::orderByDesc('created_at')
            ->get([
                'id', 'name', 'schema_name', 'view_name', 'sede_filter',
                'paired', 'active', 'pairing_code', 'pairing_expires_at',
                'last_seen_at', 'last_ip', 'user_agent',
                'connection_count', 'max_connections', 'created_at',
            ]);

        // Mostrar el código solo si aún no fue emparejado y está vigente
        $data = $devices->map(function ($d) {
            $arr = $d->toArray();
            if ($d->paired || ($d->pairing_expires_at && $d->pairing_expires_at->isPast())) {
                $arr['pairing_code'] = null;
            }
            return $arr;
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * POST /api/tableros/tokens — Crear un nuevo tablero (genera código de emparejamiento)
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'            => 'required|string|max:150',
            'schema_name'     => 'nullable|string|max:10',
            'view_name'       => 'nullable|string|max:150',
            'sede_filter'     => 'nullable|string|max:100',
            'max_connections' => 'nullable|integer|min:1|max:10',
        ]);

        $code = TableroDevice::generatePairingCode();

        $device = TableroDevice::create([
            'pairing_code'       => $code,
            'pairing_expires_at' => now()->addMinutes(5),
            'name'               => $request->name,
            'schema_name'        => $request->input('schema_name', 'ug'),
            'view_name'          => $request->input('view_name', 'VW_HC_TableroUrgencias'),
            'sede_filter'        => $request->sede_filter,
            'max_connections'    => $request->input('max_connections', 2),
            'created_by'         => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'           => $device->id,
                'name'         => $device->name,
                'pairing_code' => $code,
                'expires_in'   => '5 minutos',
                'sede_filter'  => $device->sede_filter,
                'instructions' => "En la TV, navegue a jade.medilaser.com.co/tablero e ingrese el código: {$code}",
            ],
        ], 201);
    }

    /**
     * POST /api/tableros/tokens/{id}/regenerate-code — Generar nuevo código (si la TV no emparejó)
     */
    public function regenerateCode(int $id): JsonResponse
    {
        $device = TableroDevice::findOrFail($id);

        if ($device->paired) {
            return response()->json([
                'success' => false,
                'message' => 'Este dispositivo ya fue emparejado. Revóquelo y cree uno nuevo si necesita re-emparejar.',
            ], 422);
        }

        $code = TableroDevice::generatePairingCode();
        $device->update([
            'pairing_code'       => $code,
            'pairing_expires_at' => now()->addMinutes(5),
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'pairing_code' => $code,
                'expires_in'   => '5 minutos',
            ],
        ]);
    }

    /**
     * PATCH /api/tableros/tokens/{id}/revoke — Revocar acceso de una TV
     */
    public function revoke(int $id): JsonResponse
    {
        $device = TableroDevice::findOrFail($id);
        $device->update(['active' => false]);

        return response()->json([
            'success' => true,
            'message' => "Tablero '{$device->name}' revocado. La TV dejará de recibir datos.",
        ]);
    }

    /**
     * PATCH /api/tableros/tokens/{id}/activate — Reactivar una TV
     */
    public function activate(int $id): JsonResponse
    {
        $device = TableroDevice::findOrFail($id);
        $device->update(['active' => true]);

        return response()->json([
            'success' => true,
            'message' => "Tablero '{$device->name}' reactivado.",
        ]);
    }

    /**
     * DELETE /api/tableros/tokens/{id} — Eliminar permanentemente
     */
    public function destroy(int $id): JsonResponse
    {
        $device = TableroDevice::findOrFail($id);
        $device->delete();

        return response()->json([
            'success' => true,
            'message' => "Tablero '{$device->name}' eliminado permanentemente.",
        ]);
    }
}
