<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Modulo;
use App\Models\ModuloEmpresa;
use App\Models\Empresa;
use App\Models\Perfil;

class TurnosModuleSeeder extends Seeder
{
    public function run(): void
    {
        // 1. MÓDULO RAÍZ: TURNOS
        $turnos = Modulo::create([
            'id_modulo_padre' => null,
            'nombre' => 'Cuadro de Turnos',
            'codigo' => 'TUR',
            'descripcion' => 'Gestión de horarios y turnos de personal',
            'icono' => 'bi bi-calendar-week',
            'ruta' => '/turnos',
            'orden' => 5,
            'nivel' => 0,
            'estado' => 1
        ]);

        // 1.1 HIJO: GRUPOS
        $grupos = Modulo::create([
            'id_modulo_padre' => $turnos->id,
            'nombre' => 'Grupos de Trabajo',
            'codigo' => 'TUR_GRP',
            'descripcion' => 'Gestión de grupos y sus integrantes',
            'icono' => 'bi bi-people',
            'ruta' => '/turnos/grupos',
            'orden' => 1,
            'nivel' => 1,
            'estado' => 1
        ]);

        // 1.2 HIJO: CUADROS
        $cuadros = Modulo::create([
            'id_modulo_padre' => $turnos->id,
            'nombre' => 'Planificación (Cuadros)',
            'codigo' => 'TUR_CUA',
            'descripcion' => 'Programación mensual de turnos',
            'icono' => 'bi bi-grid-3x3',
            'ruta' => '/turnos/cuadros',
            'orden' => 2,
            'nivel' => 1,
            'estado' => 1
        ]);

        // 1.3 HIJO: NOVEDADES
        $novedades = Modulo::create([
            'id_modulo_padre' => $turnos->id,
            'nombre' => 'Novedades',
            'codigo' => 'TUR_NOV',
            'descripcion' => 'Gestión de cambios y ausentismos',
            'icono' => 'bi bi-exclamation-circle',
            'ruta' => '/turnos/novedades',
            'orden' => 3,
            'nivel' => 1,
            'estado' => 1
        ]);

        // 1.4 HIJO: CONFIGURACIÓN (PLANTILLAS)
        $config = Modulo::create([
            'id_modulo_padre' => $turnos->id,
            'nombre' => 'Configuración',
            'codigo' => 'TUR_CFG',
            'descripcion' => 'Plantillas de turnos y tipos de novedad',
            'icono' => 'bi bi-gear',
            'ruta' => '/turnos/configuracion',
            'orden' => 4,
            'nivel' => 1,
            'estado' => 1
        ]);

        // 2. CREAR PERFILES PARA EL MÓDULO
        Perfil::create([
            'nombre' => 'Administrador de Turnos',
            'codigo' => 'tur-admin',
            'id_modulo' => $turnos->id,
            'descripcion' => 'Acceso total al módulo de turnos',
            'puede_crear' => true,
            'puede_leer' => true,
            'puede_editar' => true,
            'puede_eliminar' => true,
            'estado' => true
        ]);

        Perfil::create([
            'nombre' => 'Planificador de Turnos',
            'codigo' => 'tur-planificador',
            'id_modulo' => $turnos->id,
            'descripcion' => 'Puede gestionar grupos y cuadros de turnos',
            'puede_crear' => true,
            'puede_leer' => true,
            'puede_editar' => true,
            'puede_eliminar' => false,
            'estado' => true
        ]);

        // 3. ASIGNAR MÓDULO A TODAS LAS EMPRESAS
        $empresas = Empresa::all();
        foreach ($empresas as $empresa) {
            ModuloEmpresa::asignarModuloAEmpresa($turnos->id, $empresa->id, true);
        }

        echo "✅ Módulo de Turnos registrado y asignado correctamente.\n";
    }
}
