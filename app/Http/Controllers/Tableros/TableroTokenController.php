<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tableros;

use App\Http\Controllers\Controller;
use App\Models\TableroToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD de tokens de tablero (requiere autenticación + rol admin).
 *
 * Permite crear, listar, revocar y ver el estado de las TVs conectadas.
 */
final class TableroTokenController extends Controller
{
    /**
     * GET /api/tableros/tokens — Listar todos los tokens
     */
    public function index(): JsonResponse
    {
        $tokens = TableroToken::orderByDesc('created_at')
            ->get([
                'id', 'name', 'schema_name', 'view_name', 'sede_filter',
                'active', 'expires_at', 'last_used_at', 'last_ip',
                'use_count', 'max_connections', 'created_at',
            ]);

        return response()->json([
            'success' => true,
            'data'    => $tokens,
        ]);
    }

    /**
     * POST /api/tableros/tokens — Crear un nuevo token
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'            => 'required|string|max:150',
            'schema_name'     => 'nullable|string|max:10',
            'view_name'       => 'nullable|string|max:150',
            'sede_filter'     => 'nullable|string|max:100',
            'max_connections' => 'nullable|integer|min:1|max:10',
            'expires_days'    => 'nullable|integer|min:1|max:365',
        ]);

        $plainToken = TableroToken::generateToken();

        $tableroToken = TableroToken::create([
            'token'           => $plainToken,
            'name'            => $request->name,
            'schema_name'     => $request->input('schema_name', 'ug'),
            'view_name'       => $request->input('view_name', 'VW_HC_TableroUrgencias'),
            'sede_filter'     => $request->sede_filter,
            'max_connections' => $request->input('max_connections', 3),
            'expires_at'      => $request->expires_days
                ? now()->addDays((int) $request->expires_days)
                : null,
            'created_by'      => auth()->id(),
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'              => $tableroToken->id,
                'name'            => $tableroToken->name,
                'token'           => $plainToken, // ⚠️ Solo se muestra UNA VEZ
                'sede_filter'     => $tableroToken->sede_filter,
                'stream_url'      => url("/api/public/tableros/urgencias/stream?token={$plainToken}"),
                'data_url'        => url("/api/public/tableros/urgencias/data?token={$plainToken}"),
                'frontend_url'    => "https://jade.medilaser.com.co/tableroUrgencias?token={$plainToken}",
                'expires_at'      => $tableroToken->expires_at?->toIso8601String(),
                'max_connections' => $tableroToken->max_connections,
            ],
            'warning' => 'Guarda este token. No se puede recuperar después.',
        ], 201);
    }

    /**
     * PATCH /api/tableros/tokens/{id}/revoke — Revocar un token
     */
    public function revoke(int $id): JsonResponse
    {
        $token = TableroToken::findOrFail($id);
        $token->update(['active' => false]);

        return response()->json([
            'success' => true,
            'message' => "Token '{$token->name}' revocado. La TV dejará de recibir datos.",
        ]);
    }

    /**
     * PATCH /api/tableros/tokens/{id}/activate — Reactivar un token
     */
    public function activate(int $id): JsonResponse
    {
        $token = TableroToken::findOrFail($id);
        $token->update(['active' => true]);

        return response()->json([
            'success' => true,
            'message' => "Token '{$token->name}' reactivado.",
        ]);
    }

    /**
     * DELETE /api/tableros/tokens/{id} — Eliminar permanentemente
     */
    public function destroy(int $id): JsonResponse
    {
        $token = TableroToken::findOrFail($id);
        $token->delete();

        return response()->json([
            'success' => true,
            'message' => "Token '{$token->name}' eliminado permanentemente.",
        ]);
    }
}
