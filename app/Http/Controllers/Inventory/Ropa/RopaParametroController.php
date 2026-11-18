<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory\Ropa;

use App\Http\Controllers\Controller;
use App\Models\Config\ConfigUnidadFuncional;
use App\Models\Empresa;
use App\Models\Inventory\Ropa\RopaConstante;
use App\Models\Inventory\Ropa\RopaPrenda;
use App\Models\Inventory\Ropa\RopaUnidadCupo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Parámetros del inventario de ropa hospitalaria.
 *
 * Prendas, cupos por unidad (camas, camas de médico y personal) y la
 * constante vigente de cada prenda en cada unidad. Una sola constante
 * vigente por pareja. La baja es lógica.
 */
class RopaParametroController extends Controller
{
    private const REGLAS = ['cama', 'estar_medico', 'personal'];

    public function prendas(Request $request): JsonResponse
    {
        $request->validate([
            'estado' => 'nullable|in:0,1',
            'regla' => 'nullable|in:cama,estar_medico,personal',
            'q' => 'nullable|string|max:100',
        ]);

        $query = RopaPrenda::query()
            ->withCount(['constantes as constantes_vigentes' => fn ($q) => $q->where('estado', 1)])
            ->orderBy('nombre');

        if ($request->filled('estado')) {
            $query->where('estado', (int) $request->input('estado'));
        }
        if ($request->filled('regla')) {
            $query->where('regla', $request->string('regla')->toString());
        }
        if ($busqueda = $this->texto($request->input('q'))) {
            $query->where(function (Builder $q) use ($busqueda) {
                $q->where('nombre', 'like', "%{$busqueda}%")
                    ->orWhere('codigo', 'like', "%{$busqueda}%");
            });
        }

        $prendas = $query->limit(500)->get()->map(fn (RopaPrenda $prenda) => $this->prendaJson($prenda));

        return response()->json(['success' => true, 'data' => $prendas]);
    }

    public function crearPrenda(Request $request): JsonResponse
    {
        $datos = $this->validarPrenda($request);

        $prenda = RopaPrenda::create($datos);

        return response()->json([
            'success' => true,
            'message' => 'Prenda creada.',
            'data' => $this->prendaJson($prenda),
        ], 201);
    }

    public function actualizarPrenda(Request $request, int $id): JsonResponse
    {
        $prenda = RopaPrenda::query()->find($id);
        if (!$prenda) {
            return $this->noEncontrado('La prenda no existe.');
        }

        $datos = $this->validarPrenda($request, $id);

        if ((int) $datos['estado'] === 0 && (int) $prenda->estado === 1) {
            $vigentes = $prenda->constantes()->where('estado', 1)->count();
            if ($vigentes > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta prenda tiene constantes vigentes. Délas de baja antes de inactivar la prenda.',
                ], 422);
            }
        }

        $prenda->update($datos);
        $prenda->loadCount(['constantes as constantes_vigentes' => fn ($q) => $q->where('estado', 1)]);

        return response()->json([
            'success' => true,
            'message' => 'Prenda actualizada.',
            'data' => $this->prendaJson($prenda),
        ]);
    }

    public function eliminarPrenda(int $id): JsonResponse
    {
        $prenda = RopaPrenda::query()->find($id);
        if (!$prenda) {
            return $this->noEncontrado('La prenda no existe.');
        }

        if ($prenda->constantes()->where('estado', 1)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Esta prenda tiene constantes vigentes. Délas de baja antes de inactivar la prenda.',
            ], 422);
        }

        $prenda->update(['estado' => 0]);

        return response()->json([
            'success' => true,
            'message' => 'Prenda dada de baja.',
            'data' => $this->prendaJson($prenda),
        ]);
    }

    public function empresas(Request $request): JsonResponse
    {
        $user = $this->usuario($request);
        $user->loadMissing('empresas');
        $super = $user->veTodasLasEmpresas();

        $asignadas = $user->empresas
            ->unique('id')
            ->map(fn (Empresa $empresa) => ['id' => $empresa->id, 'nombre' => $empresa->nombre])
            ->sortBy('nombre')
            ->values();

        $lista = $super
            ? Empresa::query()->where('estado', 1)->orderBy('nombre')->get(['id', 'nombre'])
            : $asignadas;

        return response()->json([
            'success' => true,
            'debe_elegir' => $super || $lista->count() !== 1,
            'data' => $lista->values(),
        ]);
    }

    public function unidades(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'nullable|string|max:100',
            'id_sucursal' => 'nullable|integer',
            'id_sede' => 'nullable|integer',
            'con_cupo' => 'nullable|boolean',
        ]);

        $user = $this->usuario($request);
        $query = $this->unidadesVisibles($user)
            ->with(['sucursal:id,nombre', 'sede:id,nombre', 'cupoRopa'])
            ->orderBy('nombre');

        if ($request->filled('id_sucursal')) {
            $query->where('id_sucursal', (int) $request->input('id_sucursal'));
        }
        if ($request->filled('id_sede')) {
            $query->where('id_sede', (int) $request->input('id_sede'));
        }
        if ($request->boolean('con_cupo')) {
            $query->whereHas('cupoRopa');
        }
        if ($busqueda = $this->texto($request->input('q'))) {
            $query->where(function (Builder $q) use ($busqueda) {
                $q->where('codigo', 'like', "%{$busqueda}%")
                    ->orWhere('nombre', 'like', "%{$busqueda}%");
            });
        }

        $filas = $query->limit(2000)->get()->map(fn (ConfigUnidadFuncional $unidad) => $this->unidadJson($unidad));

        return response()->json(['success' => true, 'data' => $filas]);
    }

    public function guardarCupo(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate([
            'camas' => 'present|integer|min:0|max:9999',
            'camas_medico' => 'present|integer|min:0|max:9999',
            'personal' => 'present|integer|min:0|max:9999',
            'aplica_estar_medico' => 'boolean',
            'aplica_personal' => 'boolean',
        ], [
            'camas.present' => 'Indique las camas.',
            'camas_medico.present' => 'Indique las camas de médico.',
            'personal.present' => 'Indique el personal.',
        ]);

        $unidad = $this->unidadesVisibles($this->usuario($request))->with(['sucursal:id,nombre', 'sede:id,nombre'])->find($id);
        if (!$unidad) {
            return $this->noEncontrado('La unidad no existe o no está en su alcance.');
        }

        $cupo = RopaUnidadCupo::query()->updateOrCreate(
            ['id_unidad_funcional' => $unidad->id],
            [
                'camas' => (int) $datos['camas'],
                'camas_medico' => (int) $datos['camas_medico'],
                'personal' => (int) $datos['personal'],
                'aplica_estar_medico' => $request->boolean('aplica_estar_medico'),
                'aplica_personal' => $request->boolean('aplica_personal'),
            ]
        );

        $unidad->setRelation('cupoRopa', $cupo);

        return response()->json([
            'success' => true,
            'message' => 'Cupo de la unidad guardado.',
            'data' => $this->unidadJson($unidad),
        ]);
    }

    public function constantes(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'nullable|string|max:100',
            'id_prenda' => 'nullable|integer',
            'id_unidad' => 'nullable|integer',
            'estado' => 'nullable|in:0,1',
        ]);

        $user = $this->usuario($request);
        $query = RopaConstante::query()
            ->with([
                'prenda:id,codigo,nombre,regla,estado',
                'unidad:id,codigo,nombre,id_sucursal,id_sede',
                'unidad.sucursal:id,nombre',
                'unidad.sede:id,nombre',
            ])
            ->whereIn('id_unidad_funcional', $this->unidadesVisibles($user)->select('id'));

        if ($request->filled('estado')) {
            $query->where('estado', (int) $request->input('estado'));
        } else {
            $query->where('estado', 1);
        }
        if ($request->filled('id_prenda')) {
            $query->where('id_prenda', (int) $request->input('id_prenda'));
        }
        if ($request->filled('id_unidad')) {
            $query->where('id_unidad_funcional', (int) $request->input('id_unidad'));
        }
        if ($busqueda = $this->texto($request->input('q'))) {
            $query->where(function (Builder $q) use ($busqueda) {
                $q->whereHas('prenda', function (Builder $prenda) use ($busqueda) {
                    $prenda->where('nombre', 'like', "%{$busqueda}%")
                        ->orWhere('codigo', 'like', "%{$busqueda}%");
                })->orWhereHas('unidad', function (Builder $unidad) use ($busqueda) {
                    $unidad->where('nombre', 'like', "%{$busqueda}%")
                        ->orWhere('codigo', 'like', "%{$busqueda}%");
                });
            });
        }

        $filas = $query->orderBy('id_prenda')->orderBy('id_unidad_funcional')->limit(5000)->get()
            ->map(fn (RopaConstante $constante) => $this->constanteJson($constante));

        return response()->json(['success' => true, 'data' => $filas]);
    }

    public function crearConstante(Request $request): JsonResponse
    {
        $datos = $this->validarConstante($request);

        try {
            $constante = DB::transaction(function () use ($request, $datos) {
                $this->exigirParValido($request, (int) $datos['id_prenda'], (int) $datos['id_unidad']);
                $this->exigirConstanteLibre((int) $datos['id_prenda'], (int) $datos['id_unidad']);

                return RopaConstante::create([
                    'id_prenda' => (int) $datos['id_prenda'],
                    'id_unidad_funcional' => (int) $datos['id_unidad'],
                    'constante' => round((float) $datos['constante'], 2),
                    'estado' => 1,
                    'id_usuario' => $request->user()?->id,
                ]);
            });
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Error creando constante de ropa: ' . $e->getMessage());

            return response()->json(['success' => false, 'message' => 'No se pudo crear la constante.'], 500);
        }

        $constante->load([
            'prenda:id,codigo,nombre,regla,estado',
            'unidad:id,codigo,nombre,id_sucursal,id_sede',
            'unidad.sucursal:id,nombre',
            'unidad.sede:id,nombre',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Constante creada.',
            'data' => $this->constanteJson($constante),
        ], 201);
    }

    public function actualizarConstante(Request $request, int $id): JsonResponse
    {
        $constante = RopaConstante::query()
            ->where('estado', 1)
            ->whereIn('id_unidad_funcional', $this->unidadesVisibles($this->usuario($request))->select('id'))
            ->find($id);
        if (!$constante) {
            return $this->noEncontrado('La constante vigente no existe.');
        }

        $datos = $this->validarConstante($request);

        try {
            DB::transaction(function () use ($request, $constante, $datos) {
                $this->exigirParValido($request, (int) $datos['id_prenda'], (int) $datos['id_unidad']);
                $this->exigirConstanteLibre((int) $datos['id_prenda'], (int) $datos['id_unidad'], $constante->id);
                $constante->update([
                    'id_prenda' => (int) $datos['id_prenda'],
                    'id_unidad_funcional' => (int) $datos['id_unidad'],
                    'constante' => round((float) $datos['constante'], 2),
                    'id_usuario' => $request->user()?->id,
                ]);
            });
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Error actualizando constante de ropa: ' . $e->getMessage());

            return response()->json(['success' => false, 'message' => 'No se pudo actualizar la constante.'], 500);
        }

        $constante->refresh()->load([
            'prenda:id,codigo,nombre,regla,estado',
            'unidad:id,codigo,nombre,id_sucursal,id_sede',
            'unidad.sucursal:id,nombre',
            'unidad.sede:id,nombre',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Constante actualizada.',
            'data' => $this->constanteJson($constante),
        ]);
    }

    public function eliminarConstante(Request $request, int $id): JsonResponse
    {
        $constante = RopaConstante::query()
            ->whereIn('id_unidad_funcional', $this->unidadesVisibles($this->usuario($request))->select('id'))
            ->find($id);

        if (!$constante || (int) $constante->estado !== 1) {
            return $this->noEncontrado('La constante vigente no existe.');
        }

        $constante->update(['estado' => 0, 'id_usuario' => $request->user()?->id]);

        return response()->json([
            'success' => true,
            'message' => 'Constante dada de baja. Los conteos ya cerrados no cambian.',
        ]);
    }

    private function validarPrenda(Request $request, ?int $ignorarId = null): array
    {
        $this->normalizarTexto($request, ['codigo', 'nombre']);

        $datos = $request->validate([
            'codigo' => [
                'nullable',
                'string',
                'max:30',
                Rule::unique('inv_ropa_prendas', 'codigo')->ignore($ignorarId),
            ],
            'nombre' => 'required|string|min:2|max:150',
            'regla' => 'required|in:' . implode(',', self::REGLAS),
            'estado' => 'nullable|in:0,1',
        ], [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.min' => 'El nombre es demasiado corto.',
            'codigo.unique' => 'Ya existe una prenda con ese código.',
            'regla.required' => 'Seleccione la regla del fijo.',
            'regla.in' => 'La regla debe ser cama, estar médico o personal.',
        ]);

        return [
            'codigo' => $datos['codigo'] ?? null,
            'nombre' => $datos['nombre'],
            'regla' => $datos['regla'],
            'estado' => array_key_exists('estado', $datos) ? (int) $datos['estado'] : 1,
        ];
    }

    private function validarConstante(Request $request): array
    {
        return $request->validate([
            'id_prenda' => 'required|integer',
            'id_unidad' => 'required|integer',
            'constante' => 'present|numeric|min:0|max:9999.99',
        ], [
            'id_prenda.required' => 'Seleccione la prenda.',
            'id_unidad.required' => 'Seleccione la unidad.',
            'constante.present' => 'Indique la constante.',
            'constante.min' => 'La constante no puede ser negativa.',
        ]);
    }

    private function exigirParValido(Request $request, int $prendaId, int $unidadId): void
    {
        $prenda = RopaPrenda::query()->where('estado', 1)->find($prendaId);
        if (!$prenda) {
            throw new InvalidArgumentException('La prenda no está vigente.');
        }

        $unidad = $this->unidadesVisibles($this->usuario($request))->find($unidadId);
        if (!$unidad) {
            throw new InvalidArgumentException('La unidad no está vigente o no está en su alcance.');
        }
    }

    private function exigirConstanteLibre(int $prendaId, int $unidadId, ?int $ignorarId = null): void
    {
        $query = RopaConstante::query()
            ->where('id_prenda', $prendaId)
            ->where('id_unidad_funcional', $unidadId)
            ->where('estado', 1)
            ->lockForUpdate();

        if ($ignorarId !== null) {
            $query->where('id', '!=', $ignorarId);
        }

        if ($query->exists()) {
            throw new InvalidArgumentException('Ya hay una constante vigente para esa prenda en esa unidad.');
        }
    }

    private function unidadesVisibles(User $user): Builder
    {
        return ConfigUnidadFuncional::query()->activas()->accessibleByUser($user);
    }

    private function usuario(Request $request): User
    {
        $user = $request->user();
        if (!$user instanceof User) {
            abort(401, 'Sesión no válida.');
        }

        return $user;
    }

    private function normalizarTexto(Request $request, array $campos): void
    {
        $merge = [];
        foreach ($campos as $campo) {
            if (!$request->exists($campo) || !is_string($request->input($campo))) {
                continue;
            }
            $valor = trim((string) preg_replace('/\s+/u', ' ', $request->input($campo)));
            $merge[$campo] = $valor === '' ? null : $valor;
        }
        if ($merge !== []) {
            $request->merge($merge);
        }
    }

    private function texto(mixed $valor): ?string
    {
        if (!is_string($valor)) {
            return null;
        }
        $texto = trim(str_replace(['%', '_'], ['\\%', '\\_'], $valor));

        return $texto === '' ? null : $texto;
    }

    private function prendaJson(RopaPrenda $prenda): array
    {
        return [
            'id' => $prenda->id,
            'codigo' => $prenda->codigo,
            'nombre' => $prenda->nombre,
            'regla' => $prenda->regla,
            'regla_nombre' => $prenda->reglaNombre(),
            'estado' => (int) $prenda->estado,
            'constantes_vigentes' => (int) ($prenda->constantes_vigentes ?? 0),
        ];
    }

    private function unidadJson(ConfigUnidadFuncional $unidad): array
    {
        $cupo = $unidad->cupoRopa;

        return [
            'id' => $unidad->id,
            'codigo' => $unidad->codigo,
            'nombre' => $unidad->nombre,
            'empresa_id' => $unidad->id_empresa,
            'sucursal_id' => $unidad->sucursal?->id,
            'sucursal_nombre' => $unidad->sucursal?->nombre,
            'sede_id' => $unidad->sede?->id,
            'sede_nombre' => $unidad->sede?->nombre,
            'tiene_cupo' => $cupo !== null,
            'camas' => $cupo?->camas,
            'camas_medico' => $cupo?->camas_medico,
            'personal' => $cupo?->personal,
            'aplica_estar_medico' => (bool) ($cupo?->aplica_estar_medico ?? false),
            'aplica_personal' => (bool) ($cupo?->aplica_personal ?? false),
        ];
    }

    private function constanteJson(RopaConstante $constante): array
    {
        return [
            'id' => $constante->id,
            'id_prenda' => $constante->id_prenda,
            'id_unidad' => $constante->id_unidad_funcional,
            'constante' => $constante->constante,
            'estado' => (int) $constante->estado,
            'prenda_codigo' => $constante->prenda?->codigo,
            'prenda_nombre' => $constante->prenda?->nombre,
            'regla' => $constante->prenda?->regla,
            'regla_nombre' => $constante->prenda?->reglaNombre() ?? 'Cama',
            'unidad_codigo' => $constante->unidad?->codigo,
            'unidad_nombre' => $constante->unidad?->nombre,
            'sucursal_nombre' => $constante->unidad?->sucursal?->nombre,
            'sede_nombre' => $constante->unidad?->sede?->nombre,
        ];
    }

    private function noEncontrado(string $mensaje): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $mensaje], 404);
    }
}
