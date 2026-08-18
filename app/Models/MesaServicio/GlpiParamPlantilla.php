<?php

declare(strict_types=1);

namespace App\Models\MesaServicio;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GlpiParamPlantilla extends Model
{
    public const PRIORIDADES = ['baja', 'media', 'alta', 'muy_alta'];

    public const PRIORIDAD_LABELS = [
        'baja' => 'BAJA',
        'media' => 'MEDIA',
        'alta' => 'ALTA',
        'muy_alta' => 'MUY ALTA',
    ];

    public const UNIDADES = ['minuto', 'hora', 'dia'];

    protected $table = 'glpi_param_plantillas';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'id_empresa',
        'nombre_entidad',
        'grupo_tecnico',
        'sla_asignacion',
        'prefijo_regla',
        'estado',
        'created_by',
    ];

    protected $casts = [
        'estado' => 'boolean',
        'id_empresa' => 'integer',
        'created_by' => 'integer',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function ans(): HasMany
    {
        return $this->hasMany(GlpiParamPlantillaAns::class, 'plantilla_id');
    }

    public function categorias(): HasMany
    {
        return $this->hasMany(GlpiParamPlantillaCategoria::class, 'plantilla_id');
    }

    public function scopeActivas($query)
    {
        return $query->where('estado', true);
    }

    public static function nombreRegla(string $prioridad, string $prefijo): string
    {
        $label = self::PRIORIDAD_LABELS[$prioridad] ?? strtoupper($prioridad);
        $prefijo = trim($prefijo);

        return $prefijo === '' ? $label : "{$label} {$prefijo}";
    }
}
