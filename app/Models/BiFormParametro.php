<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiFormParametro extends Model
{
    protected $table = 'bi_form_parametros';

    protected $fillable = [
        'formulario_codigo',
        'campos',
        'usuario_actualiza_id',
    ];

    protected $casts = [
        'campos' => 'array',
    ];

    public function usuarioActualiza(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_actualiza_id');
    }
}
