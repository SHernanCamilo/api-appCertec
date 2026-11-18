<?php

declare(strict_types=1);

namespace App\Models\Inventory\Ropa;

use App\Models\Config\ConfigUnidadFuncional;
use App\Models\Sede;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RopaInventario extends Model
{
    protected $table = 'inv_ropa_inventarios';

    protected $fillable = [
        'consecutivo',
        'estado',
        'id_usuario',
        'id_sucursal',
        'id_sede',
        'id_unidad_funcional',
        'periodo',
        'fecha_corte',
        'comentario',
        'fecha_registro',
        'id_borrador',
    ];

    protected $casts = [
        'consecutivo' => 'integer',
        'estado' => 'integer',
        'id_usuario' => 'integer',
        'id_sucursal' => 'integer',
        'id_sede' => 'integer',
        'id_unidad_funcional' => 'integer',
        'fecha_corte' => 'date',
        'fecha_registro' => 'datetime',
        'id_borrador' => 'integer',
    ];

    public function lineas(): HasMany
    {
        return $this->hasMany(RopaInventarioLinea::class, 'id_inventario');
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_usuario');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'id_sucursal');
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'id_sede');
    }

    public function unidad(): BelongsTo
    {
        return $this->belongsTo(ConfigUnidadFuncional::class, 'id_unidad_funcional');
    }

    public function borrador(): BelongsTo
    {
        return $this->belongsTo(self::class, 'id_borrador');
    }

    public function estaCerrado(): bool
    {
        return (int) $this->estado === 1;
    }
}
