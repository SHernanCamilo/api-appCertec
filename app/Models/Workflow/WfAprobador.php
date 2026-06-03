<?php

namespace App\Models\Workflow;

use App\Models\User;
use App\Models\Sede;
use App\Models\Finance\AntiUnidadFuncional;
use Illuminate\Database\Eloquent\Model;

/**
 * Aprobadores parametrizables por paso.
 * Soporta 4 estrategias:
 *   1. Aprobador fijo (id_user)
 *   2. Aprobador por unidad funcional (dinámico)
 *   3. Aprobador por prefijo de sucursal (dinámico)
 *   4. Aprobador por grupo (dinámico)
 */
class WfAprobador extends Model
{
    protected $table = 'wf_aprobadores';

    protected $fillable = [
        'id_paso',
        'id_user',
        'id_unidad_funcional',
        'prefijo_sucursal',
        'id_sede',
        'id_grupo',
        'es_suplente',
        'estado',
    ];

    protected $casts = [
        'es_suplente' => 'boolean',
        'estado' => 'boolean',
    ];

    public function paso()
    {
        return $this->belongsTo(WfPaso::class, 'id_paso');
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

    public function grupo()
    {
        return $this->belongsTo(WfGrupo::class, 'id_grupo');
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }

    public function scopeTitulares($query)
    {
        return $query->where('es_suplente', false);
    }

    public function scopeSuplentes($query)
    {
        return $query->where('es_suplente', true);
    }

    public function scopePorSede($query, ?int $idSede)
    {
        return $query->where(function ($q) use ($idSede) {
            $q->where('id_sede', $idSede)
              ->orWhereNull('id_sede');
        });
    }
}