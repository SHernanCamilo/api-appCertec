<?php

namespace App\Models\Config;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Empresa;
use App\Models\User;

class SecPatron extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'config_sec_patrones';

    protected $fillable = [
        'empresa_id',
        'nombre',
        'patron',
        'descripcion',
        'estado',
        'created_by',
    ];

    protected $casts = [
        'estado'     => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected $attributes = [
        'estado' => true,
    ];

    // ─── Relaciones ───────────────────────────────────────────────

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(SecDetalle::class, 'patron_id');
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

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * Cuenta cuántos '#' tiene el patrón (define el padding del consecutivo)
     */
    public function cantidadDigitos(): int
    {
        return substr_count($this->patron, '#');
    }
}
