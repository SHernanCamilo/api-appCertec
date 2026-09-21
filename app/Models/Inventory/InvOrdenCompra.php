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
        'es_sincronizada', 'origen', 'puede_editar', 'pedidos_relacionados',
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

    /**
     * Pedidos vinculados a la OC por la relación N:N (inv_compras_pedidos).
     * Se llena en las OC sincronizadas de Indigo (cuando la descripción trae el
     * consecutivo del pedido) y en cualquier OC que se haya vinculado explícitamente.
     */
    public function pedidos()
    {
        return $this->belongsToMany(
            InvPedido::class,
            'inv_compras_pedidos',
            'compra_id',
            'pedido_id'
        );
    }

    /**
     * Accessor: pedidos relacionados a la OC, para mostrarlos en la vista.
     *
     * Combina DOS fuentes para cubrir tanto OC automáticas como manuales:
     *   1. La relación N:N inv_compras_pedidos (típico de las OC de Indigo).
     *   2. Los pedidos deducidos por el pedido_detalle_id de cada detalle
     *      (típico de las OC creadas a mano desde el aplicativo).
     *
     * Devuelve una lista sin duplicados: [{ id, numero_pedido }].
     */
    public function getPedidosRelacionadosAttribute(): array
    {
        $mapa = []; // pedido_id => numero_pedido

        // 1) Relación N:N (si está cargada o se puede cargar).
        foreach ($this->pedidos as $ped) {
            $mapa[(int) $ped->id] = $ped->numero_pedido;
        }

        // 2) Pedidos deducidos por los detalles (pedido_detalle_id → pedido).
        //    Si las relaciones vienen eager-loaded (detalles.pedidoDetalle.pedido),
        //    se usan directamente para evitar consultas N+1. Si no, se resuelven
        //    con una única consulta por los ids faltantes.
        $faltantes = [];
        foreach ($this->detalles as $det) {
            if (empty($det->pedido_detalle_id)) {
                continue;
            }
            $pd = $det->relationLoaded('pedidoDetalle') ? $det->pedidoDetalle : null;
            if ($pd && $pd->relationLoaded('pedido') && $pd->pedido) {
                $mapa[(int) $pd->pedido->id] = $pd->pedido->numero_pedido;
            } else {
                $faltantes[] = (int) $det->pedido_detalle_id;
            }
        }

        if (!empty($faltantes)) {
            $porDetalle = InvPedidoDetalle::whereIn('id', array_unique($faltantes))
                ->with('pedido:id,numero_pedido')
                ->get();
            foreach ($porDetalle as $det) {
                if ($det->pedido) {
                    $mapa[(int) $det->pedido->id] = $det->pedido->numero_pedido;
                }
            }
        }

        return collect($mapa)
            ->map(fn ($numero, $id) => ['id' => (int) $id, 'numero_pedido' => $numero])
            ->values()
            ->all();
    }
}
