<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InvCompraAuditoria extends Model
{
    use HasFactory;

    protected $table = 'inv_compras_auditoria';
    public $timestamps = false; // only created_at

    protected $fillable = [
        'compra_id',
        'campo_modificado',
        'valor_anterior',
        'valor_nuevo',
        'motivo_modificacion',
        'modificado_por',
        'created_at'
    ];
}
