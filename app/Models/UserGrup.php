<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserGrup extends Model
{
    public const TIPO_VISTA_BD     = 'vista_bd';
    public const TIPO_DEPARTAMENTO = 'departamento';

    public const ORIGEN_AZURE = 'Azure';
    public const ORIGEN_LOCAL = 'local';

    protected $table = 'users_grups';

    protected $fillable = [
        'id_user',
        'tipo',
        'permiso',
        'origen',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_user');
    }
}
