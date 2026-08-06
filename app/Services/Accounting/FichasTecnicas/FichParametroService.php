<?php

declare(strict_types=1);

namespace App\Services\Accounting\FichasTecnicas;

use App\Models\Accounting\FichasTecnicas\FichAgremiacion;
use App\Models\Accounting\FichasTecnicas\FichEspecialidad;
use App\Models\Accounting\FichasTecnicas\FichHomologo;
use App\Models\Accounting\FichasTecnicas\FichObjetoContrato;
use App\Models\Accounting\FichasTecnicas\FichObsItem;
use App\Models\Accounting\FichasTecnicas\FichProfesional;
use App\Models\Accounting\FichasTecnicas\FichTipoServicio;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * CRUD de los catálogos maestros del módulo (rol Parametrizador del legacy).
 *
 * Reemplaza `parametrizador/agremiaciones.php`, `profesionales.php`,
 * `especialidades.php`, `servicios.php`, `asig_esp.php`, `new_*.php` y
 * `edit_*.php`, donde cada archivo repetía su propia validación de duplicados
 * con `mysqli_num_rows` y armaba el SQL por concatenación.
 */
final class FichParametroService
{
    /** Catálogos administrables y su modelo asociado. */
    private const CATALOGOS = [
        'agremiaciones'    => FichAgremiacion::class,
        'profesionales'    => FichProfesional::class,
        'especialidades'   => FichEspecialidad::class,
        'tipos-servicio'   => FichTipoServicio::class,
        'objetos-contrato' => FichObjetoContrato::class,
        'obs-items'        => FichObsItem::class,
        'homologos'        => FichHomologo::class,
    ];

    /**
     * @param  array<string, mixed>  $filtros
     */
    public function listar(string $catalogo, array $filtros = []): Paginator
    {
        $modelo = $this->modelo($catalogo);
        $query  = $modelo::query();

        if (array_key_exists('estado', $filtros) && $filtros['estado'] !== '' && $filtros['estado'] !== null) {
            $query->where('estado', filter_var($filtros['estado'], FILTER_VALIDATE_BOOLEAN));
        }

        $buscar = trim((string) ($filtros['buscar'] ?? ''));
        if ($buscar !== '' && method_exists($modelo, 'scopeBuscar')) {
            $query->buscar($buscar);
        } elseif ($buscar !== '') {
            $query->where('descripcion', 'like', "%{$buscar}%");
        }

        match ($catalogo) {
            'profesionales'  => $query->with('especialidades:id,descripcion,perfil'),
            'homologos'      => $query->with('tipoServicio:id,descripcion'),
            'obs-items'      => $query->with('tiposServicio:id,descripcion'),
            'tipos-servicio' => $query->withCount('detalles'),
            default          => null,
        };

        $orden   = $this->columnaOrden($catalogo);
        $perPage = min(max((int) ($filtros['per_page'] ?? 25), 5), 200);

        return $query->orderBy($orden)->paginate($perPage);
    }

    /**
     * Opciones ligeras para los selects del formulario del generador.
     *
     * El legacy hacía `SELECT *` de cada catálogo dentro del propio HTML.
     *
     * @return array<string, Collection<int, object>>
     */
    public function opcionesFormulario(): array
    {
        return [
            'agremiaciones'    => FichAgremiacion::query()->activas()->orderBy('nombre')->get(['id', 'nombre', 'nit']),
            'especialidades'   => FichEspecialidad::query()->activas()->orderBy('descripcion')->get(['id', 'descripcion', 'perfil']),
            'objetos_contrato' => FichObjetoContrato::query()->activos()->orderBy('descripcion')->get(['id', 'descripcion']),
            'tipos_servicio'   => FichTipoServicio::query()->activos()->orderBy('descripcion')->get(['id', 'descripcion']),
            'formas_pago'      => collect(\App\Models\Accounting\FichasTecnicas\FichDetalle::FORMAS_PAGO)
                ->map(static fn (string $v): array => ['value' => $v, 'label' => $v]),
            'perfiles'         => collect(FichEspecialidad::PERFILES)
                ->map(static fn (string $v): array => ['value' => $v, 'label' => $v]),
        ];
    }

    /**
     * Profesionales que atienden una especialidad.
     *
     * Reemplaza `generador/select_especialidad.php`, que devolvía HTML.
     *
     * @return Collection<int, object>
     */
    public function profesionalesPorEspecialidad(int $idEspecialidad): Collection
    {
        return DB::table('v_fich_profesionales_especialidad')
            ->where('id_especialidad', $idEspecialidad)
            ->where('profesional_estado', true)
            ->select(['id_profesional', 'documento', 'profesional_nombre', 'tarjeta_profesional', 'especialidad_perfil'])
            ->orderBy('profesional_nombre')
            ->get();
    }

    /**
     * Observaciones aplicables a un tipo de servicio.
     *
     * Reemplaza `generador/ajax/get_observaciones.php`.
     *
     * @return Collection<int, FichObsItem>
     */
    public function observacionesPorTipoServicio(int $idTipoServicio): Collection
    {
        return FichObsItem::query()
            ->activos()
            ->paraTipoServicio($idTipoServicio)
            ->orderBy('descripcion')
            ->get(['id', 'descripcion']);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Escritura
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     */
    public function crear(string $catalogo, array $data): Model
    {
        $modelo = $this->modelo($catalogo);
        $this->validar($catalogo, $data);

        return DB::transaction(function () use ($modelo, $catalogo, $data): Model {
            /** @var Model $registro */
            $registro = $modelo::query()->create($this->normalizar($catalogo, $data));

            $this->sincronizarRelaciones($catalogo, $registro, $data);

            return $registro->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function actualizar(string $catalogo, int $id, array $data): Model
    {
        $modelo = $this->modelo($catalogo);
        $this->validar($catalogo, $data, $id);

        return DB::transaction(function () use ($modelo, $catalogo, $id, $data): Model {
            /** @var Model $registro */
            $registro = $modelo::query()->findOrFail($id);
            $registro->update($this->normalizar($catalogo, $data));

            $this->sincronizarRelaciones($catalogo, $registro, $data);

            return $registro->refresh();
        });
    }

    /**
     * Desactivación lógica (regla R17 del legacy: nunca se borra físicamente).
     */
    public function cambiarEstado(string $catalogo, int $id, bool $estado): Model
    {
        $modelo = $this->modelo($catalogo);

        /** @var Model $registro */
        $registro = $modelo::query()->findOrFail($id);
        $registro->update(['estado' => $estado]);

        return $registro->refresh();
    }

    /**
     * Asigna especialidades a un profesional (legacy `asig_esp.php`).
     *
     * @param  list<int>  $idsEspecialidad
     */
    public function asignarEspecialidades(int $idProfesional, array $idsEspecialidad): FichProfesional
    {
        $profesional = FichProfesional::query()->findOrFail($idProfesional);

        $ids = array_values(array_unique(array_map('intval', $idsEspecialidad)));
        $profesional->especialidades()->sync($ids);

        return $profesional->load('especialidades:id,descripcion,perfil');
    }

    /**
     * Asigna tipos de servicio a una observación predefinida.
     *
     * @param  list<int>  $idsTipoServicio
     */
    public function asignarTiposServicioAObservacion(int $idObsItem, array $idsTipoServicio): FichObsItem
    {
        $obsItem = FichObsItem::query()->findOrFail($idObsItem);

        $ids = array_values(array_unique(array_map('intval', $idsTipoServicio)));
        $obsItem->tiposServicio()->sync($ids);

        return $obsItem->load('tiposServicio:id,descripcion');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return class-string<Model>
     */
    private function modelo(string $catalogo): string
    {
        if (! isset(self::CATALOGOS[$catalogo])) {
            throw new InvalidArgumentException(
                "Catálogo desconocido \"{$catalogo}\". Válidos: ".implode(', ', array_keys(self::CATALOGOS))
            );
        }

        return self::CATALOGOS[$catalogo];
    }

    private function columnaOrden(string $catalogo): string
    {
        return match ($catalogo) {
            'agremiaciones', 'profesionales' => 'nombre',
            'homologos'                      => 'code_cups',
            default                          => 'descripcion',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function validar(string $catalogo, array $data, ?int $id = null): void
    {
        $sufijo = $id !== null ? ",{$id}" : '';
        $modo   = $id !== null ? 'sometimes|required' : 'required';

        $reglas = match ($catalogo) {
            'agremiaciones' => [
                'nombre'       => "{$modo}|string|max:255",
                'nit'          => "nullable|string|max:20|unique:fich_agremiaciones,nit{$sufijo}",
                'rep_legal'    => 'nullable|string|max:255',
                'cc_rep_legal' => 'nullable|string|max:30',
                'direccion'    => 'nullable|string|max:255',
                'telefono'     => 'nullable|string|max:50',
                'correo'       => 'nullable|email|max:150',
                'estado'       => 'nullable|boolean',
            ],
            'profesionales' => [
                'documento'           => "{$modo}|string|max:20|unique:fich_profesionales,documento{$sufijo}",
                'nombre'              => "{$modo}|string|max:255",
                'tarjeta_profesional' => 'nullable|string|max:60',
                'correo'              => 'nullable|email|max:150',
                'telefono'            => 'nullable|string|max:50',
                'estado'              => 'nullable|boolean',
                'especialidades'      => 'nullable|array',
                'especialidades.*'    => 'integer|exists:fich_especialidades,id',
            ],
            'especialidades' => [
                'descripcion' => "{$modo}|string|max:255|unique:fich_especialidades,descripcion{$sufijo}",
                'perfil'      => 'nullable|string|in:'.implode(',', FichEspecialidad::PERFILES),
                'estado'      => 'nullable|boolean',
            ],
            'tipos-servicio' => [
                'descripcion' => "{$modo}|string|max:150|unique:fich_tipos_servicio,descripcion{$sufijo}",
                'estado'      => 'nullable|boolean',
            ],
            'objetos-contrato' => [
                'descripcion' => "{$modo}|string|max:500",
                'estado'      => 'nullable|boolean',
            ],
            'obs-items' => [
                'descripcion'      => "{$modo}|string|max:500",
                'estado'           => 'nullable|boolean',
                'tipos_servicio'   => 'nullable|array',
                'tipos_servicio.*' => 'integer|exists:fich_tipos_servicio,id',
            ],
            'homologos' => [
                'code_cups'        => "{$modo}|string|max:20",
                'desc_cups'        => "{$modo}|string|max:500",
                'tipo_manual'      => "{$modo}|string|in:".implode(',', \App\Enums\FichasTecnicas\TipoManual::valores()),
                'code_manual'      => "{$modo}|string|max:20",
                'desc_manual'      => "{$modo}|string|max:500",
                'id_tipo_servicio' => 'nullable|integer|exists:fich_tipos_servicio,id',
                'uvr_grupo'        => 'nullable|string|max:30',
                'vlr_cirujano'     => 'nullable|numeric|min:0',
                'vlr_aneste'       => 'nullable|numeric|min:0',
                'valor'            => 'nullable|numeric|min:0',
                'pbs'              => 'nullable|boolean',
                'observaciones'    => 'nullable|string',
                'estado'           => 'nullable|boolean',
            ],
            default => [],
        };

        $validator = Validator::make($data, $reglas);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Unicidad compuesta de homólogos (el legacy la comprobaba a mano).
        if ($catalogo === 'homologos' && isset($data['code_cups'], $data['code_manual'])) {
            $existe = FichHomologo::query()
                ->where('code_cups', $data['code_cups'])
                ->where('code_manual', $data['code_manual'])
                ->when($id !== null, fn ($q) => $q->where('id', '!=', $id))
                ->exists();

            if ($existe) {
                throw ValidationException::withMessages([
                    'code_manual' => "La homologación {$data['code_cups']} → {$data['code_manual']} ya existe.",
                ]);
            }
        }
    }

    /**
     * Normaliza el payload: mayúsculas en nombres/descripciones (el legacy lo
     * hacía con `onchange="this.value=this.value.toUpperCase()"` en el HTML,
     * lo que era fácil de saltar) y quita las claves de relaciones.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizar(string $catalogo, array $data): array
    {
        unset($data['especialidades'], $data['tipos_servicio']);

        foreach (['nombre', 'descripcion', 'desc_cups', 'desc_manual', 'rep_legal', 'perfil'] as $campo) {
            if (isset($data[$campo]) && is_string($data[$campo])) {
                $data[$campo] = mb_strtoupper(trim($data[$campo]), 'UTF-8');
            }
        }

        if ($catalogo === 'obs-items' && ! isset($data['usuario_crea_id'])) {
            $data['usuario_crea_id'] = auth('api')->id();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sincronizarRelaciones(string $catalogo, Model $registro, array $data): void
    {
        if ($catalogo === 'profesionales' && isset($data['especialidades'])) {
            /** @var FichProfesional $registro */
            $registro->especialidades()->sync(array_map('intval', (array) $data['especialidades']));
        }

        if ($catalogo === 'obs-items' && isset($data['tipos_servicio'])) {
            /** @var FichObsItem $registro */
            $registro->tiposServicio()->sync(array_map('intval', (array) $data['tipos_servicio']));
        }
    }
}
