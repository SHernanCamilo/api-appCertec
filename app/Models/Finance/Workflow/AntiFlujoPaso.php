<?php

namespace App\Models\Finance\Workflow;

use Illuminate\Database\Eloquent\Model;

class AntiFlujoPaso extends Model
{
    protected $table = 'anti_flujo_pasos';

    protected $fillable = ['id_flujo', 'orden', 'nombre_paso', 'rol_aprobador', 'es_opcional', 'permite_rechazo', 'estado'];

    protected $casts = ['es_opcional' => 'boolean', 'permite_rechazo' => 'boolean', 'estado' => 'boolean', 'orden' => 'integer'];

    public function flujo()
    {
        return $this->belongsTo(AntiFlujo::class, 'id_flujo');
    }

    public function aprobadores()
    {
        return $this->hasMany(AntiFluj oAprobador::class, 'id_flujo_paso');
    }
}
