<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\BiGrupo;
use App\Models\BiVista;
use App\Models\BiVistaDelegacion;
use App\Models\BiVistaDelegacionUsuario;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BiDelegacionController extends Controller
{
    /**
     * GET /api/fabric/bi-grupos/{id}/delegaciones?empresa_id=X
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'empresa_id' => 'required|exists:ent_empresas,id',
        ]);

        try {
            $grupo     = BiGrupo::findOrFail($id);
            $empresaId = (int) $request->empresa_id;

            $vistas = BiVista::query()
                ->where('id_bi_grupos', $grupo->id)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'descripcion', 'estado']);

            $delegadas = BiVistaDelegacion::query()
                ->where('empresa_id', $empresaId)
                ->where('id_bi_grupos', $grupo->id)
                ->pluck('id_bi_vista')
                ->all();

            $delegadasSet = array_flip($delegadas);
            $tieneConfig  = count($delegadas) > 0;

            $items = $vistas->map(fn (BiVista $v) => [
                'id'        => $v->id,
                'nombre'    => $v->nombre,
                'descripcion' => $v->descripcion,
                'estado'    => $v->estado,
                'delegada'  => isset($delegadasSet[$v->id]),
            ]);

            return response()->json([
                'success' => true,
                'data'    => [
                    'empresa_id'    => $empresaId,
                    'id_bi_grupos'  => $grupo->id,
                    'schema'        => $grupo->codigo,
                    'tiene_config'  => $tieneConfig,
                    'vista_ids'     => array_values($delegadas),
                    'vistas'        => $items,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al consultar delegación', $e);
        }
    }

    /**
     * PUT /api/fabric/bi-grupos/{id}/delegaciones
     * Body: { empresa_id, vista_ids: number[] }
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'empresa_id'   => 'required|exists:ent_empresas,id',
            'vista_ids'    => 'present|array',
            'vista_ids.*'  => 'integer|exists:bi_vistas,id',
        ]);

        try {
            $grupo     = BiGrupo::findOrFail($id);
            $empresaId = (int) $request->empresa_id;
            $vistaIds  = array_values(array_unique(array_map('intval', $request->input('vista_ids', []))));

            $validIds = BiVista::query()
                ->where('id_bi_grupos', $grupo->id)
                ->whereIn('id', $vistaIds)
                ->pluck('id')
                ->all();

            DB::transaction(function () use ($empresaId, $grupo, $validIds) {
                BiVistaDelegacion::query()
                    ->where('empresa_id', $empresaId)
                    ->where('id_bi_grupos', $grupo->id)
                    ->delete();

                foreach ($validIds as $vistaId) {
                    BiVistaDelegacion::create([
                        'empresa_id'    => $empresaId,
                        'id_bi_grupos'  => $grupo->id,
                        'id_bi_vista'   => $vistaId,
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => count($validIds) . ' vista(s) delegada(s) a la empresa',
                'data'    => [
                    'empresa_id'   => $empresaId,
                    'id_bi_grupos' => $grupo->id,
                    'vista_ids'    => $validIds,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al guardar delegación', $e);
        }
    }

    /**
     * GET /api/fabric/bi-grupos/{id}/delegaciones-usuarios?empresa_id=X&user_id=Y
     */
    public function showUsuario(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'empresa_id' => 'required|exists:ent_empresas,id',
            'user_id'    => 'required|exists:users,id',
        ]);

        try {
            $grupo     = BiGrupo::findOrFail($id);
            $empresaId = (int) $request->empresa_id;
            $userId    = (int) $request->user_id;

            $pertenece = User::query()
                ->where('id', $userId)
                ->whereHas('empresas', fn ($q) => $q->where('ent_empresas.id', $empresaId))
                ->exists();

            if (!$pertenece) {
                return response()->json([
                    'success' => false,
                    'message' => 'El usuario no pertenece a la empresa seleccionada.',
                ], 422);
            }

            $poolIds = $this->resolvePoolVistaIds($grupo, $empresaId);
            $esMisma   = $this->esMismaEmpresaEsquema($grupo, $empresaId);
            $empresaTieneConfig = $esMisma ? count($poolIds) > 0 : count($poolIds) > 0;

            $vistasPool = BiVista::query()
                ->where('id_bi_grupos', $grupo->id)
                ->when(!$esMisma && $empresaTieneConfig, fn ($q) => $q->whereIn('id', $poolIds))
                ->when(!$esMisma && !$empresaTieneConfig, fn ($q) => $q->whereRaw('1 = 0'))
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'descripcion', 'estado']);

            $asignadas = BiVistaDelegacionUsuario::query()
                ->where('user_id', $userId)
                ->where('empresa_id', $empresaId)
                ->where('id_bi_grupos', $grupo->id)
                ->pluck('id_bi_vista')
                ->all();

            $asignadasSet = array_flip($asignadas);

            $usuario = User::find($userId, ['id', 'name', 'email']);

            $items = $vistasPool->map(fn (BiVista $v) => [
                'id'          => $v->id,
                'nombre'      => $v->nombre,
                'descripcion' => $v->descripcion,
                'estado'      => $v->estado,
                'delegada'    => isset($asignadasSet[$v->id]),
            ]);

            return response()->json([
                'success' => true,
                'data'    => [
                    'empresa_id'          => $empresaId,
                    'user_id'             => $userId,
                    'usuario'             => $usuario,
                    'id_bi_grupos'        => $grupo->id,
                    'schema'              => $grupo->codigo,
                    'es_misma_empresa'    => $esMisma,
                    'empresa_tiene_config'=> $empresaTieneConfig,
                    'tiene_config'        => count($asignadas) > 0,
                    'vista_ids'           => array_values($asignadas),
                    'vistas'              => $items,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al consultar delegación por usuario', $e);
        }
    }

    /**
     * PUT /api/fabric/bi-grupos/{id}/delegaciones-usuarios
     * Body: { empresa_id, user_id, vista_ids: number[] }
     */
    public function updateUsuario(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'empresa_id'  => 'required|exists:ent_empresas,id',
            'user_id'     => 'required|exists:users,id',
            'vista_ids'   => 'present|array',
            'vista_ids.*' => 'integer|exists:bi_vistas,id',
        ]);

        try {
            $grupo     = BiGrupo::findOrFail($id);
            $empresaId = (int) $request->empresa_id;
            $userId    = (int) $request->user_id;
            $vistaIds  = array_values(array_unique(array_map('intval', $request->input('vista_ids', []))));

            $pertenece = User::query()
                ->where('id', $userId)
                ->whereHas('empresas', fn ($q) => $q->where('ent_empresas.id', $empresaId))
                ->exists();

            if (!$pertenece) {
                return response()->json([
                    'success' => false,
                    'message' => 'El usuario no pertenece a la empresa seleccionada.',
                ], 422);
            }

            $poolIds = $this->resolvePoolVistaIds($grupo, $empresaId);
            $esMisma = $this->esMismaEmpresaEsquema($grupo, $empresaId);

            if ($poolIds === [] && !$esMisma) {
                return response()->json([
                    'success' => false,
                    'message' => 'Configure primero la delegación por empresa para esta empresa y esquema.',
                ], 422);
            }

            $validIds = BiVista::query()
                ->where('id_bi_grupos', $grupo->id)
                ->whereIn('id', $vistaIds)
                ->when($poolIds !== [], fn ($q) => $q->whereIn('id', $poolIds))
                ->pluck('id')
                ->all();

            DB::transaction(function () use ($empresaId, $grupo, $userId, $validIds) {
                // delete() de query builder no dispara eventos del modelo → limpiar caché a mano
                BiVistaDelegacionUsuario::query()
                    ->where('user_id', $userId)
                    ->where('empresa_id', $empresaId)
                    ->where('id_bi_grupos', $grupo->id)
                    ->delete();

                \Illuminate\Support\Facades\Cache::forget('bi_vista_delegacion_usuarios_index');

                foreach ($validIds as $vistaId) {
                    BiVistaDelegacionUsuario::create([
                        'user_id'      => $userId,
                        'empresa_id'   => $empresaId,
                        'id_bi_grupos' => $grupo->id,
                        'id_bi_vista'  => $vistaId,
                    ]);
                }
            });

            return response()->json([
                'success' => true,
                'message' => count($validIds) . ' vista(s) delegada(s) al usuario',
                'data'    => [
                    'empresa_id'   => $empresaId,
                    'user_id'      => $userId,
                    'id_bi_grupos' => $grupo->id,
                    'vista_ids'    => $validIds,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al guardar delegación por usuario', $e);
        }
    }

    private function error(string $message, \Exception $e, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error'   => config('app.debug') ? $e->getMessage() : null,
        ], $status);
    }

    /**
     * Delegación interna: empresa del esquema = empresa seleccionada.
     * El pool son todas las vistas del esquema (sin catálogo por empresa previo).
     */
    private function esMismaEmpresaEsquema(BiGrupo $grupo, int $empresaId): bool
    {
        return $grupo->empresa_id !== null && (int) $grupo->empresa_id === $empresaId;
    }

    /**
     * @return array<int, int>
     */
    private function resolvePoolVistaIds(BiGrupo $grupo, int $empresaId): array
    {
        if ($this->esMismaEmpresaEsquema($grupo, $empresaId)) {
            return BiVista::query()
                ->where('id_bi_grupos', $grupo->id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return BiVistaDelegacion::query()
            ->where('empresa_id', $empresaId)
            ->where('id_bi_grupos', $grupo->id)
            ->pluck('id_bi_vista')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
