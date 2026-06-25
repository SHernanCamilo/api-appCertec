<?php

namespace App\Models\Workflow;

use App\Models\Cargo;
use App\Models\Empresa;
use App\Models\Config\ConfigUnidadFuncional;
use Illuminate\Database\Eloquent\Model;

/**
 * Grupo de Aprobación / Grupo de UFs.
 *
 * Agrupa cargos por tipo (Asistencial, Administrativo, Directivo)
 * y/o unidades funcionales para centralizar flujos de aprobación.
 */
class WfGrupo extends Model
{
    protected $table = 'wf_grupos';

    protected $fillable = [
        'codigo',
        'nombre',
        'descripcion',
        'id_empresa',
        'estado',
    ];

    protected $casts = [
        'estado' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function cargos()
    {
        return $this->belongsToMany(Cargo::class, 'wf_grupo_cargos', 'id_grupo', 'id_cargo', 'id', 'id_cargo');
    }

    /**
     * Unidades funcionales vinculadas al grupo (pivot wf_grupo_unidades).
     */
    public function unidadesFuncionales()
    {
        return $this->belongsToMany(ConfigUnidadFuncional::class, 'wf_grupo_unidades', 'id_grupo', 'id_unidad_funcional');
    }

    public function scopeActivos($query)
    {
        return $query->where('estado', true);
    }

    public function scopePorCodigo($query, string $codigo)
    {
        return $query->where('codigo', $codigo);
    }

    /**
     * Obtiene el grupo al que pertenece un cargo.
     */
    public static function obtenerGrupoPorCargo(int $idCargo, ?int $idEmpresa = null): ?self
    {
        $query = static::activos()
            ->whereHas('cargos', fn($q) => $q->where('config_cargo.id_cargo', $idCargo));

        if ($idEmpresa) {
            $query->where(function ($q) use ($idEmpresa) {
                $q->where('id_empresa', $idEmpresa)->orWhereNull('id_empresa');
            });
        }

        return $query->first();
    }

    /**
     * Obtiene el grupo al que pertenece una unidad funcional.
     */
    public static function obtenerGrupoPorUnidadFuncional(int $idUnidadFuncional, ?int $idEmpresa = null): ?self
    {
        $query = static::activos()
            ->whereHas('unidadesFuncionales', fn($q) => $q->where('config_unidades_funcionales.id', $idUnidadFuncional));

        if ($idEmpresa) {
            $query->where(function ($q) use ($idEmpresa) {
                $q->where('id_empresa', $idEmpresa)->orWhereNull('id_empresa');
            });
        }

        return $query->first();
    }
}
