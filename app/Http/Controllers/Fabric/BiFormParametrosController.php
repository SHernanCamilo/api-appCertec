<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\BiFormParametro;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BiFormParametrosController extends Controller
{
    public function show(string $formulario): JsonResponse
    {
        try {
            $row = BiFormParametro::query()
                ->where('formulario_codigo', $formulario)
                ->first();

            return response()->json([
                'success' => true,
                'data' => $this->toItem($formulario, $row),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => true,
                'data' => $this->toItem($formulario, null),
                'message' => config('app.debug') ? $e->getMessage() : null,
            ]);
        }
    }

    public function upsert(Request $request, string $formulario): JsonResponse
    {
        $payload = $request->validate([
            'campos' => ['required', 'array'],
            'campos.*.key' => ['required', 'string', 'max:120'],
            'campos.*.visible' => ['required', 'boolean'],
            'campos.*.requerido' => ['required', 'boolean'],
            'campos.*.label' => ['required', 'string', 'max:255'],
        ]);

        $row = BiFormParametro::updateOrCreate(
            ['formulario_codigo' => $formulario],
            [
                'campos' => $payload['campos'],
                'usuario_actualiza_id' => (int) $request->user()->id,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Parámetros guardados correctamente',
            'data' => $this->toItem($formulario, $row),
        ]);
    }

    private function toItem(string $formulario, ?BiFormParametro $row): array
    {
        return [
            'formulario' => $formulario,
            'campos' => $row?->campos ?? [],
            'updatedAt' => optional($row?->updated_at)?->toIso8601String(),
        ];
    }
}
