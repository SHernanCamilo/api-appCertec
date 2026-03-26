<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\Sede;

class AntiAprobador extends Model
{
    protected $table = 'anti_aprobadores';

    const ROL_JEFE_INMEDIATO = 'jefe_inmediato';
    const ROL_FINANCIERO     = 'financiero';
    const ROL_TESORERIA      = 'tesoreria';
    const ROL_CONTABILIDAD   = 'contabilidad';

    protected $fillable = [
        'id_unidad_funcional',
        'user_id',
        'rol_aprobador',
        'id_sede',
        'es_suplente',
        'estado',
    ];

    protected $casts = [
        'es_suplente' => 'boolean',
        'estado'      => 'boolean',
    ];

    public function unidadFuncional()
    {
        return $this->belongsTo(AntiUnidadFuncional::class, 'id_unidad_funcional');
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sede()
    {
        return $this->belongsTo(Sede::class, 'id_sede');
    }
}
