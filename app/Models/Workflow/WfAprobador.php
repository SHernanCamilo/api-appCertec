<?php

namespace App\Models\Workflow;

use App\Models\User;
use App\Models\Sede;
use App\Models\Finance\AntiUnidadFuncional;
use Illuminate\Database\Eloquent\Model;

/**
 * Aprobadores parametrizables por paso.
 * Soporta 5 estrategias:
 *   1. Aprobador fijo (id_user)
 *   2. Aprobador por unidad funcional (dinámico)
 *   3. Aprobador por prefijo de sucursal (dinámico)
 *   4. Aprobador por grupo (dinámico)
 *   5. Aprobador por permiso (permiso_codigo -> seg_permisos.codigo)
 */
class WfAprobador extends Model
{
    protected $table = 'wf_aprobadores';

    protected $fillable = [
        'id_paso',
        'id_user',
        'tipo_aprobador',
        'id_unidad_funcional',
        'prefijo_sucursal',
        'id_sede',
        'id_grupo',
        'permiso_codigo',
        'alcance',
        'es_suplente',
        'condiciones',
        'estado',
    ];

    protected $casts = [
        'es_suplente' => 'boolean',
        'condiciones' => 'array',
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

    /**
     * Evalúa si las condiciones dinámicas (JSON) de este aprobador se cumplen para el contexto del evento.
     */
    public function evaluarCondiciones(array $contexto): bool
    {
        if (empty($this->condiciones)) {
            return true;
        }

        foreach ($this->condiciones as $campo => $valor) {
            // Si el contexto no tiene el campo, o su valor es diferente, no aplica
            if (!array_key_exists($campo, $contexto) || $contexto[$campo] != $valor) {
                return false;
            }
        }
        
        return true;
    }
}