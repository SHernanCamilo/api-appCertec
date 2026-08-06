<?php

declare(strict_types=1);

namespace App\Models\Accounting\FichasTecnicas;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comentario de validación (autorización / aprobación / rechazo).
 *
 * Legacy: `comentarios`.
 *
 * @property int      $id
 * @property int      $id_ficha
 * @property int      $id_usuario
 * @property int|null $id_estado
 * @property string   $descripcion
 */
class FichComentario extends Model
{
    protected $table = 'fich_comentarios';

    protected $fillable = ['id_ficha', 'id_usuario', 'id_estado', 'descripcion'];

    public function ficha(): BelongsTo
    {
        return $this->belongsTo(FichFicha::class, 'id_ficha');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    public function estado(): BelongsTo
    {
        return $this->belongsTo(FichEstado::class, 'id_estado');
    }
}
