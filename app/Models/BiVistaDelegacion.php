<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class BiVistaDelegacion extends Model
{
    protected $table = 'bi_vista_delegaciones';

    protected $fillable = [
        'empresa_id',
        'id_bi_grupos',
        'id_bi_vista',
    ];

    protected static function booted(): void
    {
        $clear = fn () => Cache::forget('bi_vista_delegaciones_index');

        static::saved($clear);
        static::deleted($clear);
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
