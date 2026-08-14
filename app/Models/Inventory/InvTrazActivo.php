<?php

declare(strict_types=1);

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Novedad de toma de inventario sobre un activo fijo.
 *
 * Registro inmutable: cada toma crea una fila nueva. El historial de un activo
 * es la secuencia de filas con la misma placa ordenadas por created_at.
 */
class InvTrazActivo extends Model
{
    /** Estados físicos que puede reportar el inventariador. */
    public const ESTADO_FISICO_BUENO     = 'En buen estado';
    public const ESTADO_FISICO_REPARACION = 'Para Reparacion';
    public const ESTADO_FISICO_BAJA      = 'Dar de baja';

    public const ESTADOS_FISICOS = [
        self::ESTADO_FISICO_BUENO,
        self::ESTADO_FISICO_REPARACION,
        self::ESTADO_FISICO_BAJA,
    ];

    /** Estados administrativos del activo en Indigo. */
    public const ESTADOS = ['Activo', 'Inactivo'];

    /**
     * Campos de novedad y la etiqueta que se muestra en el historial.
     *
     * @var array<string, string>
     */
    public const CAMPOS_NOVEDAD = [
        'novedad_placa'            => 'Placa',
        'novedad_estado'           => 'Estado',
        'novedad_articulo'         => 'Artículo',
        'novedad_marca'            => 'Marca',
        'novedad_modelo'           => 'Modelo',
        'novedad_serie'            => 'Serie',
        'novedad_responsable'      => 'Responsable',
        'novedad_localizacion'     => 'Localización',
        'novedad_tipo_inventario'  => 'Tipo de inventario',
        'novedad_sucursal'         => 'Sucursal',
        'novedad_estado_fisico'    => 'Estado físico',
    ];

    protected $table = 'inv_traz_activo';

    protected $fillable = [
        'placa',
        'serie',
        'articulo_codigo',
        'articulo_nombre',
        'valores_origen',
        'novedad_placa',
        'novedad_estado',
        'novedad_articulo',
        'novedad_marca',
        'novedad_modelo',
        'novedad_serie',
        'novedad_responsable',
        'novedad_localizacion',
        'novedad_tipo_inventario',
        'novedad_sucursal',
        'novedad_estado_fisico',
        'observacion',
        'sucursal_origen',
        'id_empresa',
        'id_sucursal',
        'registrado_por',
    ];

    protected $casts = [
        'valores_origen' => 'array',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
    ];

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeDePlaca(Builder $query, string $placa): Builder
    {
        return $query->where('placa', $placa);
    }

    public function scopeRecientesPrimero(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /** Solo las novedades que piden dar de baja el activo. */
    public function scopeParaBaja(Builder $query): Builder
    {
        return $query->where('novedad_estado_fisico', self::ESTADO_FISICO_BAJA);
    }

    // =========================================================================
    // DERIVADOS
    // =========================================================================

    /**
     * Lista de cambios de esta novedad: campo, valor anterior (de Indigo) y
     * valor reportado. Es lo que se muestra en el historial del activo.
     *
     * @return list<array{campo: string, etiqueta: string, anterior: ?string, nuevo: string}>
     */
    public function cambios(): array
    {
        $origen  = $this->valores_origen ?? [];
        $cambios = [];

        foreach (self::CAMPOS_NOVEDAD as $campo => $etiqueta) {
            $nuevo = $this->{$campo};

            if ($nuevo === null || trim((string) $nuevo) === '') {
                continue;
            }

            $cambios[] = [
                'campo'    => $campo,
                'etiqueta' => $etiqueta,
                'anterior' => $this->valorOrigen($campo, $origen),
                'nuevo'    => (string) $nuevo,
            ];
        }

        return $cambios;
    }

    public function tieneCambios(): bool
    {
        return $this->cambios() !== [];
    }

    /**
     * Busca en el snapshot de Fabric el valor que tenía el campo antes.
     *
     * Los nombres de columna de la vista no siempre coinciden con los de la
     * tabla, así que se prueban varias formas del mismo nombre.
     *
     * @param array<string, mixed> $origen
     */
    private function valorOrigen(string $campoNovedad, array $origen): ?string
    {
        $base = str_replace('novedad_', '', $campoNovedad);

        $candidatos = [
            $base,
            str_replace('_', '', $base),
            ucfirst($base),
            ucwords($base, '_'),
            str_replace('_', '', ucwords($base, '_')),
        ];

        // tipo_inventario → TipoInventario, estado_fisico → Estado_Fisico
        $candidatos[] = str_replace(' ', '', ucwords(str_replace('_', ' ', $base)));
        $candidatos[] = ucwords($base, '_');

        $normalizado = [];
        foreach ($origen as $clave => $valor) {
            $normalizado[strtolower(str_replace(['_', ' '], '', (string) $clave))] = $valor;
        }

        foreach ($candidatos as $candidato) {
            $clave = strtolower(str_replace(['_', ' '], '', $candidato));
            if (array_key_exists($clave, $normalizado)) {
                $valor = $normalizado[$clave];
                return $valor === null || $valor === '' ? null : (string) $valor;
            }
        }

        return null;
    }
}
