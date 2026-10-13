<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deja definido el módulo ORG-SERV-TAREA y el permiso org-serv-tarea-ver
 * que exige la API de tareas programadas. No pisa un módulo que ya exista.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seg_modulos') || ! Schema::hasTable('seg_permisos')) {
            return;
        }

        $ahora = now();
        $modulo = DB::table('seg_modulos')->where('codigo', 'ORG-SERV-TAREA')->first();

        if ($modulo === null) {
            $padre = DB::table('seg_modulos')->where('codigo', 'ORG')->first();
            DB::table('seg_modulos')->insert([
                'id_modulo_padre' => $padre->id ?? null,
                'nombre' => 'Programador de tareas',
                'codigo' => 'ORG-SERV-TAREA',
                'descripcion' => 'Programar sincronización, cierres y mantenimiento',
                'icono' => 'bi bi-clock-history',
                'ruta' => '/organizacion/servicios/task-scheduler',
                'orden' => 10,
                'nivel' => $padre ? ((int) $padre->nivel + 1) : 1,
                'estado' => 1,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
            $modulo = DB::table('seg_modulos')->where('codigo', 'ORG-SERV-TAREA')->first();
        }

        $permisosPrevios = DB::table('seg_permisos')
            ->where('id_modulo', $modulo->id)
            ->pluck('id');

        $definiciones = [
            [
                'codigo' => 'org-serv-tarea-visible',
                'nombre' => 'Visible Programador de tareas',
                'descripcion' => 'Permite ver el programador de tareas en el menú',
                'tipo' => 'menu',
                'orden' => 0,
            ],
            [
                'codigo' => 'org-serv-tarea-ver',
                'nombre' => 'Ver tareas programadas',
                'descripcion' => 'Permite consultar y ejecutar las tareas programadas',
                'tipo' => 'accion',
                'orden' => 1,
            ],
        ];

        foreach ($definiciones as $def) {
            DB::table('seg_permisos')->updateOrInsert(
                ['codigo' => $def['codigo']],
                [
                    'id_modulo' => $modulo->id,
                    'nombre' => $def['nombre'],
                    'descripcion' => $def['descripcion'],
                    'tipo' => $def['tipo'],
                    'orden' => $def['orden'],
                    'estado' => 1,
                    'updated_at' => $ahora,
                    'created_at' => $ahora,
                ]
            );
        }

        if (Schema::hasTable('seg_perfiles') && Schema::hasTable('seg_perfil_permiso')) {
            $ver = DB::table('seg_permisos')->where('codigo', 'org-serv-tarea-ver')->first();
            $perfiles = DB::table('seg_perfiles')
                ->where('id_modulo', $modulo->id)
                ->where('puede_leer', 1)
                ->pluck('id');

            if ($permisosPrevios->isNotEmpty()) {
                $conMenu = DB::table('seg_perfil_permiso')
                    ->whereIn('id_permiso', $permisosPrevios)
                    ->pluck('id_perfil');
                $perfiles = $perfiles->merge($conMenu)->unique()->values();
            }

            foreach ($perfiles as $idPerfil) {
                $existe = DB::table('seg_perfil_permiso')
                    ->where('id_perfil', $idPerfil)
                    ->where('id_permiso', $ver->id)
                    ->exists();
                if ($existe) {
                    continue;
                }
                DB::table('seg_perfil_permiso')->insert([
                    'id_perfil' => $idPerfil,
                    'id_permiso' => $ver->id,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        }

        if (Schema::hasTable('seg_modulo_empresa') && Schema::hasTable('ent_empresas')) {
            $empresas = DB::table('ent_empresas')->pluck('id');
            foreach ($empresas as $idEmpresa) {
                DB::table('seg_modulo_empresa')->updateOrInsert(
                    ['id_modulo' => $modulo->id, 'id_empresa' => $idEmpresa],
                    [
                        'activo' => 1,
                        'hereda_hijos' => 0,
                        'updated_at' => $ahora,
                        'created_at' => $ahora,
                    ]
                );
            }
        }

        Cache::increment('sidebar_cache_version');
    }

    public function down(): void
    {
        if (! Schema::hasTable('seg_permisos')) {
            return;
        }

        $ids = DB::table('seg_permisos')
            ->whereIn('codigo', ['org-serv-tarea-visible', 'org-serv-tarea-ver'])
            ->pluck('id');

        if ($ids->isNotEmpty() && Schema::hasTable('seg_perfil_permiso')) {
            DB::table('seg_perfil_permiso')->whereIn('id_permiso', $ids)->delete();
        }

        DB::table('seg_permisos')
            ->whereIn('codigo', ['org-serv-tarea-visible', 'org-serv-tarea-ver'])
            ->delete();
    }
};
