<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Facades\DB;

class MatrizObsActivoC extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'matzobs_activos_c';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'id_activo_glpi',
        'id_empresa',
        'id_sucursal',
        'id_sede',
        'nombre_equipo',
        'agente',
        'placa',
        'serial',
        'ubicacion',
        'usuario_glpi',
        'puntaje',
        'usuario_modificacion',
        'date_u_sincronizacion',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id_empresa' => 'integer',
        'id_sucursal' => 'integer',
        'id_sede' => 'integer',
        'puntaje' => 'decimal:2',
        'date_u_sincronizacion' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación uno a uno con la tabla detalle
     */
    public function detalle(): HasOne
    {
        return $this->hasOne(MatrizObsActivoD::class, 'activo_c_id');
    }

    /**
     * Relación con Sucursal
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'id_sucursal');
    }

    /**
     * Relación con Sede
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'id_sede');
    }

    /**
     * Relación con Empresa
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    /**
     * Scope para buscar por ID de activo GLPI
     */
    public function scopeByGlpiId($query, $glpiId)
    {
        return $query->where('id_activo_glpi', $glpiId);
    }

    /**
     * Scope para buscar por sucursal
     */
    public function scopeBySucursal($query, $sucursalId)
    {
        return $query->where('id_sucursal', $sucursalId);
    }

    /**
     * Scope para buscar por sede
     */
    public function scopeBySede($query, $sedeId)
    {
        return $query->where('id_sede', $sedeId);
    }

    /**
     * Scope para buscar por empresa
     */
    public function scopeByEmpresa($query, $empresaId)
    {
        return $query->where('id_empresa', $empresaId);
    }

    /**
     * Subconsulta que resuelve la unidad funcional de un activo.
     * Coincide el texto de ubicación con el nombre de la unidad, o con el
     * nombre sin el sufijo de empresa (FACTURACION → FACTURACION-EAL).
     */
    public static function sqlIdUnidadFuncional(string $alias): string
    {
        return "(
            SELECT uf.id
            FROM config_unidades_funcionales uf
            WHERE uf.estado = 1
              AND uf.id_sucursal = {$alias}.id_sucursal
              AND (
                    (uf.id_sede IS NULL AND {$alias}.id_sede IS NULL)
                 OR uf.id_sede = {$alias}.id_sede
              )
              AND {$alias}.ubicacion IS NOT NULL
              AND {$alias}.ubicacion <> ''
              AND (
                    UPPER(TRIM(uf.nombre)) = UPPER(TRIM({$alias}.ubicacion))
                 OR UPPER(TRIM(uf.nombre)) LIKE CONCAT(UPPER(TRIM({$alias}.ubicacion)), '-%')
              )
            ORDER BY
                CASE WHEN UPPER(TRIM(uf.nombre)) = UPPER(TRIM({$alias}.ubicacion)) THEN 0 ELSE 1 END,
                CHAR_LENGTH(uf.nombre)
            LIMIT 1
        )";
    }

    /**
     * Limita la consulta a los activos cuya ubicación corresponde a la unidad funcional.
     */
    public static function aplicarCoincidenciaUnidad($query, int $unidadId, string $alias = 'matzobs_activos_c'): void
    {
        $unidad = DB::table('config_unidades_funcionales')->where('id', $unidadId)->first();
        if (!$unidad) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where($alias . '.id_sucursal', $unidad->id_sucursal);
        if ($unidad->id_sede) {
            $query->where($alias . '.id_sede', $unidad->id_sede);
        } else {
            $query->whereNull($alias . '.id_sede');
        }

        $col = $alias . '.ubicacion';
        $nombre = $unidad->nombre;
        $query->where(function ($q) use ($col, $nombre) {
            $q->whereRaw("UPPER(TRIM({$col})) = UPPER(TRIM(?))", [$nombre])
              ->orWhereRaw("UPPER(TRIM(?)) LIKE CONCAT(UPPER(TRIM({$col})), '-%')", [$nombre]);
        });
    }

    /**
     * Activos de la sucursal (y sede, si existe) que no coinciden con ninguna unidad funcional.
     */
    public static function aplicarSinUnidadFuncional($query, int $sucursalId, ?int $sedeId, string $alias = 'matzobs_activos_c'): void
    {
        $query->where($alias . '.id_sucursal', $sucursalId);
        if ($sedeId) {
            $query->where($alias . '.id_sede', $sedeId);
        } else {
            $query->whereNull($alias . '.id_sede');
        }

        $query->whereRaw(self::sqlIdUnidadFuncional($alias) . ' IS NULL');
    }

    /**
     * Scope para buscar por rango de puntaje
     */
    public function scopeByPuntajeRange($query, $min, $max)
    {
        return $query->whereBetween('puntaje', [$min, $max]);
    }

    /**
     * Scope para activos obsoletos (puntaje bajo)
     */
    public function scopeObsoletos($query, $threshold = 40)
    {
        return $query->where('puntaje', '<', $threshold);
    }

    /**
     * Scope para activos óptimos (puntaje alto)
     */
    public function scopeOptimos($query, $threshold = 80)
    {
        return $query->where('puntaje', '>=', $threshold);
    }

    /**
     * Obtener rangos funcionales desde parámetros (id_grupo = 3)
     */
    public static function getRangosFuncionales()
    {
        static $rangos = null;
        
        if ($rangos === null) {
            $rangos = MatrizObsParametro::where('id_grupo', 3)
                ->orderBy('rango_i', 'asc')
                ->get()
                ->map(function($param) {
                    return [
                        'nombre' => $param->nombre,
                        'min' => (float) $param->rango_i,
                        'max' => (float) $param->rango_f,
                        'color' => self::getColorPorNombre($param->nombre)
                    ];
                })
                ->toArray();
        }
        
        return $rangos;
    }

    /**
     * Obtener color según el nombre del estado
     */
    private static function getColorPorNombre($nombre)
    {
        $colores = [
            'OBSOLETO' => '#dc3545',      // Rojo
            'POTENCIALMENTE' => '#ffc107', // Amarillo
            'FUNCIONAL' => '#ffff00',      // Amarillo claro
            'ÓPTIMO' => '#198754'          // Verde
        ];
        
        return $colores[$nombre] ?? '#6c757d'; // Gris por defecto
    }

    /**
     * Obtener el estado del activo basado en el puntaje (dinámico desde parámetros)
     */
    public function getEstadoAttribute(): string
    {
        if ($this->puntaje === null) {
            return 'Sin clasificar';
        }

        $rangos = self::getRangosFuncionales();
        
        foreach ($rangos as $rango) {
            if ($this->puntaje >= $rango['min'] && $this->puntaje <= $rango['max']) {
                return $rango['nombre'];
            }
        }
        
        return 'Sin clasificar';
    }

    /**
     * Obtener el color del estado (dinámico desde parámetros)
     */
    public function getColorEstadoAttribute(): string
    {
        if ($this->puntaje === null) {
            return '#6c757d'; // Gris
        }

        $rangos = self::getRangosFuncionales();
        
        foreach ($rangos as $rango) {
            if ($this->puntaje >= $rango['min'] && $this->puntaje <= $rango['max']) {
                return $rango['color'];
            }
        }
        
        return '#6c757d'; // Gris por defecto
    }

    /**
     * Obtener información completa del activo con detalle
     */
    public function getInfoCompleta()
    {
        return $this->load('detalle');
    }
}