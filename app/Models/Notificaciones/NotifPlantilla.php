<?php

namespace App\Models\Notificaciones;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class NotifPlantilla extends Model
{
    protected $table = 'notif_plantillas';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'contenido',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Solo plantillas activas.
     */
    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('estado', true);
    }

    /**
     * Buscar plantilla por código.
     */
    public function scopePorCodigo(Builder $query, string $codigo): Builder
    {
        return $query->where('codigo', $codigo);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Renderiza el contenido reemplazando variables {{variable}} con los datos.
     *
     * @param array<string, string> $variables
     */
    public function renderizar(array $variables): string
    {
        $contenido = $this->contenido;

        foreach ($variables as $key => $value) {
            $contenido = str_replace('{{' . $key . '}}', $value ?? '', $contenido);
        }

        return $contenido;
    }
}
