<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatrizObsAgente extends Model
{
    use HasFactory;

    /**
     * Nombre de la tabla asociada al modelo
     */
    protected $table = 'matzobs_agentes';

    /**
     * Los atributos que son asignables en masa
     */
    protected $fillable = [
        'tag',
        'id_empresa',
        'id_sucursal',
        'id_sede',
    ];

    /**
     * Los atributos que deben ser convertidos a tipos nativos
     */
    protected $casts = [
        'id_empresa' => 'integer',
        'id_sucursal' => 'integer',
        'id_sede' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Relación con Empresa
     */
    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
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
     * Scope para buscar por tag
     */
    public function scopeByTag($query, $tag)
    {
        return $query->where('tag', 'like', "%{$tag}%");
    }

    /**
     * Scope para buscar por empresa
     */
    public function scopeByEmpresa($query, $empresaId)
    {
        return $query->where('id_empresa', $empresaId);
    }

    /**
     * Scope para buscar por sucursal
     */
    public function scopeBySucursal($query, $sucursalId)
    {
        return $query->where('id_sucursal', $sucursalId);
    }
}
