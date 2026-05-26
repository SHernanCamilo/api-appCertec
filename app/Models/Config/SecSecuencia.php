<?php

namespace App\Models\Config;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\User;

class SecSecuencia extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'config_sec_secuencias';

    protected $fillable = [
        'empresa_id',
        'modulo_id',
        'proceso_id',
        'es_manual',
        'ambito',
        'es_secuencial',
        'rango',
        'estado',
        'created_by',
    ];

    protected $casts = [
        'es_manual'     => 'boolean',
        'es_secuencial' => 'boolean',
        'estado'        => 'boolean',
        'rango'         => 'integer',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
        'deleted_at'    => 'datetime',
    ];

    protected $attributes = [
        'es_manual'     => false,
        'ambito'        => 'empresa',
        'es_secuencial' => true,
        'rango'         => 4,
        'estado'        => true,
    ];

    // Valores válidos para el campo ambito
    const AMBITO_EMPRESA   = 'empresa';
    const AMBITO_SUCURSAL  = 'sucursal';
    const AMBITO_SEDE      = 'sede';

    // ─── Relaciones ───────────────────────────────────────────────

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function modulo(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'modulo_id');
    }

    public function proceso(): BelongsTo
    {
        return $this->belongsTo(Modulo::class, 'proceso_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(SecDetalle::class, 'secuencia_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }

    public function scopePorEmpresa($query, int $empresaId)
    {
        return $query->where('empresa_id', $empresaId);
    }
}
