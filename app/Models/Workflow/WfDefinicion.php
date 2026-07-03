<?php

namespace App\Models\Workflow;

use App\Models\Empresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Definición de un flujo de aprobación.
 * Un flujo es una secuencia ordenada de pasos.
 */
class WfDefinicion extends Model
{
    protected $table = 'wf_definiciones';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'id_modulo',
        'id_empresa',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    public function modulo()
    {
        return $this->belongsTo(WfModulo::class, 'id_modulo');
    }

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function pasos()
    {
        return $this->hasMany(WfPaso::class, 'id_definicion')->orderBy('orden');
    }

    public function reglas()
    {
        return $this->hasMany(WfRegla::class, 'id_definicion')->orderBy('prioridad');
    }

    public function instancias()
    {
        return $this->hasMany(WfInstancia::class, 'id_definicion');
    }

    public function unidadesFuncionales(): BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Config\ConfigUnidadFuncional::class, 'wf_definicion_unidad_funcional', 'id_definicion', 'id_unidad_funcional');
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }

    public function scopePorModulo($query, int $idModulo)
    {
        return $query->where('id_modulo', $idModulo);
    }

    public function scopePorEmpresa($query, ?int $idEmpresa)
    {
        return $query->where(function ($q) use ($idEmpresa) {
            $q->where('id_empresa', $idEmpresa)
              ->orWhereNull('id_empresa');
        });
    }
}
