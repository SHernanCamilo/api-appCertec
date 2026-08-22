<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trazabilidad de Activos Fijos — registro de novedades encontradas durante
 * la toma de inventario físico.
 *
 * Cada registro representa una inspección realizada por un inventariador en una
 * fecha específica. No se actualiza ni elimina: el historial completo del activo
 * es la secuencia de registros ordenada por fecha.
 *
 * @property int $id
 * @property string $placa
 * @property int $tipo_inventario_id
 * @property string|null $serie
 * @property string|null $articulo_codigo
 * @property string|null $articulo_nombre
 * @property array|null $valores_origen Snapshot del maestro de Indigo al momento de la toma
 * @property string|null $novedad_placa
 * @property string|null $novedad_estado
 * @property string|null $novedad_articulo
 * @property string|null $novedad_marca
 * @property string|null $novedad_modelo
 * @property string|null $novedad_serie
 * @property string|null $novedad_responsable
 * @property string|null $novedad_localizacion
 * @property string|null $novedad_tipo_inventario
 * @property string|null $novedad_sucursal
 * @property string|null $novedad_estado_fisico
 * @property string|null $observacion
 * @property string|null $sucursal_origen
 * @property int|null $id_empresa
 * @property int|null $id_sucursal
 * @property int $registrado_por
 * @property bool $es_externo Indica si el activo no existe en el maestro de Indigo
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class TrazabilidadActivo extends Model
{
    use HasFactory;

    protected $table = 'inv_traz_activo';

    protected $fillable = [
        'placa',
        'tipo_inventario_id',
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
        'es_externo',
    ];

    protected $casts = [
        'valores_origen' => 'array',
        'es_externo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // ═══════════════════════════════════════════════════════════════════════
    // RELACIONES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Relación con el tipo de inventario
     */
    public function tipoInventario(): BelongsTo
    {
        return $this->belongsTo(TipoInventario::class, 'tipo_inventario_id');
    }

    /**
     * Relación con el usuario que registró la toma
     */
    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // SCOPES
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Scope: filtrar por placa
     */
    public function scopePorPlaca($query, string $placa)
    {
        return $query->where('placa', $placa);
    }

    /**
     * Scope: filtrar por tipo de inventario
     */
    public function scopePorTipoInventario($query, int $tipoInventarioId)
    {
        return $query->where('tipo_inventario_id', $tipoInventarioId);
    }

    /**
     * Scope: filtrar por estado físico
     */
    public function scopePorEstadoFisico($query, string $estadoFisico)
    {
        return $query->where('novedad_estado_fisico', $estadoFisico);
    }

    /**
     * Scope: filtrar por rango de fechas
     */
    public function scopeEntreFechas($query, $desde, $hasta)
    {
        return $query->whereBetween('created_at', [$desde, $hasta]);
    }

    /**
     * Scope: solo activos externos (no están en el maestro de Indigo)
     */
    public function scopeExternos($query)
    {
        return $query->where('es_externo', true);
    }

    /**
     * Scope: solo activos del maestro (sí están en Indigo)
     */
    public function scopeDelMaestro($query)
    {
        return $query->where('es_externo', false);
    }

    /**
     * Scope: ordenar por fecha de registro (más reciente primero)
     */
    public function scopeRecientes($query)
    {
        return $query->orderBy('created_at', 'desc');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // MÉTODOS DE INSTANCIA
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Cuenta cuántos campos tienen novedad (excluyendo observación)
     */
    public function contarNovedades(): int
    {
        $camposNovedad = [
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
        ];

        $contador = 0;
        foreach ($camposNovedad as $campo) {
            if (!empty($this->$campo)) {
                $contador++;
            }
        }

        return $contador;
    }

    /**
     * Verifica si este registro tiene al menos una novedad
     */
    public function tieneNovedades(): bool
    {
        return $this->contarNovedades() > 0;
    }

    /**
     * Obtiene un resumen de las novedades
     */
    public function getResumenNovedadesAttribute(): array
    {
        $novedades = [];
        $camposNovedad = [
            'novedad_placa' => 'Placa',
            'novedad_estado' => 'Estado',
            'novedad_articulo' => 'Artículo',
            'novedad_marca' => 'Marca',
            'novedad_modelo' => 'Modelo',
            'novedad_serie' => 'Serie',
            'novedad_responsable' => 'Responsable',
            'novedad_localizacion' => 'Localización',
            'novedad_tipo_inventario' => 'Tipo Inventario',
            'novedad_sucursal' => 'Sucursal',
            'novedad_estado_fisico' => 'Estado Físico',
        ];

        foreach ($camposNovedad as $campo => $etiqueta) {
            if (!empty($this->$campo)) {
                $novedades[$etiqueta] = $this->$campo;
            }
        }

        return $novedades;
    }
}
