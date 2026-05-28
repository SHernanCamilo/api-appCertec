<?php

namespace App\Models\Config;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ConfigUnidadFunUsuario extends Model
{
    protected $table = 'config_unidades_fun_usuarios';

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

    public function usuario()
    {
        return $this->belongsTo(User::class, 'id_user');
    }
}
