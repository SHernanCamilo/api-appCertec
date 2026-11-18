<?php

declare(strict_types=1);

namespace App\Http\Controllers\Inventory\Ropa;

use App\Http\Controllers\Controller;
use App\Models\Config\ConfigUnidadFuncional;
use App\Models\Inventory\Ropa\RopaConstante;
use App\Models\Inventory\Ropa\RopaInventario;
use App\Models\Inventory\Ropa\RopaInventarioLinea;
use App\Models\Inventory\Ropa\RopaPrenda;
use App\Models\Inventory\Ropa\RopaUnidadCupo;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Toma del inventario de ropa.
 *
 * Abrir crea un borrador. Guardar copia la cabecera y las líneas vigentes
 * a un inventario cerrado, con otro consecutivo. El cerrado no se edita.
 */
class RopaInventarioController extends Controller
{
    public function reportePropio(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'id_sucursal' => 'required|integer',
            'id_sede' => 'nullable|integer',
            'id_unidad' => 'nullable|integer',
            'periodo' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ], [
            'id_sucursal.required' => 'Seleccione la sucursal.',
            'periodo.required' => 'Indique el periodo.',
            'periodo.regex' => 'El periodo debe ser año y mes, por ejemplo 2026-11.',
        ]);

        $user = $this->usuario($request);
        $general = $this->consultaGeneral($user);
        $inventarios = RopaInventario::query()
            ->with([
                'sucursal:id,nombre',
                'sede:id,nombre',
                'unidad:id,codigo,nombre',
                'unidad.cupoRopa',
                'usuario:id,name',
                'lineas' => fn ($q) => $q->where('estado', 1),
                'lineas.prenda:id,codigo,nombre,regla',
            ])
            ->where('estado', 1)
            ->when(!$general, fn ($q) => $q->where('id_usuario', $user->id))
            ->where('id_sucursal', (int) $datos['id_sucursal'])
            ->when($request->filled('id_sede'), fn ($q) => $q->where('id_sede', (int) $datos['id_sede']))
            ->where('periodo', $datos['periodo'])
            ->whereIn('id_unidad_funcional', $this->unidadesVisibles($user)->select('config_unidades_funcionales.id'))
            ->when(
                $request->filled('id_unidad'),
                fn ($q) => $q->where('id_unidad_funcional', (int) $datos['id_unidad'])
            )
            ->get();

        $grupos = [];
        foreach ($inventarios as $inventario) {
            $cupo = $inventario->unidad?->cupoRopa;
            foreach ($inventario->lineas as $linea) {
                $clave = $inventario->id_unidad_funcional . '-' . $linea->id_prenda;
                if (!isset($grupos[$clave])) {
                    $fijo = $this->fijo($linea, $cupo);
                    $grupos[$clave] = [
                        'periodo' => $inventario->periodo,
                        'sucursal' => $inventario->sucursal?->nombre,
                        'sede' => $inventario->sede?->nombre,
                        'unidad' => $inventario->unidad?->nombre,
                        'usuario' => $inventario->usuario?->name,
                        'prenda' => $linea->prenda?->nombre,
                        'camas' => (int) $linea->camas,
                        'camas_medico' => (int) ($cupo?->camas_medico ?? 0),
                        'personal' => (int) ($cupo?->personal ?? 0),
                        'constante' => $this->numero((float) $linea->constante),
                        'fijo' => $this->numero($fijo),
                        'fijo_valor' => $fijo,
                        'cantidad' => 0,
                    ];
                }
                $grupos[$clave]['cantidad'] += (int) $linea->cantidad;
            }
        }

        $filas = collect($grupos)
            ->map(function (array $fila) {
                $fila['faltante'] = $this->faltanteTexto($fila['fijo_valor'], $fila['cantidad']);
                unset($fila['fijo_valor']);

                return $fila;
            })
            ->sortBy([
                ['unidad', 'asc'],
                ['prenda', 'asc'],
            ])
            ->values();

        return response()->json([
            'success' => true,
            'consulta_general' => $general,
            'data' => $filas,
        ]);
    }

    private function consultaGeneral(User $user): bool
    {
        return $user->rolesCustom()
            ->where('seg_roles_custom.estado', 1)
            ->whereHas('perfiles', function ($perfil) {
                $perfil->where('seg_perfiles.estado', 1)
                    ->whereHas('permisos', function ($permiso) {
                        $permiso->where('seg_permisos.codigo', 'inv-ropa-consulta-sede')
                            ->where('seg_permisos.estado', 1);
                    });
            })
            ->exists();
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'periodo' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'id_unidad' => 'nullable|integer',
        ]);

        $user = $this->usuario($request);
        $general = $this->consultaGeneral($user);
        $query = RopaInventario::query()
            ->with([
                'sucursal:id,nombre',
                'sede:id,nombre',
                'unidad:id,codigo,nombre',
                'usuario:id,name',
                'borrador:id,consecutivo',
            ])
            ->withCount(['lineas as lineas_vigentes' => fn ($q) => $q->where('estado', 1)])
            ->where('estado', 1)
            ->when(!$general, fn ($q) => $q->where('id_usuario', $user->id))
            ->whereIn('id_unidad_funcional', $this->unidadesVisibles($user)->select('config_unidades_funcionales.id'))
            ->orderByDesc('fecha_corte')
            ->orderByDesc('id');

        if ($request->filled('periodo')) {
            $query->where('periodo', $request->string('periodo')->toString());
        }
        if ($request->filled('id_unidad')) {
            $query->where('id_unidad_funcional', (int) $request->input('id_unidad'));
        }

        $filas = $query->limit(300)->get()->map(fn (RopaInventario $inventario) => $this->resumenJson($inventario));

        return response()->json(['success' => true, 'data' => $filas]);
    }

    public function apoyo(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'id_unidad' => 'required|integer',
            'id_prenda' => 'nullable|integer',
            'periodo' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $unidad = $this->unidadVisible($this->usuario($request), (int) $datos['id_unidad']);
        if (!$unidad) {
            return $this->noEncontrado('La unidad no existe o no está en su alcance.');
        }

        $cupo = RopaUnidadCupo::query()->where('id_unidad_funcional', $unidad->id)->first();
        $constante = null;
        if ($request->filled('id_prenda')) {
            $constante = RopaConstante::query()
                ->where('id_prenda', (int) $datos['id_prenda'])
                ->where('id_unidad_funcional', $unidad->id)
                ->where('estado', 1)
                ->orderByDesc('id')
                ->value('constante');
        }

        $cerrado = false;
        if ($request->filled('periodo')) {
            $cerrado = RopaInventario::query()
                ->where('estado', 1)
                ->where('id_unidad_funcional', $unidad->id)
                ->where('periodo', $request->string('periodo')->toString())
                ->exists();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'camas' => $cupo?->camas,
                'tiene_cupo' => $cupo !== null,
                'constante' => $constante,
                'inventario_cerrado' => $cerrado,
            ],
        ]);
    }

    public function abrir(Request $request): JsonResponse
    {
        $inventario = DB::transaction(function () use ($request) {
            return RopaInventario::create([
                'consecutivo' => $this->siguienteConsecutivo(),
                'estado' => 2,
                'id_usuario' => $this->usuario($request)->id,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Toma abierta.',
            'data' => $this->documentoJson($inventario),
        ], 201);
    }

    public function ver(Request $request, int $id): JsonResponse
    {
        $inventario = $this->inventarioVisible($request, $id);
        if (!$inventario) {
            return $this->noEncontrado('El inventario no existe.');
        }

        return response()->json(['success' => true, 'data' => $this->documentoJson($inventario)]);
    }

    public function agregarLinea(Request $request, int $id): JsonResponse
    {
        $datos = $request->validate([
            'id_unidad' => 'required|integer',
            'id_prenda' => 'required|integer',
            'cama_extra' => 'present|integer|min:0|max:999',
            'cantidad' => 'present|integer|min:0|max:99999',
        ], [
            'cama_extra.present' => 'Indique la cama extra.',
            'cantidad.present' => 'Indique la cantidad encontrada.',
            'cantidad.min' => 'La cantidad no puede ser negativa.',
        ]);

        try {
            $inventario = DB::transaction(function () use ($request, $id, $datos) {
                $borrador = $this->bloquearBorrador($request, $id);
                $unidad = $this->unidadVisible($this->usuario($request), (int) $datos['id_unidad']);
                if (!$unidad || $unidad->id_sucursal === null) {
                    throw new InvalidArgumentException('La unidad no tiene sucursal o no está en su alcance.');
                }
                if ($borrador->id_unidad_funcional && (int) $borrador->id_unidad_funcional !== (int) $unidad->id) {
                    throw new InvalidArgumentException('Quite las prendas antes de cambiar la unidad.');
                }

                $prenda = RopaPrenda::query()->where('estado', 1)->find((int) $datos['id_prenda']);
                if (!$prenda) {
                    throw new InvalidArgumentException('La prenda no está vigente.');
                }

                $repetida = $borrador->lineas()
                    ->where('id_prenda', $prenda->id)
                    ->where('estado', 1)
                    ->lockForUpdate()
                    ->exists();
                if ($repetida) {
                    throw new InvalidArgumentException('Esa prenda ya está en el inventario.');
                }

                $cupo = RopaUnidadCupo::query()->where('id_unidad_funcional', $unidad->id)->first();
                if (!$cupo) {
                    throw new InvalidArgumentException('La unidad no tiene camas configuradas. Complételas en Parámetros.');
                }

                $constante = RopaConstante::query()
                    ->where('id_prenda', $prenda->id)
                    ->where('id_unidad_funcional', $unidad->id)
                    ->where('estado', 1)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();
                if (!$constante) {
                    throw new InvalidArgumentException('No hay constante vigente de esa prenda en la unidad.');
                }

                if (!$borrador->id_unidad_funcional) {
                    $borrador->update([
                        'id_unidad_funcional' => $unidad->id,
                        'id_sucursal' => $unidad->id_sucursal,
                        'id_sede' => $unidad->id_sede,
                    ]);
                }

                $borrador->lineas()->create([
                    'id_prenda' => $prenda->id,
                    'camas' => (int) $cupo->camas,
                    'cama_extra' => (int) $datos['cama_extra'],
                    'constante' => $constante->constante,
                    'cantidad' => (int) $datos['cantidad'],
                    'estado' => 1,
                ]);

                return $borrador;
            });
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Error agregando línea de ropa: ' . $e->getMessage());

            return response()->json(['success' => false, 'message' => 'No se pudo agregar la prenda.'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Prenda agregada.',
            'data' => $this->documentoJson($inventario->fresh()),
        ], 201);
    }

    public function quitarLinea(Request $request, int $id, int $linea): JsonResponse
    {
        try {
            $inventario = DB::transaction(function () use ($request, $id, $linea) {
                $borrador = $this->bloquearBorrador($request, $id);
                $fila = $borrador->lineas()->where('estado', 1)->lockForUpdate()->find($linea);
                if (!$fila) {
                    throw new InvalidArgumentException('La prenda no está en el inventario.');
                }
                $fila->update(['estado' => 0]);
                if (!$borrador->lineas()->where('estado', 1)->exists()) {
                    $borrador->update([
                        'id_unidad_funcional' => null,
                        'id_sucursal' => null,
                        'id_sede' => null,
                    ]);
                }

                return $borrador;
            });
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Prenda quitada.',
            'data' => $this->documentoJson($inventario->fresh()),
        ]);
    }

    public function vaciar(Request $request, int $id): JsonResponse
    {
        try {
            $inventario = DB::transaction(function () use ($request, $id) {
                $borrador = $this->bloquearBorrador($request, $id);
                $borrador->lineas()->where('estado', 1)->update(['estado' => 0]);
                $borrador->update([
                    'id_unidad_funcional' => null,
                    'id_sucursal' => null,
                    'id_sede' => null,
                ]);

                return $borrador;
            });
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->documentoJson($inventario->fresh()),
        ]);
    }

    public function cerrar(Request $request, int $id): JsonResponse
    {
        $this->normalizarComentario($request);
        $datos = $request->validate([
            'id_sucursal' => 'required|integer',
            'id_sede' => 'nullable|integer',
            'id_unidad' => 'required|integer',
            'periodo' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'comentario' => 'nullable|string|max:255',
        ], [
            'id_sucursal.required' => 'Seleccione la sucursal.',
            'id_unidad.required' => 'Seleccione la unidad funcional.',
            'periodo.required' => 'Indique el periodo.',
            'periodo.regex' => 'El periodo debe ser año y mes, por ejemplo 2026-11.',
            'comentario.max' => 'El comentario admite hasta 255 caracteres.',
        ]);

        try {
            $cerrado = DB::transaction(function () use ($request, $id, $datos) {
                $borrador = $this->bloquearBorrador($request, $id);
                $this->exigirCierreValido($request, $borrador, $datos);

                $lineas = $borrador->lineas()->where('estado', 1)->lockForUpdate()->get();
                if ($lineas->isEmpty()) {
                    throw new InvalidArgumentException('Agregue al menos una prenda.');
                }

                $registro = now();
                $idSede = isset($datos['id_sede']) ? (int) $datos['id_sede'] : null;
                $cerrado = RopaInventario::create([
                    'consecutivo' => $this->siguienteConsecutivo(),
                    'estado' => 1,
                    'id_usuario' => $borrador->id_usuario,
                    'id_sucursal' => (int) $datos['id_sucursal'],
                    'id_sede' => $idSede,
                    'id_unidad_funcional' => (int) $datos['id_unidad'],
                    'periodo' => $datos['periodo'],
                    'fecha_corte' => $registro->toDateString(),
                    'comentario' => $datos['comentario'] ?? null,
                    'fecha_registro' => $registro,
                    'id_borrador' => $borrador->id,
                ]);

                foreach ($lineas as $linea) {
                    $cerrado->lineas()->create([
                        'id_prenda' => $linea->id_prenda,
                        'camas' => $linea->camas,
                        'cama_extra' => $linea->cama_extra,
                        'constante' => $linea->constante,
                        'cantidad' => $linea->cantidad,
                        'estado' => 1,
                    ]);
                }

                $borrador->update([
                    'id_sucursal' => (int) $datos['id_sucursal'],
                    'id_sede' => $idSede,
                    'id_unidad_funcional' => (int) $datos['id_unidad'],
                    'periodo' => $datos['periodo'],
                    'fecha_corte' => $registro->toDateString(),
                    'comentario' => $datos['comentario'] ?? null,
                ]);

                return $cerrado;
            });
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Error cerrando inventario de ropa: ' . $e->getMessage());

            return response()->json(['success' => false, 'message' => 'No se pudo guardar el inventario.'], 500);
        }

        $cerrado->load('borrador:id,consecutivo');

        return response()->json([
            'success' => true,
            'message' => 'Inventario ' . $cerrado->borrador?->consecutivo . ' guardado.',
            'data' => $this->documentoJson($cerrado),
        ]);
    }

    private function exigirCierreValido(Request $request, RopaInventario $borrador, array $datos): void
    {
        if (RopaInventario::query()->where('id_borrador', $borrador->id)->lockForUpdate()->exists()) {
            throw new InvalidArgumentException('Este inventario ya fue guardado.');
        }

        $periodo = (string) $datos['periodo'];

        $unidad = $this->unidadVisible($this->usuario($request), (int) $datos['id_unidad']);
        if (!$unidad || (int) $unidad->id_sucursal !== (int) $datos['id_sucursal']) {
            throw new InvalidArgumentException('La unidad no pertenece a la sucursal.');
        }

        if ($unidad->id_sede !== null) {
            $sede = Sede::query()->find((int) ($datos['id_sede'] ?? 0));
            if (!$sede || (int) $sede->id_Sucursal !== (int) $datos['id_sucursal'] || (int) $unidad->id_sede !== (int) $sede->id) {
                throw new InvalidArgumentException('La unidad no pertenece a la sede.');
            }
        } elseif (!empty($datos['id_sede'])) {
            throw new InvalidArgumentException('La unidad no tiene sede.');
        }
        if ($borrador->id_unidad_funcional && (int) $borrador->id_unidad_funcional !== (int) $unidad->id) {
            throw new InvalidArgumentException('Las prendas corresponden a otra unidad.');
        }

        $duplicado = RopaInventario::query()
            ->where('estado', 1)
            ->where('id_unidad_funcional', $unidad->id)
            ->where('periodo', $periodo)
            ->lockForUpdate()
            ->exists();
        if ($duplicado) {
            throw new InvalidArgumentException('Esa unidad ya tiene un inventario cerrado en ese periodo.');
        }
    }

    private function bloquearBorrador(Request $request, int $id): RopaInventario
    {
        $borrador = RopaInventario::query()->lockForUpdate()->find($id);
        if (!$borrador || (int) $borrador->estado !== 2 || (int) $borrador->id_usuario !== (int) $this->usuario($request)->id) {
            throw new InvalidArgumentException('El inventario no está abierto para tomar.');
        }

        return $borrador;
    }

    private function inventarioVisible(Request $request, int $id): ?RopaInventario
    {
        $inventario = RopaInventario::query()->find($id);
        if (!$inventario) {
            return null;
        }

        $user = $this->usuario($request);
        if ((int) $inventario->estado === 2) {
            return (int) $inventario->id_usuario === (int) $user->id ? $inventario : null;
        }

        if ((int) $inventario->estado !== 1 || !$inventario->id_unidad_funcional) {
            return null;
        }

        if (!$this->unidadVisible($user, (int) $inventario->id_unidad_funcional)) {
            return null;
        }

        $propio = (int) $inventario->id_usuario === (int) $user->id;

        return $propio || $this->consultaGeneral($user) ? $inventario : null;
    }

    private function siguienteConsecutivo(): int
    {
        $ultimo = (int) RopaInventario::query()->lockForUpdate()->max('consecutivo');

        return $ultimo + 1;
    }

    private function unidadVisible(User $user, int $id): ?ConfigUnidadFuncional
    {
        return $this->unidadesVisibles($user)->where('config_unidades_funcionales.id', $id)->first();
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

    private function normalizarComentario(Request $request): void
    {
        if (!$request->exists('comentario') || !is_string($request->input('comentario'))) {
            return;
        }
        $texto = trim((string) preg_replace('/\s+/u', ' ', $request->input('comentario')));
        $request->merge(['comentario' => $texto === '' ? null : $texto]);
    }

    private function resumenJson(RopaInventario $inventario): array
    {
        return [
            'id' => $inventario->id,
            'consecutivo' => $inventario->consecutivo,
            'consecutivo_toma' => $inventario->borrador?->consecutivo,
            'periodo' => $inventario->periodo,
            'fecha_corte' => $inventario->fecha_corte?->toDateString(),
            'unidad_codigo' => $inventario->unidad?->codigo,
            'unidad_nombre' => $inventario->unidad?->nombre,
            'sede_nombre' => $inventario->sede?->nombre,
            'sucursal_nombre' => $inventario->sucursal?->nombre,
            'lineas' => (int) ($inventario->lineas_vigentes ?? 0),
            'usuario' => $inventario->usuario?->name,
        ];
    }

    private function documentoJson(RopaInventario $inventario): array
    {
        $inventario->loadMissing([
            'sucursal:id,nombre',
            'sede:id,nombre',
            'unidad:id,codigo,nombre',
            'unidad.cupoRopa',
            'usuario:id,name',
            'borrador:id,consecutivo',
            'lineas' => fn ($q) => $q->where('estado', 1)->orderBy('id'),
            'lineas.prenda:id,codigo,nombre,regla',
        ]);

        $cupo = $inventario->unidad?->cupoRopa;
        $calcular = (int) $inventario->estado === 1;

        return [
            'id' => $inventario->id,
            'consecutivo' => $inventario->consecutivo,
            'consecutivo_toma' => $inventario->borrador?->consecutivo,
            'estado' => (int) $inventario->estado,
            'periodo' => $inventario->periodo,
            'fecha_corte' => $inventario->fecha_corte?->toDateString(),
            'comentario' => $inventario->comentario,
            'fecha_registro' => $inventario->fecha_registro?->format('Y-m-d H:i'),
            'sucursal_id' => $inventario->id_sucursal,
            'sucursal_nombre' => $inventario->sucursal?->nombre,
            'sede_id' => $inventario->id_sede,
            'sede_nombre' => $inventario->sede?->nombre,
            'unidad_id' => $inventario->id_unidad_funcional,
            'unidad_codigo' => $inventario->unidad?->codigo,
            'unidad_nombre' => $inventario->unidad?->nombre,
            'usuario' => $inventario->usuario?->name,
            'lineas' => $inventario->lineas->map(function (RopaInventarioLinea $linea) use ($cupo, $calcular) {
                $fila = [
                    'id' => $linea->id,
                    'id_prenda' => $linea->id_prenda,
                    'prenda_codigo' => $linea->prenda?->codigo,
                    'prenda_nombre' => $linea->prenda?->nombre,
                    'constante' => $linea->constante,
                    'camas' => $linea->camas,
                    'cama_extra' => $linea->cama_extra,
                    'camas_vista' => $linea->camas + $linea->cama_extra,
                    'cantidad' => $linea->cantidad,
                ];
                if ($calcular) {
                    $fijo = $this->fijo($linea, $cupo);
                    $fila['fijo'] = $this->numero($fijo);
                    $fila['faltante'] = $this->faltanteTexto($fijo, $linea->cantidad);
                }

                return $fila;
            })->values(),
        ];
    }

    private function fijo(RopaInventarioLinea $linea, ?RopaUnidadCupo $cupo): float
    {
        $constante = (float) $linea->constante;
        $regla = $linea->prenda?->regla;
        if ($regla === 'estar_medico' && $cupo?->aplica_estar_medico) {
            return round(((int) $cupo->camas_medico) * $constante, 2);
        }
        if ($regla === 'personal' && $cupo?->aplica_personal) {
            return round(((int) $cupo->personal) * $constante, 2);
        }

        return round(((int) $linea->camas) * $constante, 2);
    }

    private function faltanteTexto(float $fijo, int $cantidad): string
    {
        $faltante = round($fijo - $cantidad, 2);
        if ($faltante < 0) {
            return '+' . $this->numero(abs($faltante));
        }

        return $this->numero($faltante);
    }

    private function numero(float $valor): string
    {
        $redondo = round($valor, 2);
        if (abs($redondo - round($redondo)) < 0.001) {
            return (string) (int) round($redondo);
        }

        return number_format($redondo, 2, '.', '');
    }

    private function noEncontrado(string $mensaje): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $mensaje], 404);
    }
}
