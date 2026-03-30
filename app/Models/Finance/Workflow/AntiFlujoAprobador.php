<?php

namespace App\Models\Finance\Workflow;

use App\Models\User;
use App\Models\Sede;
use App\Models\Finance\AntiUnidadFuncional;
use Illuminate\Database\Eloquent\Model;

class AntiFlujoAprobador extends Model
{
    protected $table = 'anti_flujo_aprobadores';

    protected $fillable = [
        'id_flujo_paso',
        'id_user',
        'id_unidad_funcional',
        'prefijo_sucursal',
        'id_sede',
        'es_suplente',
        'estado',
    ];

    protected $casts = [
        'es_suplente' => 'boolean',
        'estado'      => 'boolean',
    ];

    public function flujoPaso()
    {
        return $this->belongsTo(AntiFlujoPaso::class, 'id_flujo_paso');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'id_user');
    }

    public function unidadFuncional()
    {
        return $this->belongsTo(AntiUnidadFuncional::class, 'id_unidad_funcional');
    }

    public function sede()
    {
        return $this->belongsTo(Sede::class, 'id_sede');
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }

    public function scopeTitulares($query)
    {
        return $query->where('es_suplente', false);
    }
}
