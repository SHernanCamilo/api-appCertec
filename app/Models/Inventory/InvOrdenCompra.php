<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class InvOrdenCompra extends Model
{
    use HasFactory;

    protected $table = 'inv_ordenes_compra';

    protected $fillable = [
        'numero_orden_compra', 'fecha_orden', 'observaciones', 'proveedor_nombre',
        'estado', 'sincronizado_indigo', 'creado_por', 'oc_indigo', 'sucursal_id'
    ];

    protected $casts = [
        'sincronizado_indigo' => 'boolean',
        'fecha_orden'         => 'date',
    ];

    protected $appends = [
        'total', 'items_count', 'creado_por_nombre',
        'es_sincronizada', 'origen', 'puede_editar',
    ];

    /**
     * Accessor: indica si la OC proviene de una sincronización externa (Indigo u otro).
     * Se considera sincronizada si tiene la marca o si trae número de OC de Indigo.
     */
    public function getEsSincronizadaAttribute(): bool
    {
        return (bool) $this->sincronizado_indigo
            || !empty($this->oc_indigo);
    }

    /**
     * Accessor: origen legible de la OC ('indigo' | 'aplicativo').
     */
    public function getOrigenAttribute(): string
    {
        return $this->es_sincronizada ? 'indigo' : 'aplicativo';
    }

    /**
     * Accessor: indica si la OC es editable en general (sin considerar el usuario).
     * Solo las creadas desde el aplicativo y en estado 'pendiente' pueden editarse.
     * La verificación de propiedad (creado_por = usuario) se hace en el controlador,
     * donde está disponible el usuario autenticado.
     */
    public function getPuedeEditarAttribute(): bool
    {
        return !$this->es_sincronizada
            && strtolower((string) $this->estado) === 'pendiente';
    }

    /**
     * Determina si un usuario específico puede editar/eliminar esta OC.
     * Regla: creada desde el aplicativo (no sincronizada), en estado pendiente
     * y que el usuario sea su creador.
     */
    public function puedeEditarPorUsuario(int $userId): bool
    {
        return $this->puede_editar && (int) $this->creado_por === $userId;
    }

    /**
     * Accessor: Total de la OC (suma de cantidad × precio de cada detalle)
     */
    public function getTotalAttribute(): float
    {
        return $this->detalles->sum(function ($detalle) {
            return ($detalle->cantidad_solicitada_compra ?? 0) * ($detalle->precio_unitario_compra ?? 0);
        });
    }

    /**
     * Accessor: Cantidad de ítems en la OC
     */
    public function getItemsCountAttribute(): int
    {
        return $this->detalles->count();
    }

    /**
     * Accessor: Nombre del creador
     */
    public function getCreadoPorNombreAttribute(): string
    {
        return $this->creador?->name ?? 'Administrador del Sistema';
    }

    public function detalles()
    {
        return $this->hasMany(InvOrdenCompraDetalle::class, 'compra_id');
    }

    public function creador()
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function sucursal()
    {
        return $this->belongsTo(\App\Models\Sucursal::class, 'sucursal_id');
    }
    
    public function recepciones()
    {
        return $this->hasMany(InvRecepcion::class, 'compra_id');
    }
}
