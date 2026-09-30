<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Models\BiFromTrasAsistencial;
use App\Services\Fabric\ODataParquetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BiTrasladoAsistencialController extends Controller
{
    public function __construct(
        private readonly ODataParquetService $parquet
    ) {}

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
                    'created_at',
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

    /**
     * GET /fabric/traslado-asistencial/paciente?documento=
     * Busca un paciente en el parquet de dc.VW_AD_Paciente (DuckDB), sin COUNT ni SQL en vivo.
     */
    public function buscarPaciente(Request $request): JsonResponse
    {
        $documento = trim((string) $request->query('documento', ''));
        if ($documento === '') {
            return response()->json([
                'success' => false,
                'message' => 'Indique el número de documento.',
                'data' => null,
            ], 422);
        }

        return $this->buscarPersonaParquet(
            $documento,
            'VW_AD_Paciente',
            'No se encontró el paciente.',
            'El parquet de pacientes aún no está listo. Puede diligenciar los datos.'
        );
    }

    /**
     * GET /fabric/traslado-asistencial/profesional?documento=
     * Busca un profesional en el parquet de dc.VW_AD_Profesionales.
     */
    public function buscarProfesional(Request $request): JsonResponse
    {
        $documento = trim((string) $request->query('documento', ''));
        if ($documento === '') {
            return response()->json([
                'success' => false,
                'message' => 'Indique el número de documento.',
                'data' => null,
            ], 422);
        }

        return $this->buscarPersonaParquet(
            $documento,
            'VW_AD_Profesionales',
            'No se encontró el profesional.',
            'El parquet de profesionales aún no está listo. Puede diligenciar los datos.'
        );
    }

    private function buscarPersonaParquet(
        string $documento,
        string $vista,
        string $mensajeNoEncontrado,
        string $mensajeParquetFaltante
    ): JsonResponse {
        // DuckDB ignora columnas inexistentes. Sin WHERE, LIMIT 1 devuelve
        // siempre la primera fila del parquet (la misma persona).
        $columnasDoc = [
            'Identificacion',
            'NumeroIdentificacion',
            'NroIdentificacion',
            'Documento',
            'NumeroDocumento',
        ];

        $ultimoError = null;
        foreach ($columnasDoc as $col) {
            $hallado = $this->buscarFilaPorColumna($vista, $col, $documento, $ultimoError);
            if ($hallado === 'parquet_missing') {
                return response()->json([
                    'success' => false,
                    'code' => 'parquet_missing',
                    'message' => $mensajeParquetFaltante,
                    'data' => null,
                ], 409);
            }
            if (is_array($hallado)) {
                return response()->json([
                    'success' => true,
                    'source' => $hallado['source'] ?? 'parquet-local',
                    'data' => $hallado['row'],
                ]);
            }
            if ($hallado === 'miss') {
                return response()->json([
                    'success' => true,
                    'data' => null,
                    'message' => $mensajeNoEncontrado,
                ]);
            }
        }

        if (is_string($ultimoError) && $ultimoError !== '') {
            return response()->json([
                'success' => false,
                'message' => $ultimoError,
                'data' => null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => null,
            'message' => $mensajeNoEncontrado,
        ]);
    }

    /**
     * @return array{row: array<string, mixed>, source?: string}|'miss'|'ignored'|'error'|'parquet_missing'
     */
    private function buscarFilaPorColumna(string $vista, string $col, string $documento, ?string &$ultimoError)
    {
        $exacto = $this->consultarParquetVista($vista, [$col => $documento]);
        if (($exacto['status'] ?? 0) === 409) {
            return 'parquet_missing';
        }
        if (!($exacto['success'] ?? false)) {
            $ultimoError = $exacto['message'] ?? 'No se pudo consultar el parquet.';
            return 'error';
        }

        $row = $exacto['value'][0] ?? null;
        if (is_array($row) && $this->filaTieneDocumento($row, $documento)) {
            return ['row' => $row, 'source' => $exacto['source'] ?? 'parquet-local'];
        }

        if (!is_array($row) || $row === []) {
            $parcial = $this->consultarParquetVista($vista, [$col => "%{$documento}%"]);
            $rowParcial = ($parcial['success'] ?? false) ? ($parcial['value'][0] ?? null) : null;
            if (is_array($rowParcial) && $this->filaTieneDocumento($rowParcial, $documento)) {
                return ['row' => $rowParcial, 'source' => $parcial['source'] ?? 'parquet-local'];
            }

            return 'miss';
        }

        // Filtro ignorado (columna no existe): descubrir el nombre real y reintentar una vez.
        $colReal = $this->columnaDocumentoEnFila($row);
        if ($colReal !== null && strcasecmp($colReal, $col) !== 0) {
            return $this->buscarFilaPorColumna($vista, $colReal, $documento, $ultimoError);
        }

        return 'ignored';
    }

    /**
     * @param  array<string, string>  $filtro
     * @return array<string, mixed>
     */
    private function consultarParquetVista(string $vista, array $filtro): array
    {
        return $this->parquet->filter('dc', $vista, $filtro, 1, 0, [
            'count' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function columnaDocumentoEnFila(array $row): ?string
    {
        $aliases = [
            'identificacion',
            'numeroidentificacion',
            'nroidentificacion',
            'documento',
            'numerodocumento',
            'nrodocumento',
            'cedula',
            'idpaciente',
        ];

        foreach (array_keys($row) as $key) {
            $norm = strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $key));
            if (in_array($norm, $aliases, true)) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function filaTieneDocumento(array $row, string $documento): bool
    {
        $needle = $this->normalizarDocumento($documento);
        if ($needle === '') {
            return false;
        }

        $col = $this->columnaDocumentoEnFila($row);
        $candidatos = $col !== null
            ? [$row[$col] ?? '']
            : array_values($row);

        foreach ($candidatos as $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $hay = $this->normalizarDocumento((string) $value);
            if ($hay === $needle || ($hay !== '' && str_contains($hay, $needle))) {
                return true;
            }
        }

        return false;
    }

    private function normalizarDocumento(string $valor): string
    {
        $soloDigitos = preg_replace('/\D+/', '', $valor) ?? '';

        return $soloDigitos !== '' ? $soloDigitos : mb_strtolower(trim($valor));
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
            'fechaAtencion' => optional($row->created_at ?? $row->fecha_guarda)?->format('Y-m-d'),
            'fechaCreacion' => optional($row->created_at ?? $row->fecha_guarda)?->format('Y-m-d'),
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
