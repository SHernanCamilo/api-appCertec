<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\BiGrupo;
use App\Models\BiVista;
use App\Services\Fabric\GraphFabricGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BiGrupoController extends Controller
{
    public function __construct(
        private readonly GraphFabricGatewayService $gateway
    ) {}
    public function buscar(Request $request): JsonResponse
    {
        $request->validate([
            'empresa_id' => 'required|exists:ent_empresas,id',
            'codigo'     => 'required|string|max:20',
        ]);

        try {
            $grupo = BiGrupo::query()
                ->with(['empresa:id,nombre,prefijo', 'vistas'])
                ->where('empresa_id', (int) $request->empresa_id)
                ->where('codigo', strtoupper(trim($request->codigo)))
                ->first();

            return response()->json([
                'success' => true,
                'data'    => $grupo,
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al buscar esquema', $e);
        }
    }

    public function catalogoFabric(Request $request): JsonResponse
    {
        $request->validate([
            'schema'  => 'required|string|max:20|alpha_dash',
            'refresh' => 'nullable|boolean',
        ]);

        try {
            $result = $this->gateway->getCatalogViewsForSchema(
                $request->input('schema'),
                $request->boolean('refresh')
            );

            return response()->json($result, ($result['success'] ?? false) ? 200 : 502);
        } catch (\Exception $e) {
            return $this->error('Error al consultar catálogo Fabric', $e);
        }
    }

    /**
     * Consulta Fabric y persiste todas las vistas del esquema en bi_vistas.
     *
     * POST /api/fabric/bi-grupos/{id}/sincronizar-vistas
     */
    public function sincronizarVistasFabric(int $id): JsonResponse
    {
        try {
            $grupo = BiGrupo::findOrFail($id);
            $schema = strtolower(trim($grupo->codigo));

            $catalog = $this->gateway->getCatalogViewsForSchema($schema, true);

            if (!($catalog['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => $catalog['message'] ?? 'Error al consultar catálogo Fabric',
                    'data'    => [],
                ], 502);
            }

            $nuevas = 0;
            $actualizadas = 0;

            foreach ($catalog['data'] ?? [] as $view) {
                $nombre = trim($view['view_name'] ?? '');
                if ($nombre === '') {
                    continue;
                }

                $descripcion = $view['qualified_name'] ?? null;

                $vista = BiVista::firstOrCreate(
                    [
                        'id_bi_grupos' => $grupo->id,
                        'nombre'       => $nombre,
                    ],
                    [
                        'descripcion' => $descripcion,
                    ]
                );

                if ($vista->wasRecentlyCreated) {
                    $nuevas++;
                    continue;
                }

                if ($descripcion !== null && $vista->descripcion !== $descripcion) {
                    $vista->update(['descripcion' => $descripcion]);
                    $actualizadas++;
                }
            }

            $vistas = BiVista::query()
                ->where('id_bi_grupos', $grupo->id)
                ->orderBy('nombre')
                ->get();

            return response()->json([
                'success' => true,
                'message' => sprintf(
                    'Sincronización completada: %d vista(s) desde Fabric, %d nueva(s), %d actualizada(s)',
                    count($catalog['data'] ?? []),
                    $nuevas,
                    $actualizadas
                ),
                'data'    => [
                    'vistas'        => $vistas,
                    'total_fabric'  => count($catalog['data'] ?? []),
                    'nuevas'        => $nuevas,
                    'actualizadas'  => $actualizadas,
                    'schema'        => $schema,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al sincronizar vistas desde Fabric', $e);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $query = BiGrupo::query()
                ->with(['empresa:id,nombre,prefijo', 'vistas', 'usuarioCrea:id,name', 'usuarioModifica:id,name'])
                ->orderBy('codigo');

            if ($request->filled('empresa_id')) {
                $query->where('empresa_id', (int) $request->empresa_id);
            }

            if ($request->filled('tipo')) {
                $query->where('tipo', (int) $request->tipo);
            }

            return response()->json([
                'success' => true,
                'data'    => $query->get(),
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al listar esquemas', $e);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $grupo = BiGrupo::query()
                ->with(['empresa:id,nombre,prefijo', 'vistas', 'usuarioCrea:id,name', 'usuarioModifica:id,name'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => $grupo,
            ]);
        } catch (\Exception $e) {
            return $this->error('Esquema no encontrado', $e, 404);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'codigo'      => [
                'required',
                'string',
                'max:20',
                Rule::unique('bi_grupos', 'codigo')->where(
                    fn ($q) => $q->where('empresa_id', $request->input('empresa_id'))
                ),
            ],
            'tipo'        => 'required|integer|in:1,2,3',
            'descripcion' => 'nullable|string|max:255',
            'empresa_id'  => 'required|exists:ent_empresas,id',
        ]);

        try {
            $grupo = BiGrupo::create([
                'codigo'               => strtoupper(trim($request->codigo)),
                'tipo'                 => (int) $request->tipo,
                'descripcion'          => $request->descripcion,
                'empresa_id'           => (int) $request->empresa_id,
                'usuario_crea_id'      => auth()->id(),
                'usuario_modifica_id'  => auth()->id(),
            ]);

            $grupo->load(['empresa:id,nombre,prefijo']);

            return response()->json([
                'success' => true,
                'message' => 'Esquema creado correctamente',
                'data'    => $grupo,
            ], 201);
        } catch (\Exception $e) {
            return $this->error('Error al crear el esquema', $e);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $grupo = BiGrupo::findOrFail($id);

        $request->validate([
            'codigo'      => [
                'sometimes',
                'required',
                'string',
                'max:20',
                Rule::unique('bi_grupos', 'codigo')
                    ->ignore($id)
                    ->where(fn ($q) => $q->where(
                        'empresa_id',
                        $request->input('empresa_id', $grupo->empresa_id)
                    )),
            ],
            'tipo'        => 'sometimes|required|integer|in:1,2,3',
            'descripcion' => 'nullable|string|max:255',
            'empresa_id'  => 'sometimes|required|exists:ent_empresas,id',
        ]);

        try {
            $grupo->fill([
                'codigo'              => strtoupper(trim($request->input('codigo', $grupo->codigo))),
                'tipo'                => (int) $request->input('tipo', $grupo->tipo),
                'descripcion'         => $request->input('descripcion', $grupo->descripcion),
                'empresa_id'          => (int) $request->input('empresa_id', $grupo->empresa_id),
                'usuario_modifica_id' => auth()->id(),
            ]);
            $grupo->save();

            $grupo->load(['empresa:id,nombre,prefijo']);

            return response()->json([
                'success' => true,
                'message' => 'Esquema actualizado correctamente',
                'data'    => $grupo,
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al actualizar el esquema', $e);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            BiGrupo::findOrFail($id)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Esquema eliminado correctamente',
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al eliminar el esquema', $e);
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
}
