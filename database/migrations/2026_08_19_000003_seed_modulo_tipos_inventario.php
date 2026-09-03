<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inserta el módulo "Tipos de Inventario" en seg_modulos como hijo del módulo
 * "Control Activo" (INV-ACTIVOS-CTRL) dentro del grupo de Activos Fijos.
 *
 * Si el módulo padre no existe aún, lo crea también para garantizar integridad.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Buscar el módulo padre de Activos Fijos (INV-ACTIVOS-CTRL)
        $padreCtrl = DB::table('seg_modulos')
            ->where('codigo', 'INV-ACTIVOS-CTRL')
            ->first();

        if (! $padreCtrl) {
            // Si el módulo de Control Activo no existe, intentar con el grupo padre
            $padreGrupo = DB::table('seg_modulos')
                ->where('codigo', 'INV-ACTIVOS')
                ->first();

            if (! $padreGrupo) {
                // Buscar el módulo raíz de inventario
                $raizInventario = DB::table('seg_modulos')
                    ->where('codigo', 'INV')
                    ->orWhere('ruta', '/inventario')
                    ->first();

                $idPadreGrupo = $raizInventario?->id;

                // Crear grupo Activos Fijos si no existe
                $idPadreGrupo = DB::table('seg_modulos')->insertGetId([
                    'id_modulo_padre' => $idPadreGrupo,
                    'nombre'          => 'Activos Fijos',
                    'codigo'          => 'INV-ACTIVOS',
                    'descripcion'     => 'Control e inventario de activos fijos',
                    'icono'           => 'tags',
                    'ruta'            => '/inventario/activosFijos',
                    'orden'           => 90,
                    'nivel'           => 1,
                    'estado'          => 1,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            } else {
                $idPadreGrupo = $padreGrupo->id;
            }

            // Crear módulo Control Activo
            DB::table('seg_modulos')->insertGetId([
                'id_modulo_padre' => $idPadreGrupo,
                'nombre'          => 'Control de Activos',
                'codigo'          => 'INV-ACTIVOS-CTRL',
                'descripcion'     => 'Toma de inventario y trazabilidad de activos fijos',
                'icono'           => 'clipboard-check',
                'ruta'            => '/inventario/activosFijos/controlActivo',
                'orden'           => 1,
                'nivel'           => 2,
                'estado'          => 1,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            // Recargar el padre para obtener su ID
            $padreCtrl = DB::table('seg_modulos')
                ->where('codigo', 'INV-ACTIVOS-CTRL')
                ->first();
        }

        // Verificar que el módulo de Tipos de Inventario no exista ya
        $existe = DB::table('seg_modulos')
            ->where('codigo', 'INV-ACTIVOS-TIPOS')
            ->exists();

        if (! $existe) {
            // Usar el mismo padre que Control Activo (hermanos en el mismo nivel)
            $idPadre = $padreCtrl?->id_modulo_padre ?? $padreCtrl?->id;

            DB::table('seg_modulos')->insert([
                'id_modulo_padre' => $idPadre,
                'nombre'          => 'Tipos de Inventario',
                'codigo'          => 'INV-ACTIVOS-TIPOS',
                'descripcion'     => 'Parametrización de tipos de inventario y periodicidad',
                'icono'           => 'sliders',
                'ruta'            => '/inventario/activosFijos/tiposInventario',
                'orden'           => 2,
                'nivel'           => $padreCtrl?->nivel ?? 2,
                'estado'          => 1,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('seg_modulos')
            ->where('codigo', 'INV-ACTIVOS-TIPOS')
            ->delete();
    }
};
