<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiGrupo extends Model
{
    public const TIPO_ESQUEMA = 1;
    public const TIPO_DEPARTAMENTO = 2;

    protected $table = 'bi_grupos';

    protected $fillable = [
        'codigo',
        'tipo',
        'descripcion',
        'usuario_crea_id',
        'usuario_modifica_id',
    ];

    protected $casts = [
        'tipo' => 'integer',
    ];

    public function usuarioCrea(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_crea_id');
    }

    public function usuarioModifica(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_modifica_id');
    }
}
