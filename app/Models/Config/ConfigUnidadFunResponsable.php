<?php

namespace App\Models\Config;

use App\Models\Empleado;
use Illuminate\Database\Eloquent\Model;

class ConfigUnidadFunResponsable extends Model
{
    protected $table = 'config_unidades_fun_responsable';

    protected $fillable = [
        'id_unidad_funcional',
        'id_user',
    ];

    protected $casts = [
        'id_unidad_funcional' => 'integer',
        'id_user' => 'integer',
    ];

    public function unidadFuncional()
    {
        return $this->belongsTo(ConfigUnidadFuncional::class, 'id_unidad_funcional');
    }

    public function persona()
    {
        return $this->belongsTo(Empleado::class, 'id_user');
    }
}
