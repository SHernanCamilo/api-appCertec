<?php

declare(strict_types=1);

namespace App\Models\MesaServicio;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GlpiParamPlantillaCategoria extends Model
{
    public const NIVEL_MAXIMO = 4;

    protected $table = 'glpi_param_plantilla_categorias';

    protected $fillable = [
        'plantilla_id',
        'parent_id',
        'nombre',
        'nivel',
        'categoria',
        'subcategoria',
        'prioridad',
        'ans_nombre',
        'ruta_completa',
        'glpi_itilcategories_id',
    ];

    protected $casts = [
        'plantilla_id' => 'integer',
        'parent_id' => 'integer',
        'nivel' => 'integer',
        'glpi_itilcategories_id' => 'integer',
    ];

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(GlpiParamPlantilla::class, 'plantilla_id');
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function hijas(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public static function armarRuta(array $segmentos): string
    {
        return collect($segmentos)
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->implode(' > ');
    }
}
