<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class BiVistaDelegacionUsuario extends Model
{
    protected $table = 'bi_vista_delegacion_usuarios';

    protected $fillable = [
        'user_id',
        'empresa_id',
        'id_bi_grupos',
        'id_bi_vista',
    ];

    protected static function booted(): void
    {
        $clear = function () {
            Cache::forget('bi_vista_delegacion_usuarios_index');
        };

        static::saved($clear);
        static::deleted($clear);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function grupo(): BelongsTo
    {
        return $this->belongsTo(BiGrupo::class, 'id_bi_grupos');
    }

    public function vista(): BelongsTo
    {
        return $this->belongsTo(BiVista::class, 'id_bi_vista');
    }
}
