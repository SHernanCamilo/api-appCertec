<?php

declare(strict_types=1);

namespace App\Http\Controllers\MesaServicio;

use App\Http\Controllers\Controller;
use App\Http\Requests\MesaServicio\StoreGlpiPlantillaRequest;
use App\Http\Requests\MesaServicio\UpdateGlpiPlantillaRequest;
use App\Models\MesaServicio\GlpiParamPlantilla;
use App\Models\MesaServicio\GlpiParamPlantillaAns;
use App\Models\MesaServicio\GlpiParamPlantillaCategoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GlpiPlantillaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            $query = GlpiParamPlantilla::with(['empresa:id,nombre', 'ans', 'categorias'])
                ->withCount('categorias');

            if ($request->filled('id_empresa')) {
                $query->where('id_empresa', (int) $request->id_empresa);
            }

            if ($request->has('estado') && $request->estado !== '') {
                $query->where('estado', filter_var($request->estado, FILTER_VALIDATE_BOOLEAN));
            }

            if ($request->filled('search')) {
                $search = trim((string) $request->search);
                $query->where(function ($q) use ($search): void {
                    $q->where('codigo', 'like', "%{$search}%")
                        ->orWhere('nombre', 'like', "%{$search}%")
                        ->orWhere('nombre_entidad', 'like', "%{$search}%");
                });
            }

            $plantillas = $query->orderBy('nombre')->get();

            return response()->json([
                'success' => true,
                'data' => $plantillas,
            ]);
        } catch (Throwable $e) {
            Log::error('Error al listar plantillas GLPI: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las plantillas.',
            ], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        $plantilla = GlpiParamPlantilla::with(['empresa:id,nombre', 'ans', 'categorias'])->find($id);

        if (! $plantilla) {
            return response()->json([
                'success' => false,
                'message' => 'Plantilla no encontrada.',
            ], 404);
        }

        $plantilla->setRelation(
            'ans',
            $this->ordenarAns($plantilla->ans)
        );

        return response()->json([
            'success' => true,
            'data' => $this->presentarPlantilla($plantilla),
        ]);
    }

    public function store(StoreGlpiPlantillaRequest $request): JsonResponse
    {
        try {
            $plantilla = DB::transaction(function () use ($request) {
                $data = $this->cabeceraDesdeRequest($request->validated());
                $data['created_by'] = auth()->id();
                $data['prefijo_regla'] = $data['prefijo_regla'] ?: 'TIC';

                $plantilla = GlpiParamPlantilla::create($data);
                $this->sincronizarAns($plantilla, $request->validated()['ans']);
                $this->sincronizarCategorias($plantilla, $request->input('categorias', []));

                return $plantilla->load(['empresa:id,nombre', 'ans', 'categorias']);
            });

            $plantilla->setRelation('ans', $this->ordenarAns($plantilla->ans));

            return response()->json([
                'success' => true,
                'message' => 'Plantilla creada correctamente.',
                'data' => $this->presentarPlantilla($plantilla),
            ], 201);
        } catch (Throwable $e) {
            Log::error('Error al crear plantilla GLPI: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la plantilla.',
            ], 500);
        }
    }

    public function update(UpdateGlpiPlantillaRequest $request, int $id): JsonResponse
    {
        $plantilla = GlpiParamPlantilla::find($id);

        if (! $plantilla) {
            return response()->json([
                'success' => false,
                'message' => 'Plantilla no encontrada.',
            ], 404);
        }

        try {
            $plantilla = DB::transaction(function () use ($request, $plantilla) {
                $data = $this->cabeceraDesdeRequest($request->validated());
                $data['prefijo_regla'] = $data['prefijo_regla'] ?: 'TIC';

                $plantilla->update($data);
                $this->sincronizarAns($plantilla, $request->validated()['ans']);
                $this->sincronizarCategorias($plantilla, $request->input('categorias', []));

                return $plantilla->fresh(['empresa:id,nombre', 'ans', 'categorias']);
            });

            $plantilla->setRelation('ans', $this->ordenarAns($plantilla->ans));

            return response()->json([
                'success' => true,
                'message' => 'Plantilla actualizada correctamente.',
                'data' => $this->presentarPlantilla($plantilla),
            ]);
        } catch (Throwable $e) {
            Log::error('Error al actualizar plantilla GLPI: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la plantilla.',
            ], 500);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        $plantilla = GlpiParamPlantilla::find($id);

        if (! $plantilla) {
            return response()->json([
                'success' => false,
                'message' => 'Plantilla no encontrada.',
            ], 404);
        }

        try {
            $plantilla->delete();

            return response()->json([
                'success' => true,
                'message' => 'Plantilla eliminada correctamente.',
            ]);
        } catch (Throwable $e) {
            Log::error('Error al eliminar plantilla GLPI: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la plantilla.',
            ], 500);
        }
    }

    public function toggleEstado(int $id): JsonResponse
    {
        $plantilla = GlpiParamPlantilla::find($id);

        if (! $plantilla) {
            return response()->json([
                'success' => false,
                'message' => 'Plantilla no encontrada.',
            ], 404);
        }

        $plantilla->estado = ! $plantilla->estado;
        $plantilla->save();

        return response()->json([
            'success' => true,
            'message' => $plantilla->estado ? 'Plantilla activada.' : 'Plantilla desactivada.',
            'data' => $plantilla->fresh(['empresa:id,nombre', 'ans', 'categorias']),
        ]);
    }

    private function cabeceraDesdeRequest(array $validated): array
    {
        return [
            'codigo' => strtoupper(trim($validated['codigo'])),
            'nombre' => trim($validated['nombre']),
            'descripcion' => $validated['descripcion'] ?? null,
            'id_empresa' => $validated['id_empresa'] ?? null,
            'nombre_entidad' => $validated['nombre_entidad'] ?? null,
            'grupo_tecnico' => $validated['grupo_tecnico'] ?? null,
            'sla_asignacion' => $validated['sla_asignacion'] ?? null,
            'prefijo_regla' => strtoupper(trim((string) ($validated['prefijo_regla'] ?? 'TIC'))),
            'estado' => array_key_exists('estado', $validated)
                ? (bool) $validated['estado']
                : true,
        ];
    }

    private function sincronizarAns(GlpiParamPlantilla $plantilla, array $ans): void
    {
        $prefijo = $plantilla->prefijo_regla ?: 'TIC';
        $plantilla->ans()->delete();

        foreach ($ans as $fila) {
            $prioridad = $fila['prioridad'];
            $nombreDefault = GlpiParamPlantilla::nombreRegla($prioridad, $prefijo);

            GlpiParamPlantillaAns::create([
                'plantilla_id' => $plantilla->id,
                'prioridad' => $prioridad,
                'tiempo_asignacion' => $fila['tiempo_asignacion'] ?? null,
                'unidad_asignacion' => $fila['unidad_asignacion'] ?? 'hora',
                'tiempo_solucion' => $fila['tiempo_solucion'] ?? null,
                'unidad_solucion' => $fila['unidad_solucion'] ?? 'hora',
                'nombre_sla_solucion' => trim((string) ($fila['nombre_sla_solucion'] ?? '')) ?: $nombreDefault,
                'nombre_regla' => trim((string) ($fila['nombre_regla'] ?? '')) ?: $nombreDefault,
            ]);
        }
    }

    private function sincronizarCategorias(GlpiParamPlantilla $plantilla, array $categorias): void
    {
        $plantilla->load('ans');
        GlpiParamPlantillaCategoria::where('plantilla_id', $plantilla->id)->update(['parent_id' => null]);
        $plantilla->categorias()->delete();

        foreach ($categorias as $nodo) {
            $this->guardarNodoCategoria($plantilla, $nodo, null, [], 1);
        }
    }

    private function guardarNodoCategoria(
        GlpiParamPlantilla $plantilla,
        array $nodo,
        ?int $parentId,
        array $rutaPadres,
        int $nivel
    ): void {
        if ($nivel > GlpiParamPlantillaCategoria::NIVEL_MAXIMO) {
            return;
        }

        $nombre = trim((string) ($nodo['nombre'] ?? $nodo['categoria'] ?? ''));
        if ($nombre === '') {
            return;
        }

        $ruta = array_values(array_filter([...$rutaPadres, $nombre]));
        $hijas = is_array($nodo['hijas'] ?? null) ? $nodo['hijas'] : [];
        $ansNombre = trim((string) ($nodo['ans_nombre'] ?? ''));
        $prioridad = (string) ($nodo['prioridad'] ?? 'baja');

        if ($ansNombre !== '') {
            $ansAsociado = $plantilla->ans->first(
                fn ($ans) => mb_strtoupper(trim((string) $ans->nombre_regla)) === mb_strtoupper($ansNombre)
            );
            if ($ansAsociado) {
                $prioridad = (string) $ansAsociado->prioridad;
            }
        }

        $registro = GlpiParamPlantillaCategoria::create([
            'plantilla_id' => $plantilla->id,
            'parent_id' => $parentId,
            'nombre' => $nombre,
            'nivel' => $nivel,
            'categoria' => $ruta[0],
            'subcategoria' => count($ruta) > 1 ? (string) end($ruta) : '',
            'prioridad' => $prioridad,
            'ans_nombre' => $ansNombre !== '' ? $ansNombre : null,
            'ruta_completa' => GlpiParamPlantillaCategoria::armarRuta($ruta),
            'glpi_itilcategories_id' => $nodo['glpi_itilcategories_id'] ?? null,
        ]);

        if ($nivel >= GlpiParamPlantillaCategoria::NIVEL_MAXIMO) {
            return;
        }

        foreach ($hijas as $hija) {
            if (is_array($hija)) {
                $this->guardarNodoCategoria($plantilla, $hija, (int) $registro->id, $ruta, $nivel + 1);
            }
        }
    }

    private function presentarPlantilla(GlpiParamPlantilla $plantilla): array
    {
        $data = $plantilla->toArray();
        $data['categorias'] = $this->construirArbol($plantilla->categorias ?? collect());

        return $data;
    }

    private function construirArbol($categorias): array
    {
        $filas = collect($categorias);
        if ($filas->isEmpty()) {
            return [];
        }

        $ids = $filas->map(fn ($item) => (int) ($item->id ?? 0));
        $idsUnicos = $ids->unique()->count() === $filas->count() && $ids->every(fn ($id) => $id > 0);

        if ($idsUnicos) {
            return $this->arbolDesdeParentId($filas);
        }

        return $this->arbolDesdeRutaCompleta($filas);
    }

    private function arbolDesdeParentId($filas): array
    {
        $porPadre = $filas->groupBy(fn ($item) => (string) ((int) ($item->parent_id ?: 0)));

        $armar = function (int $parentId, array $stack = []) use (&$armar, $porPadre): array {
            if (in_array($parentId, $stack, true)) {
                return [];
            }
            $stack[] = $parentId;
            $key = (string) $parentId;

            return ($porPadre[$key] ?? collect())->map(function ($item) use ($armar, $stack) {
                $id = (int) $item->id;

                return [
                    'id' => $id,
                    'nombre' => $item->nombre ?: $item->categoria,
                    'prioridad' => $item->prioridad,
                    'ans_nombre' => $item->ans_nombre,
                    'nivel' => (int) $item->nivel,
                    'ruta_completa' => $item->ruta_completa,
                    'hijas' => $id > 0 ? $armar($id, $stack) : [],
                ];
            })->values()->all();
        };

        return $armar(0);
    }

    private function arbolDesdeRutaCompleta($filas): array
    {
        $nodos = [];
        foreach ($filas as $fila) {
            $ruta = trim((string) ($fila->ruta_completa ?: $fila->nombre ?: $fila->categoria));
            if ($ruta === '') {
                continue;
            }
            $nodos[$ruta] = [
                'id' => (int) ($fila->id ?? 0),
                'nombre' => $fila->nombre ?: $fila->categoria,
                'prioridad' => $fila->prioridad,
                'ans_nombre' => $fila->ans_nombre,
                'nivel' => (int) ($fila->nivel ?: 1),
                'ruta_completa' => $ruta,
                'hijas' => [],
            ];
        }

        $raices = [];
        foreach ($nodos as $ruta => &$nodo) {
            $pos = mb_strrpos($ruta, ' > ');
            $rutaPadre = $pos === false ? '' : trim(mb_substr($ruta, 0, $pos));
            if ($rutaPadre !== '' && isset($nodos[$rutaPadre])) {
                $nodos[$rutaPadre]['hijas'][] = &$nodo;
            } else {
                $raices[] = &$nodo;
            }
        }
        unset($nodo);

        return json_decode(json_encode($raices), true) ?: [];
    }

    private function arbolDesdeCategoriaSubcategoria($filas): array
    {
        $arbol = [];
        $index = [];

        foreach ($filas as $fila) {
            $padre = trim((string) $fila->categoria);
            $hija = trim((string) $fila->subcategoria);

            if ($padre === '') {
                continue;
            }

            if (! isset($index[$padre])) {
                $index[$padre] = count($arbol);
                $arbol[] = [
                    'nombre' => $padre,
                    'prioridad' => $fila->prioridad,
                    'ans_nombre' => $fila->ans_nombre ?? null,
                    'nivel' => 1,
                    'hijas' => [],
                ];
            }

            if ($hija !== '') {
                $arbol[$index[$padre]]['hijas'][] = [
                    'nombre' => $hija,
                    'prioridad' => $fila->prioridad,
                    'ans_nombre' => $fila->ans_nombre ?? null,
                    'nivel' => 2,
                    'hijas' => [],
                ];
            }
        }

        return $arbol;
    }

    private function ordenarAns($ans)
    {
        $orden = array_flip(GlpiParamPlantilla::PRIORIDADES);

        return $ans
            ->sortBy([
                fn ($item) => $orden[$item->prioridad] ?? 99,
                fn ($item) => (int) $item->id,
            ])
            ->values();
    }
}
