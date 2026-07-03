<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvRecepcionDetalle extends Model
{
    use HasFactory;

    protected $table = 'inv_recepcion_detalles';

    protected $fillable = [
        'recepcion_id', 'pedido_detalle_id', 'codigo_producto', 'producto_nombre',
        'cantidad_solicitada', 'cantidad_recibida', 'numero_lote', 'fecha_vencimiento',
        'codigo_sanitario', 'aspecto_cumple', 'embalaje_cumple', 'contenido_cumple',
        'cadena_frio_temperatura', 'concepto_recepcion', 'es_medicamento_vital',
        'mvd_ium', 'mvd_solicitante', 'mvd_principio_activo', 'mvd_forma_farmaceutica',
        'mvd_presentacion_comercial', 'mvd_fecha_autorizacion', 'observaciones_recepcion'
    ];

    public function recepcion()
    {
        return $this->belongsTo(InvRecepcion::class, 'recepcion_id');
    }

    public function pedidoDetalle()
    {
        return $this->belongsTo(InvPedidoDetalle::class, 'pedido_detalle_id');
    }
}
