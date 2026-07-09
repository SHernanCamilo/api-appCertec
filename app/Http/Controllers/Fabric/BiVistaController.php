<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\BiVista;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BiVistaController extends Controller
{
    /** Códigos de sede alineados con Graph-Fabric DEPT_TO_SUFFIX */
    public const DEPARTAMENTOS_CATALOGO = [
        ['codigo' => 'MA',  'nombre' => 'Materno (MA)'],
        ['codigo' => 'EAL', 'nombre' => 'El Abner (EAL)'],
        ['codigo' => 'FLA', 'nombre' => 'Florencia (FLA)'],
        ['codigo' => 'KTA', 'nombre' => 'Facatativá (KTA)'],
        ['codigo' => 'TJA', 'nombre' => 'Tunja (TJA)'],
        ['codigo' => 'NVA', 'nombre' => 'Neiva (NVA)'],
        ['codigo' => 'DTA', 'nombre' => 'Duitama (DTA)'],
        ['codigo' => 'NAL', 'nombre' => 'Nacional (NAL)'],
    ];

    public function catalogoDepartamentos(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => self::DEPARTAMENTOS_CATALOGO,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'id_bi_grupos' => 'required|exists:bi_grupos,id',
        ]);

        try {
            $vistas = BiVista::query()
                ->where('id_bi_grupos', (int) $request->id_bi_grupos)
                ->orderBy('nombre')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => $vistas,
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al listar vistas', $e);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'id_bi_grupos' => 'required|exists:bi_grupos,id',
            'nombre'       => [
                'required',
                'string',
                'max:150',
                Rule::unique('bi_vistas', 'nombre')->where(
                    fn ($q) => $q->where('id_bi_grupos', $request->input('id_bi_grupos'))
                ),
            ],
            'descripcion'   => 'nullable|string|max:255',
            'departamentos' => 'nullable|array',
            'departamentos.*' => 'string|max:10',
            'estado'        => ['nullable', Rule::in([
                BiVista::ESTADO_ACTIVO,
                BiVista::ESTADO_INACTIVO,
                BiVista::ESTADO_MANTENIMIENTO,
            ])],
        ]);

        try {
            $vista = BiVista::create([
                'id_bi_grupos'  => (int) $request->id_bi_grupos,
                'nombre'        => trim($request->nombre),
                'descripcion'   => $request->descripcion,
                'departamentos' => $this->normalizarDepartamentos($request->departamentos),
                'estado'        => $request->input('estado', BiVista::ESTADO_ACTIVO),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Vista registrada correctamente',
                'data'    => $vista,
            ], 201);
        } catch (\Exception $e) {
            return $this->error('Error al registrar la vista', $e);
        }
    }

    public function storeBulk(Request $request): JsonResponse
    {
        $request->validate([
            'id_bi_grupos'       => 'required|exists:bi_grupos,id',
            'vistas'             => 'required|array|min:1',
            'vistas.*.nombre'      => 'required|string|max:150',
            'vistas.*.descripcion' => 'nullable|string|max:255',
        ]);

        try {
            $grupoId = (int) $request->id_bi_grupos;
            $creadas = [];

            foreach ($request->vistas as $item) {
                $nombre = trim($item['nombre']);
                if ($nombre === '') {
                    continue;
                }

                $vista = BiVista::firstOrCreate(
                    [
                        'id_bi_grupos' => $grupoId,
                        'nombre'       => $nombre,
                    ],
                    [
                        'descripcion' => $item['descripcion'] ?? null,
                    ]
                );

                $creadas[] = $vista;
            }

            return response()->json([
                'success' => true,
                'message' => count($creadas) . ' vista(s) registrada(s)',
                'data'    => $creadas,
            ], 201);
        } catch (\Exception $e) {
            return $this->error('Error al registrar las vistas', $e);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $vista = BiVista::findOrFail($id);

        $request->validate([
            'nombre' => [
                'sometimes',
                'required',
                'string',
                'max:150',
                Rule::unique('bi_vistas', 'nombre')
                    ->ignore($id)
                    ->where(fn ($q) => $q->where('id_bi_grupos', $vista->id_bi_grupos)),
            ],
            'descripcion'   => 'nullable|string|max:255',
            'departamentos' => 'nullable|array',
            'departamentos.*' => 'string|max:10',
            'estado'        => ['sometimes', Rule::in([
                BiVista::ESTADO_ACTIVO,
                BiVista::ESTADO_INACTIVO,
                BiVista::ESTADO_MANTENIMIENTO,
            ])],
        ]);

        try {
            $vista->fill([
                'nombre'        => trim($request->input('nombre', $vista->nombre)),
                'descripcion'   => $request->input('descripcion', $vista->descripcion),
                'departamentos' => $request->has('departamentos')
                    ? $this->normalizarDepartamentos($request->departamentos)
                    : $vista->departamentos,
                'estado'        => $request->input('estado', $vista->estado),
            ]);
            $vista->save();

            return response()->json([
                'success' => true,
                'message' => 'Vista actualizada correctamente',
                'data'    => $vista,
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al actualizar la vista', $e);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            BiVista::findOrFail($id)->delete();

            return response()->json([
                'success' => true,
                'message' => 'Vista eliminada correctamente',
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al eliminar la vista', $e);
        }
    }

    private function normalizarDepartamentos(?array $departamentos): ?array
    {
        if ($departamentos === null) {
            return null;
        }

        $normalizados = array_values(array_unique(array_filter(array_map(
            fn ($d) => strtoupper(trim((string) $d)),
            $departamentos
        ))));

        return $normalizados === [] ? null : $normalizados;
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
