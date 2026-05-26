<?php

namespace App\Models\Config;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Sucursal;
use App\Models\Sede;
use App\Models\User;

class SecDetalle extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'config_sec_detalles';

    protected $fillable = [
        'secuencia_id',
        'patron_id',
        'sucursal_id',
        'sede_id',
        'siguiente_numero',
        'estado',
        'created_by',
    ];

    protected $casts = [
        'siguiente_numero' => 'integer',
        'estado'           => 'boolean',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
        'deleted_at'       => 'datetime',
    ];

    protected $attributes = [
        'siguiente_numero' => 1,
        'estado'           => true,
    ];

    // ─── Relaciones ───────────────────────────────────────────────

    public function secuencia(): BelongsTo
    {
        return $this->belongsTo(SecSecuencia::class, 'secuencia_id');
    }

    public function patron(): BelongsTo
    {
        return $this->belongsTo(SecPatron::class, 'patron_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'sucursal_id');
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }
}
