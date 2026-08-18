<?php

declare(strict_types=1);

namespace App\Http\Controllers\MesaServicio;

use App\Http\Controllers\Controller;
use App\Services\MesaServicio\GlpiValidadorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GlpiValidadorController extends Controller
{
    public function __construct(private GlpiValidadorService $validador)
    {
    }

    public function entidades(): JsonResponse
    {
        try {
            $arbol = $this->validador->arbolEntidades();

            return response()->json([
                'success' => true,
                'data' => $arbol,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Error al leer entidades GLPI: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'No se pudieron obtener las entidades de GLPI.',
            ], 502);
        }
    }

    public function comparar(Request $request): JsonResponse
    {
        $request->validate([
            'plantilla_id' => ['required', 'integer', 'min:1'],
            'entidad_id' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $resultado = $this->validador->comparar(
                (int) $request->input('plantilla_id'),
                (int) $request->input('entidad_id')
            );

            return response()->json([
                'success' => true,
                'data' => $resultado,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Error al comparar plantilla GLPI: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'No se pudo comparar la plantilla con GLPI.',
            ], 502);
        }
    }

    public function compararRegla(Request $request): JsonResponse
    {
        $request->validate([
            'plantilla_id' => ['required', 'integer', 'min:1'],
            'entidad_id' => ['required', 'integer', 'min:0'],
            'regla_glpi_id' => ['required', 'integer', 'min:1'],
            'ans_key' => ['nullable', 'string'],
        ]);

        try {
            $resultado = $this->validador->compararRegla(
                (int) $request->input('plantilla_id'),
                (int) $request->input('entidad_id'),
                (int) $request->input('regla_glpi_id'),
                $request->filled('ans_key') ? (string) $request->input('ans_key') : null
            );

            return response()->json([
                'success' => true,
                'data' => $resultado,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Error al comparar regla GLPI: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'No se pudo comparar la regla con el ANS de la plantilla.',
            ], 502);
        }
    }
}
