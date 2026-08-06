<?php

declare(strict_types=1);

namespace App\Models\Accounting\FichasTecnicas;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Observación libre asociada a una ficha técnica.
 *
 * Legacy: `observaciones`.
 *
 * @property int      $id
 * @property int      $id_ficha
 * @property string   $desc_obs
 * @property int|null $usuario_crea_id
 */
class FichObservacion extends Model
{
    protected $table = 'fich_observaciones';

    protected $fillable = ['id_ficha', 'desc_obs', 'usuario_crea_id'];

    public function ficha(): BelongsTo
    {
        return $this->belongsTo(FichFicha::class, 'id_ficha');
    }

    public function usuarioCrea(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_crea_id');
    }
}
