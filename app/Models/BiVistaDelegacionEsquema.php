<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

class BiVistaDelegacionEsquema extends Model
{
    protected $table = 'bi_vista_delegacion_esquemas';

    protected $fillable = [
        'empresa_id',
        'id_bi_grupos_origen',
        'id_bi_grupos_destino',
        'id_bi_vista',
    ];

    protected static function booted(): void
    {
        $clear = fn () => Cache::forget('bi_vista_delegacion_esquemas_index');

        static::saved($clear);
        static::deleted($clear);
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function grupoOrigen(): BelongsTo
    {
        return $this->belongsTo(BiGrupo::class, 'id_bi_grupos_origen');
    }

    public function grupoDestino(): BelongsTo
    {
        return $this->belongsTo(BiGrupo::class, 'id_bi_grupos_destino');
    }

    public function vista(): BelongsTo
    {
        return $this->belongsTo(BiVista::class, 'id_bi_vista');
    }
}
