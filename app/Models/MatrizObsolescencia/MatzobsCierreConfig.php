<?php

namespace App\Models\MatrizObsolescencia;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Modelo: MatzobsCierreConfig
 *
 * Tabla de configuración global del proceso de cierre de inventario.
 * Siempre existe una única fila (id = 1).
 * Se puede leer con MatzobsCierreConfig::config() y actualizar con update().
 *
 * @property int         $id
 * @property bool        $recalcular_antes_de_cerrar
 * @property bool        $incluir_sin_puntaje
 * @property bool        $incluir_inactivos
 * @property bool        $notificar_al_cerrar
 * @property string|null $emails_notificacion
 * @property int         $max_cierres_a_conservar
 * @property string|null $modificado_por
 */
class MatzobsCierreConfig extends Model
{
    use HasFactory;

    protected $table = 'matzobs_cierre_config';

    protected $fillable = [
        'recalcular_antes_de_cerrar',
        'incluir_sin_puntaje',
        'incluir_inactivos',
        'notificar_al_cerrar',
        'emails_notificacion',
        'max_cierres_a_conservar',
        'modificado_por',
    ];

    protected $casts = [
        'recalcular_antes_de_cerrar' => 'boolean',
        'incluir_sin_puntaje'        => 'boolean',
        'incluir_inactivos'          => 'boolean',
        'notificar_al_cerrar'        => 'boolean',
        'max_cierres_a_conservar'    => 'integer',
    ];

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Obtiene la configuración activa (siempre id = 1).
     * Si no existe la crea con valores por defecto.
     */
    public static function config(): self
    {
        return self::firstOrCreate(
            ['id' => 1],
            [
                'recalcular_antes_de_cerrar' => true,
                'incluir_sin_puntaje'        => true,
                'incluir_inactivos'          => false,
                'notificar_al_cerrar'        => false,
                'emails_notificacion'        => null,
                'max_cierres_a_conservar'    => 24,
                'modificado_por'             => 'sistema',
            ]
        );
    }

    /**
     * Devuelve los emails de notificación como array.
     */
    public function getEmailsArrayAttribute(): array
    {
        if (!$this->emails_notificacion) return [];
        return array_filter(
            array_map('trim', explode(',', $this->emails_notificacion))
        );
    }
}
