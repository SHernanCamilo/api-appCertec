<?php

namespace App\Http\Controllers\TalentoHumano;

use App\Http\Controllers\Controller;
use App\Models\Workflow\WfAprobador;
use App\Models\Workflow\WfDefinicion;
use App\Models\Workflow\WfGrupo;
use App\Models\Workflow\WfModulo;
use App\Models\Workflow\WfRegla;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CRUD de Grupos de UF para el motor de flujos.
 * Reutiliza la tabla wf_grupos + pivot wf_grupo_unidades.
 */
class WfGrupoUfController extends Controller
{
    /**
     * Listar grupos activos (con sus UFs y empresa).
     */
    public function index(Request $request): JsonResponse
    {
        $query = WfGrupo::with(['unidadesFuncionales:id,codigo,nombre', 'empresa:id,nombre'])
            ->whereHas('unidadesFuncionales') // Solo grupos que tienen UFs asignadas
            ->when($request->filled('empresa_id'), fn($q) => $q->where('id_empresa', (int)$request->empresa_id))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $request->search;
                $q->where('nombre', 'like', "%{$term}%");
            })
            ->orderBy('nombre');

        if ($request->boolean('todos')) {
            return response()->json(['success' => true, 'data' => $query->get()]);
        }

        $paginated = $query->paginate($request->input('per_page', 15));
        return response()->json([
            'success'      => true,
            'data'         => $paginated->items(),
            'total'        => $paginated->total(),
            'current_page' => $paginated->currentPage(),
            'per_page'     => $paginated->perPage(),
            'last_page'    => $paginated->lastPage(),
        ]);
    }

    /**
     * Detalle de un grupo con UFs, flujo asignado y aprobadores.
     */
    public function show(int $id): JsonResponse
    {
        $grupo = WfGrupo::with(['unidadesFuncionales:id,codigo,nombre', 'empresa:id,nombre'])->findOrFail($id);

        // Buscar flujo asignado al grupo via wf_reglas (condiciones.id_grupo = $id)
        $flujo = $this->resolverFlujoGrupo($id);
        $responsables = [];

        if ($flujo) {
            $pasoIds = $flujo->pasos->pluck('id');
            $responsables = WfAprobador::whereIn('id_paso', $pasoIds)
                ->where('estado', true)
                ->whereNotNull('id_user')
                ->whereRaw("JSON_EXTRACT(condiciones, '$.id_grupo') = ?", [$id])
                ->get(['id_paso', 'id_user'])
                ->groupBy('id_paso')
                ->map(fn($items) => $items->pluck('id_user')->all())
                ->all();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'grupo'        => $grupo,
                'flujo_id'     => $flujo?->id,
                'flujo_nombre' => $flujo?->nombre,
                'pasos'        => $flujo?->pasos->map(fn($p) => [
                    'id'             => $p->id,
                    'orden'          => $p->orden,
                    'nombre_paso'    => $p->nombre_paso,
                    'rol_aprobador'  => $p->rol_aprobador,
                    'aprobadores'    => $responsables[$p->id] ?? [],
                ])->values() ?? [],
            ],
        ]);
    }

    /**
     * Crear un grupo nuevo y asignarle UFs.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'nombre'                  => $this->reglaNombreUnico($request),
            'descripcion'             => 'nullable|string|max:255',
            'id_empresa'              => 'nullable|integer',
            'unidades_funcionales'    => 'required|array|min:1',
            'unidades_funcionales.*'  => 'integer',
        ]);

        return DB::transaction(function () use ($request) {
            $grupo = WfGrupo::create([
                'nombre'      => $request->nombre,
                'descripcion' => $request->descripcion,
                'id_empresa'  => $request->id_empresa,
                'estado'      => true,
            ]);

            $grupo->unidadesFuncionales()->sync($request->unidades_funcionales);

            $grupo->load('unidadesFuncionales:id,codigo,nombre');

            return response()->json([
                'success' => true,
                'message' => 'Grupo creado exitosamente',
                'data'    => $grupo,
            ], 201);
        });
    }

    /**
     * Actualizar grupo y sus UFs.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $grupo = WfGrupo::findOrFail($id);

        $request->validate([
            'nombre'                  => $this->reglaNombreUnico($request, $id, false),
            'descripcion'             => 'nullable|string|max:255',
            'id_empresa'              => 'nullable|integer',
            'unidades_funcionales'    => 'sometimes|array|min:1',
            'unidades_funcionales.*'  => 'integer',
        ]);

        return DB::transaction(function () use ($request, $grupo) {
            $grupo->update($request->only(['nombre', 'descripcion', 'id_empresa']));

            if ($request->has('unidades_funcionales')) {
                $grupo->unidadesFuncionales()->sync($request->unidades_funcionales);
            }

            $grupo->load('unidadesFuncionales:id,codigo,nombre');

            return response()->json([
                'success' => true,
                'message' => 'Grupo actualizado',
                'data'    => $grupo,
            ]);
        });
    }

    /**
     * Asignar flujo y aprobadores a un grupo.
     * Esto globaliza el flujo para todas las UFs del grupo.
     */
    public function asignarFlujo(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'flujo_id'                    => 'required|integer',
            'aprobadores'                 => 'array',
            'aprobadores.*.id_paso'       => 'required|integer',
            'aprobadores.*.id_user'       => 'required|integer',
        ]);

        $grupo = WfGrupo::findOrFail($id);
        $flujo = WfDefinicion::with(['pasos' => fn($q) => $q->where('estado', true)->orderBy('orden'), 'modulo'])
            ->findOrFail($request->flujo_id);

        if ($flujo->modulo?->codigo !== 'eventos') {
            return response()->json([
                'success' => false,
                'message' => 'El flujo seleccionado no pertenece al módulo de eventos.',
            ], 422);
        }

        return DB::transaction(function () use ($request, $grupo, $flujo) {
            // 1. Desactivar reglas anteriores de este grupo
            $this->desactivarReglasGrupo($grupo->id);

            // 2. Crear/activar regla: condiciones.id_grupo = grupo.id
            $this->activarReglaGrupo($flujo->id, $grupo->id);

            // 3. Para cada paso: desactivar aprobadores anteriores del grupo y crear nuevos
            $aprobadoresMap = collect($request->aprobadores)
                ->filter(fn($a) => !empty($a['id_paso']) && !empty($a['id_user']));

            foreach ($flujo->pasos as $paso) {
                // Desactivar aprobadores anteriores del grupo para este paso
                WfAprobador::where('id_paso', $paso->id)
                    ->where('estado', true)
                    ->whereRaw("JSON_EXTRACT(condiciones, '$.id_grupo') = ?", [$grupo->id])
                    ->update(['estado' => false]);

                // Crear nuevos aprobadores con la condición del grupo
                $aprobadoresPaso = $aprobadoresMap->where('id_paso', $paso->id);
                foreach ($aprobadoresPaso as $cfg) {
                    WfAprobador::create([
                        'id_paso'       => $paso->id,
                        'tipo_aprobador'=> 'USER',
                        'id_user'       => (int) $cfg['id_user'],
                        'es_suplente'   => false,
                        'condiciones'   => ['id_grupo' => $grupo->id],
                        'estado'        => true,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Flujo y aprobadores asignados al grupo',
                'data'    => [
                    'grupo_id' => $grupo->id,
                    'flujo_id' => $flujo->id,
                ],
            ]);
        });
    }

    /**
     * Eliminar (desactivar) un grupo.
     */
    public function destroy(int $id): JsonResponse
    {
        $grupo = WfGrupo::findOrFail($id);
        $grupo->update(['estado' => false]);

        return response()->json(['success' => true, 'message' => 'Grupo desactivado']);
    }

    // ─── Métodos privados ─────────────────────────────────────────────────────

    private function reglaNombreUnico(Request $request, ?int $ignoreId = null, bool $required = true): array
    {
        $rule = Rule::unique('wf_grupos', 'nombre')->where(function ($query) use ($request) {
            if ($request->filled('id_empresa')) {
                $query->where('id_empresa', (int) $request->id_empresa);
            } else {
                $query->whereNull('id_empresa');
            }
        });

        if ($ignoreId) {
            $rule->ignore($ignoreId);
        }

        $rules = ['string', 'max:150', $rule];
        array_unshift($rules, $required ? 'required' : 'sometimes');

        return $rules;
    }

    private function resolverFlujoGrupo(int $grupoId): ?WfDefinicion
    {
        $regla = WfRegla::where('estado', true)
            ->whereRaw("JSON_EXTRACT(condiciones, '$.id_grupo') = ?", [$grupoId])
            ->with(['definicion' => fn($q) => $q->with(['modulo', 'pasos' => fn($p) => $p->where('estado', true)->orderBy('orden')])])
            ->orderBy('prioridad')
            ->first();

        if ($regla && $regla->definicion && optional($regla->definicion->modulo)->codigo === 'eventos') {
            return $regla->definicion;
        }

        return null;
    }

    private function desactivarReglasGrupo(int $grupoId): void
    {
        $modulo = WfModulo::where('codigo', 'eventos')->first();
        if (!$modulo) return;

        $idsEventos = WfDefinicion::where('id_modulo', $modulo->id)->pluck('id');
        if ($idsEventos->isEmpty()) return;

        WfRegla::whereIn('id_definicion', $idsEventos)
            ->where('estado', true)
            ->whereRaw("JSON_EXTRACT(condiciones, '$.id_grupo') = ?", [$grupoId])
            ->update(['estado' => false]);
    }

    private function activarReglaGrupo(int $flujoId, int $grupoId): void
    {
        $existente = WfRegla::where('id_definicion', $flujoId)
            ->whereRaw("JSON_EXTRACT(condiciones, '$.id_grupo') = ?", [$grupoId])
            ->first();

        if ($existente) {
            $existente->update([
                'estado'      => true,
                'prioridad'   => 10,
                'condiciones' => ['id_grupo' => $grupoId],
            ]);
            return;
        }

        WfRegla::create([
            'id_definicion' => $flujoId,
            'prioridad'     => 10,
            'condiciones'   => ['id_grupo' => $grupoId],
            'estado'        => true,
        ]);
    }
}
