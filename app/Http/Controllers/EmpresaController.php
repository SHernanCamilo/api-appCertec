<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class EmpresaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        try {
            $empresas = Empresa::orderBy('created_at', 'desc')->get();
            
            return response()->json($empresas, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al obtener las empresas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:50',
            'prefijo' => 'required|string|max:5',
            'rep_legal' => 'required|string|max:50',
            'cc_rep_legal' => 'required|integer',
            'direccion' => 'required|string|max:50',
            'telefono' => 'required|integer',
            'nit' => 'required|integer|unique:ent_empresas,nit',
            'logo' => 'nullable|string|max:255',
            'estado' => 'nullable|integer|in:0,1'
        ], [
            'nombre.required' => 'El nombre de la empresa es obligatorio',
            'prefijo.required' => 'El prefijo es obligatorio',
            'rep_legal.required' => 'El representante legal es obligatorio',
            'cc_rep_legal.required' => 'La cédula del representante legal es obligatoria',
            'direccion.required' => 'La dirección es obligatoria',
            'telefono.required' => 'El teléfono es obligatorio',
            'nit.required' => 'El NIT es obligatorio',
            'nit.unique' => 'El NIT ya está registrado'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $empresa = Empresa::create($request->all());
            
            return response()->json([
                'message' => 'Empresa creada exitosamente',
                'data' => $empresa
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al crear la empresa',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $empresa = Empresa::findOrFail($id);
            
            return response()->json($empresa, 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Empresa no encontrada',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'sometimes|required|string|max:50',
            'prefijo' => 'sometimes|required|string|max:5',
            'rep_legal' => 'sometimes|required|string|max:50',
            'cc_rep_legal' => 'sometimes|required|integer',
            'direccion' => 'sometimes|required|string|max:50',
            'telefono' => 'sometimes|required|integer',
            'nit' => 'sometimes|required|integer|unique:ent_empresas,nit,' . $id,
            'logo' => 'nullable|string|max:255',
            'estado' => 'nullable|integer|in:0,1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $empresa = Empresa::findOrFail($id);
            $empresa->update($request->all());
            
            return response()->json([
                'message' => 'Empresa actualizada exitosamente',
                'data' => $empresa
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al actualizar la empresa',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $empresa = Empresa::findOrFail($id);
            $empresa->delete();
            
            return response()->json([
                'message' => 'Empresa eliminada exitosamente'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al eliminar la empresa',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle estado de la empresa (activar/desactivar)
     */
    public function toggleEstado(string $id): JsonResponse
    {
        try {
            $empresa = Empresa::findOrFail($id);
            $empresa->estado = $empresa->estado === 1 ? 0 : 1;
            $empresa->save();
            
            return response()->json([
                'message' => 'Estado actualizado exitosamente',
                'data' => $empresa
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al cambiar el estado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener empresas activas
     */
    public function activas(): JsonResponse
    {
        try {
            $empresas = Empresa::activas()->orderBy('nombre')->get();
            
            return response()->json([
                'success' => true,
                'data' => $empresas
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las empresas activas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Devuelve el logo de la empresa en base64 (evita CORS en el frontend/PDF).
     */
    public function logoBase64(string $id): JsonResponse
    {
        try {
            $empresa = Empresa::findOrFail($id);
            $logo = trim((string) ($empresa->logo ?? ''));

            if ($logo === '') {
                return response()->json([
                    'success' => true,
                    'nombre' => $empresa->nombre,
                    'logo_url' => null,
                    'logo_base64' => null,
                ]);
            }

            if (str_starts_with($logo, 'data:image')) {
                return response()->json([
                    'success' => true,
                    'nombre' => $empresa->nombre,
                    'logo_url' => null,
                    'logo_base64' => $logo,
                ]);
            }

            $contents = null;
            $mime = null;

            if (preg_match('#^https?://#i', $logo)) {
                try {
                    $response = Http::timeout(8)
                        ->withHeaders(['User-Agent' => 'MONICA-LogoFetcher/1.0'])
                        ->withOptions(['verify' => false])
                        ->get($logo);

                    if ($response->successful()) {
                        $contents = $response->body();
                        $mime = $response->header('Content-Type') ?: null;
                    }
                } catch (\Throwable $e) {
                    $contents = null;
                }
            } else {
                $relative = ltrim($logo, '/');
                $candidates = [
                    public_path($relative),
                    storage_path('app/public/' . $relative),
                    storage_path('app/' . $relative),
                    base_path($relative),
                ];

                foreach ($candidates as $path) {
                    if (is_file($path)) {
                        $contents = file_get_contents($path);
                        $mime = mime_content_type($path) ?: null;
                        break;
                    }
                }
            }

            if ($contents === false || $contents === null || $contents === '') {
                return response()->json([
                    'success' => true,
                    'nombre' => $empresa->nombre,
                    'logo_url' => $logo,
                    'logo_base64' => null,
                    'message' => 'No se pudo leer el logo de la empresa',
                ]);
            }

            if (!$mime) {
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->buffer($contents) ?: 'image/png';
            }

            if (!str_starts_with((string) $mime, 'image/')) {
                return response()->json([
                    'success' => true,
                    'nombre' => $empresa->nombre,
                    'logo_url' => $logo,
                    'logo_base64' => null,
                    'message' => 'El recurso del logo no es una imagen válida',
                ]);
            }

            return response()->json([
                'success' => true,
                'nombre' => $empresa->nombre,
                'logo_url' => $logo,
                'logo_base64' => 'data:' . $mime . ';base64,' . base64_encode($contents),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el logo de la empresa',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
