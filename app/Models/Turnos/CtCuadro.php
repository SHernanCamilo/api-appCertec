<?php

namespace App\Models\Turnos;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;

class CtCuadro extends Model
{
    protected $table = 'humtal_ct_cuadro';

    // Estados del cuadro
    const ESTADO_BORRADOR   = 'borrador';
    const ESTADO_PUBLICADO  = 'publicado';
    const ESTADO_CERRADO    = 'cerrado';

    protected $fillable = [
        'id_grupo',
        'anio',
        'mes',
        'estado',
        'observaciones',
        'creado_por',
        'publicado_por',
        'fecha_publicacion',
        'cerrado_por',
        'fecha_cierre',
    ];

    protected $casts = [
        'anio'              => 'integer',
        'mes'               => 'integer',
        'fecha_publicacion' => 'datetime',
        'fecha_cierre'      => 'datetime',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(CtGrupo::class, 'id_grupo');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function publicadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publicado_por');
    }

    public function cerradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(CtAsignacion::class, 'id_cuadro');
    }

    public function novedades(): HasMany
    {
        return $this->hasMany(CtNovedad::class, 'id_cuadro');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeBorrador($query)
    {
        return $query->where('estado', self::ESTADO_BORRADOR);
    }

    public function scopePublicados($query)
    {
        return $query->where('estado', self::ESTADO_PUBLICADO);
    }

    public function scopeCerrados($query)
    {
        return $query->where('estado', self::ESTADO_CERRADO);
    }

    public function scopePorGrupo($query, int $idGrupo)
    {
        return $query->where('id_grupo', $idGrupo);
    }

    public function scopePorPeriodo($query, int $anio, int $mes)
    {
        return $query->where('anio', $anio)->where('mes', $mes);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public function esBorrador(): bool
    {
        return $this->estado === self::ESTADO_BORRADOR;
    }

    public function esPublicado(): bool
    {
        return $this->estado === self::ESTADO_PUBLICADO;
    }

    public function esCerrado(): bool
    {
        return $this->estado === self::ESTADO_CERRADO;
    }

    /**
     * Retorna el nombre del mes en español.
     */
    public function getNombreMes(): string
    {
        $meses = [
            1  => 'Enero',
            2  => 'Febrero',
            3  => 'Marzo',
            4  => 'Abril',
            5  => 'Mayo',
            6  => 'Junio',
            7  => 'Julio',
            8  => 'Agosto',
            9  => 'Septiembre',
            10 => 'Octubre',
            11 => 'Noviembre',
            12 => 'Diciembre',
        ];

        return $meses[$this->mes] ?? "Mes {$this->mes}";
    }
}
