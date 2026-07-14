<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\BiVista;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BiVistaPermissionController extends Controller
{
    /**
     * Lista los usuarios que tienen permiso de actualizar una vista desde Excel.
     */
    public function index($idVista): JsonResponse
    {
        $vista = BiVista::findOrFail($idVista);

        $usuarios = $vista->usuariosConPermisoOData()->get(['users.id', 'users.name', 'users.email']);

        return response()->json([
            'success' => true,
            'data' => $usuarios,
        ]);
    }

    /**
     * Asigna el permiso a un usuario para actualizar la vista.
     */
    public function store(Request $request, $idVista): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $vista = BiVista::findOrFail($idVista);
        $user = User::findOrFail($request->user_id);

        DB::table('bi_vista_user_permissions')->updateOrInsert(
            ['bi_vista_id' => $vista->id, 'user_id' => $user->id],
            ['created_at' => now(), 'updated_at' => now()]
        );

        return response()->json([
            'success' => true,
            'message' => 'Permiso asignado correctamente.',
        ]);
    }

    /**
     * Revoca el permiso de un usuario para actualizar la vista.
     */
    public function destroy($idVista, $idUser): JsonResponse
    {
        $vista = BiVista::findOrFail($idVista);

        DB::table('bi_vista_user_permissions')
            ->where('bi_vista_id', $vista->id)
            ->where('user_id', $idUser)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Permiso revocado correctamente.',
        ]);
    }
}
