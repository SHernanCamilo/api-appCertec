<?php

namespace App\Models\Finance;

use Illuminate\Database\Eloquent\Model;
use App\Models\Empresa;

class AntiUnidadFuncional extends Model
{
    protected $table = 'anti_unidades_funcionales';

    protected $fillable = ['codigo', 'nombre', 'id_empresa', 'estado'];

    protected $casts = ['estado' => 'boolean'];

    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function aprobadores()
    {
        return $this->hasMany(AntiAprobador::class, 'id_unidad_funcional');
    }

    /**
     * Retorna el aprobador activo para un rol dado, opcionalmente filtrado por sede.
     * Primero busca titular de esa sede, luego titular sin sede, luego suplente.
     */
    public function getAprobador(string $rol, ?int $idSede = null): ?AntiAprobador
    {
        $query = $this->aprobadores()
            ->where('rol_aprobador', $rol)
            ->where('estado', true)
            ->orderBy('es_suplente');

        if ($idSede) {
            // Titular de la sede específica primero
            return $query->where(function ($q) use ($idSede) {
                $q->where('id_sede', $idSede)->orWhereNull('id_sede');
            })->first();
        }

        return $query->whereNull('id_sede')->first();
    }

    public function scopeActivas($query)
    {
        return $query->where('estado', true);
    }
}
