<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UsuarioContexto extends Model
{
    use HasFactory;

    protected $table = 'seg_usuario_contexto';

    protected $fillable = [
        'user_id',
        'empresa_id',
        'sucursal_id',
        'sede_id',
        'ultima_actualizacion'
    ];

    protected $casts = [
        'ultima_actualizacion' => 'datetime'
    ];

    /**
     * Relación con Usuario
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación con Empresa
     */
    public function empresa()
    {
        return $this->belongsTo(Empresa::class);
    }

    /**
     * Relación con Sucursal
     */
    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * Relación con Sede
     */
    public function sede()
    {
        return $this->belongsTo(Sede::class);
    }

    /**
     * Obtener o crear contexto para un usuario
     */
    public static function obtenerContexto(User $user)
    {
        return self::with(['empresa', 'sucursal', 'sede'])
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * Establecer contexto para un usuario
     */
    public static function establecerContexto(User $user, $empresaId, $sucursalId = null, $sedeId = null)
    {
        return self::updateOrCreate(
            ['user_id' => $user->id],
            [
                'empresa_id' => $empresaId,
                'sucursal_id' => $sucursalId,
                'sede_id' => $sedeId,
                'ultima_actualizacion' => now()
            ]
        );
    }

    /**
     * Limpiar contexto de un usuario
     */
    public static function limpiarContexto(User $user)
    {
        return self::where('user_id', $user->id)->delete();
    }
}
