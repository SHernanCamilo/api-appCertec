<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ModuloEmpresa extends Model
{
    use HasFactory;

    protected $table = 'seg_modulo_empresa';

    protected $fillable = [
        'id_modulo',
        'id_empresa',
        'activo',
        'hereda_hijos'
    ];

    protected $casts = [
        'activo' => 'boolean',
        'hereda_hijos' => 'boolean'
    ];

    // Relación: Módulo
    public function modulo()
    {
        return $this->belongsTo(Modulo::class, 'id_modulo');
    }

    // Relación: Empresa
    public function empresa()
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    // Método estático: Asignar módulo a empresa (con sus hijos si aplica)
    public static function asignarModuloAEmpresa($idModulo, $idEmpresa, $heredaHijos = true)
    {
        $modulo = Modulo::find($idModulo);
        
        if (!$modulo) {
            return false;
        }

        // Crear o actualizar el registro principal
        $moduloEmpresa = self::updateOrCreate(
            [
                'id_modulo' => $idModulo,
                'id_empresa' => $idEmpresa
            ],
            [
                'activo' => 1,
                'hereda_hijos' => $heredaHijos
            ]
        );

        return $moduloEmpresa;
    }

    // Método estático: Obtener todos los módulos accesibles para una empresa
    public static function obtenerModulosEmpresa($idEmpresa, $soloRaiz = false)
    {
        $query = Modulo::activos();

        if ($soloRaiz) {
            $query->raiz();
        }

        return $query->get()->filter(function ($modulo) use ($idEmpresa) {
            return $modulo->empresaTieneAcceso($idEmpresa);
        });
    }
}
