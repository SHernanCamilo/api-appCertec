<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\BiFromTrasAsistencial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BiTrasladoAsistencialController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'tipo' => ['nullable', Rule::in(['primario', 'secundario'])],
            'estado' => ['nullable', Rule::in(['guardado', 'confirmado'])],
        ]);

        try {
            $query = BiFromTrasAsistencial::query()
                ->select([
                    'id',
                    'tipo',
                    'formato',
                    'estado',
                    'fecha_guarda',
                    'usuario_guarda_id',
                    'fecha_confirma',
                    'usuario_confirma_id',
                    'fecha_atencion',
                    'nombres_apellidos',
                    'tipo_identificacion',
                    'numero_identificacion',
                    'estado_paciente',
                ])
                ->with(['usuarioGuarda:id,name', 'usuarioConfirma:id,name'])
                ->orderByDesc('fecha_guarda')
                ->limit(200);

            if ($request->filled('tipo')) {
                $query->where('tipo', $request->tipo);
            }
            if ($request->filled('estado')) {
                $query->where('estado', $request->estado);
            }

            $rows = $query->get()->map(fn (BiFromTrasAsistencial $row) => $this->toListItem($row));

            return response()->json([
                'success' => true,
                'data' => $rows,
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al listar traslados asistenciales', $e);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $row = BiFromTrasAsistencial::query()
                ->with(['usuarioGuarda:id,name', 'usuarioConfirma:id,name'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $this->toDetail($row),
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al consultar el traslado', $e, 404);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatedPayload($request);

        try {
            $userId = (int) $request->user()->id;
            $now = now();

            $row = BiFromTrasAsistencial::create([
                ...$this->mapColumns($payload),
                'estado' => BiFromTrasAsistencial::ESTADO_GUARDADO,
                'fecha_guarda' => $now,
                'usuario_guarda_id' => $userId,
                'fecha_confirma' => null,
                'usuario_confirma_id' => null,
            ]);

            $row->load(['usuarioGuarda:id,name', 'usuarioConfirma:id,name']);

            return response()->json([
                'success' => true,
                'message' => 'Traslado guardado correctamente',
                'data' => $this->toDetail($row),
            ], 201);
        } catch (\Exception $e) {
            return $this->error('Error al guardar el traslado', $e);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $payload = $this->validatedPayload($request);

        try {
            $row = BiFromTrasAsistencial::findOrFail($id);

            if ($row->estaConfirmado()) {
                return response()->json([
                    'success' => false,
                    'message' => 'El registro ya está confirmado y no se puede modificar.',
                ], 409);
            }

            $row->update([
                ...$this->mapColumns($payload),
                'fecha_guarda' => now(),
                'usuario_guarda_id' => (int) $request->user()->id,
            ]);

            $row->load(['usuarioGuarda:id,name', 'usuarioConfirma:id,name']);

            return response()->json([
                'success' => true,
                'message' => 'Traslado actualizado correctamente',
                'data' => $this->toDetail($row),
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al actualizar el traslado', $e);
        }
    }

    public function confirmar(Request $request, int $id): JsonResponse
    {
        try {
            $row = BiFromTrasAsistencial::findOrFail($id);

            if ($row->estaConfirmado()) {
                return response()->json([
                    'success' => false,
                    'message' => 'El registro ya está confirmado.',
                ], 409);
            }

            if ($request->has('datos') || $request->has('formato')) {
                $payload = $this->validatedPayload($request);
                $row->fill($this->mapColumns($payload));
            }

            $row->estado = BiFromTrasAsistencial::ESTADO_CONFIRMADO;
            $row->fecha_confirma = now();
            $row->usuario_confirma_id = (int) $request->user()->id;
            $row->save();

            $row->load(['usuarioGuarda:id,name', 'usuarioConfirma:id,name']);

            return response()->json([
                'success' => true,
                'message' => 'Traslado confirmado correctamente',
                'data' => $this->toDetail($row),
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al confirmar el traslado', $e);
        }
    }

    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'formato' => ['required', 'string', Rule::in([
                'primario',
                'primarioCompleto',
                'secundario',
                'secundarioCompleto',
            ])],
            'datos' => ['required', 'array'],
            'fecha_atencion' => ['nullable', 'date'],
            'nombres_apellidos' => ['nullable', 'string', 'max:255'],
            'tipo_identificacion' => ['nullable', 'string', 'max:20'],
            'numero_identificacion' => ['nullable', 'string', 'max:30'],
            'estado_paciente' => ['nullable', 'string', 'max:10'],
        ]);
    }

    private function mapColumns(array $payload): array
    {
        $formato = (string) $payload['formato'];
        $datos = $payload['datos'];
        $datos['_formato'] = $formato;

        return [
            'tipo' => str_starts_with($formato, 'secundario')
                ? BiFromTrasAsistencial::TIPO_SECUNDARIO
                : BiFromTrasAsistencial::TIPO_PRIMARIO,
            'formato' => $formato,
            'fecha_atencion' => $payload['fecha_atencion']
                ?? ($datos['fechaAtencion'] ?: null)
                ?: null,
            'nombres_apellidos' => $payload['nombres_apellidos']
                ?? ($datos['nombresApellidos'] ?? null),
            'tipo_identificacion' => $payload['tipo_identificacion']
                ?? ($datos['tipoIdentificacion'] ?? null),
            'numero_identificacion' => $payload['numero_identificacion']
                ?? ($datos['numeroIdentificacion'] ?? null),
            'estado_paciente' => $payload['estado_paciente']
                ?? ($datos['estadoFinal'] ?? null),
            'datos' => $datos,
        ];
    }

    private function toListItem(BiFromTrasAsistencial $row): array
    {
        return [
            'id' => $row->id,
            'tipo' => $row->tipo,
            'formato' => $row->formato,
            'estado' => $row->estado,
            'fechaAtencion' => optional($row->fecha_atencion)?->format('Y-m-d'),
            'paciente' => $row->nombres_apellidos,
            'identificacion' => $row->numero_identificacion,
            'estadoFinal' => $row->estado_paciente,
            'fechaGuarda' => optional($row->fecha_guarda)?->format('Y-m-d H:i:s'),
            'usuarioGuarda' => $row->usuarioGuarda?->name,
            'fechaConfirma' => optional($row->fecha_confirma)?->format('Y-m-d H:i:s'),
            'usuarioConfirma' => $row->usuarioConfirma?->name,
        ];
    }

    private function toDetail(BiFromTrasAsistencial $row): array
    {
        return [
            ...$this->toListItem($row),
            'datos' => $row->datos,
        ];
    }

    private function error(string $message, \Exception $e, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error' => config('app.debug') ? $e->getMessage() : null,
        ], $status);
    }
}
