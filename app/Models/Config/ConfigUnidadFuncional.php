<?php

namespace App\Models\Config;

use App\Models\Empresa;
use App\Models\Empleado;
use App\Models\Sede;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ConfigUnidadFuncional extends Model
{
    protected $table = 'config_unidades_funcionales';

    protected $fillable = [
        'id_empresa',
        'id_sucursal',
        'id_sede',
        'codigo',
        'nombre',
        'estado',
    ];

    protected $casts = [
        'id_empresa' => 'integer',
        'id_sucursal' => 'integer',
        'id_sede' => 'integer',
        'estado' => 'boolean',
    ];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function sucursal()
    {
        return $this->belongsTo(Sucursal::class, 'id_sucursal');
    }

    public function sede()
    {
        return $this->belongsTo(Sede::class, 'id_sede');
    }

    public function usuarios()
    {
        return $this->belongsToMany(
            \App\Models\Empleado::class,
            'config_unidades_fun_usuarios',
            'id_unidad_funcional',
            'id_user'
        )->withTimestamps();
    }

    public function responsables()
    {
        return $this->belongsToMany(
            Empleado::class,
            'config_unidades_fun_responsable',
            'id_unidad_funcional',
            'id_user'
        )->withTimestamps();
    }

    public function grupos()
    {
        return $this->belongsToMany(\App\Models\TalentoHumano\CuadroTurnos\CtGrupo::class, 'humtal_ct_grupo_unidad_funcional', 'id_unidad_funcional', 'id_grupo');
    }

    public function flujos()
    {
        return $this->belongsToMany(\App\Models\Workflow\WfDefinicion::class, 'wf_definicion_unidad_funcional', 'id_unidad_funcional', 'id_definicion');
    }

    public function scopeActivas(Builder $query): Builder
    {
        return $query->where('estado', true);
    }

    public function scopePorEmpresa(Builder $query, int $empresaId): Builder
    {
        return $query->where('id_empresa', $empresaId);
    }

    public function scopeAccessibleByUser(Builder $query, User $user): Builder
    {
        $user->loadMissing('empresas');

        if ($user->empresas->isEmpty()) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            foreach ($user->empresas as $empresa) {
                $pivot = $empresa->pivot;
                $empresaId = $empresa->id;
                $sucursalId = $pivot->id_sucursal ?? null;
                $sedeId = $pivot->id_sede ?? null;
                $recursivo = (bool) ($pivot->recursivo ?? false);

                if ($recursivo && !$sucursalId) {
                    $q->orWhere('id_empresa', $empresaId);
                } elseif ($recursivo && $sucursalId) {
                    $q->orWhere(function (Builder $sq) use ($empresaId, $sucursalId) {
                        $sq->where('id_empresa', $empresaId)
                            ->where('id_sucursal', $sucursalId);
                    });
                } else {
                    $q->orWhere(function (Builder $sq) use ($empresaId, $sucursalId, $sedeId) {
                        $sq->where('id_empresa', $empresaId);
                        if ($sedeId) {
                            $sq->where('id_sede', $sedeId);
                        } elseif ($sucursalId) {
                            $sq->where('id_sucursal', $sucursalId)
                                ->whereNull('id_sede');
                        } else {
                            $sq->whereNull('id_sucursal')
                                ->whereNull('id_sede');
                        }
                    });
                }
            }
        });
    }
}
