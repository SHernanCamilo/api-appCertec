<?php

namespace App\Models\Inventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvComprasAuditoria extends Model
{
    protected $table = 'inv_compras_auditoria';
    public $timestamps = false;

    protected $fillable = [
        'compra_id', 'campo_modificado', 'valor_anterior',
        'valor_nuevo', 'motivo_modificacion', 'modificado_por',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function compra(): BelongsTo
    {
        return $this->belongsTo(InvOrdenCompra::class, 'compra_id');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modificado_por');
    }
}
