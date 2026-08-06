<?php

declare(strict_types=1);

namespace App\Models\Accounting\FichasTecnicas;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de cambios de estado de una ficha.
 *
 * No existía en el legacy: la escribe el trigger `trg_fich_fichas_au`,
 * por lo que este modelo es de solo lectura desde la aplicación.
 *
 * @property int      $id
 * @property int      $id_ficha
 * @property int|null $id_estado_anterior
 * @property int      $id_estado_nuevo
 * @property int|null $id_usuario
 * @property string|null $observacion
 */
class FichHistorialEstado extends Model
{
    protected $table = 'fich_historial_estados';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function ficha(): BelongsTo
    {
        return $this->belongsTo(FichFicha::class, 'id_ficha');
    }

    public function estadoAnterior(): BelongsTo
    {
        return $this->belongsTo(FichEstado::class, 'id_estado_anterior');
    }

    public function estadoNuevo(): BelongsTo
    {
        return $this->belongsTo(FichEstado::class, 'id_estado_nuevo');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }
}
