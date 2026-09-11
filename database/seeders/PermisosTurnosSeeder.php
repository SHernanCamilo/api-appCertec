<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Permiso;
use App\Models\Modulo;

class PermisosTurnosSeeder extends Seeder
{
    /**
     * Seed de permisos para el módulo de Cuadro de Turnos
     */
    public function run(): void
    {
        echo "🔐 Creando permisos para el módulo de Cuadro de Turnos...\n\n";

        // Obtener el módulo raíz de Turnos
        $moduloTurnos = Modulo::where('codigo', 'TUR')->first();

        if (!$moduloTurnos) {
            echo "❌ Error: No se encontró el módulo 'TUR'\n";
            echo "   Ejecuta primero: php artisan db:seed --class=TurnosModuleSeeder\n";
            return;
        }

        echo "📦 Módulo encontrado: {$moduloTurnos->nombre} (ID: {$moduloTurnos->id})\n\n";

        // Obtener submódulos
        $moduloCuadro = Modulo::where('codigo', 'TUR_CUA')->first();
        $moduloConfig = Modulo::where('codigo', 'TUR_CFG')->first();
        $moduloGrupos = Modulo::where('codigo', 'TUR_GRP')->first();
        $moduloNovedades = Modulo::where('codigo', 'TUR_NOV')->first();

        $permisos = [
            // ═══════════════════════════════════════════════════════
            // CUADRO DE TURNOS (Asignación de turnos)
            // ═══════════════════════════════════════════════════════
            [
                'id_modulo' => $moduloCuadro->id ?? $moduloTurnos->id,
                'nombre' => 'Ver Cuadro de Turnos',
                'codigo' => 'talhum-turnos-ver',
                'descripcion' => 'Permite visualizar el cuadro de turnos',
                'tipo' => 'accion',
                'icono' => 'eye',
                'orden' => 1,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloCuadro->id ?? $moduloTurnos->id,
                'nombre' => 'Crear Asignación de Turno',
                'codigo' => 'talhum-turnos-crear',
                'descripcion' => 'Permite asignar turnos a empleados',
                'tipo' => 'boton',
                'icono' => 'plus-circle',
                'orden' => 2,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloCuadro->id ?? $moduloTurnos->id,
                'nombre' => 'Editar Asignación de Turno',
                'codigo' => 'talhum-turnos-editar',
                'descripcion' => 'Permite modificar turnos asignados',
                'tipo' => 'boton',
                'icono' => 'pencil',
                'orden' => 3,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloCuadro->id ?? $moduloTurnos->id,
                'nombre' => 'Eliminar Asignación de Turno',
                'codigo' => 'talhum-turnos-eliminar',
                'descripcion' => 'Permite eliminar turnos asignados',
                'tipo' => 'boton',
                'icono' => 'trash',
                'orden' => 4,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloCuadro->id ?? $moduloTurnos->id,
                'nombre' => 'Publicar Cuadro',
                'codigo' => 'talhum-turnos-publicar',
                'descripcion' => 'Permite publicar el cuadro de turnos',
                'tipo' => 'boton',
                'icono' => 'send',
                'orden' => 5,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloCuadro->id ?? $moduloTurnos->id,
                'nombre' => 'Cerrar Cuadro',
                'codigo' => 'talhum-turnos-cerrar',
                'descripcion' => 'Permite cerrar/bloquear el cuadro de turnos',
                'tipo' => 'boton',
                'icono' => 'lock',
                'orden' => 6,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloCuadro->id ?? $moduloTurnos->id,
                'nombre' => 'Exportar Cuadro',
                'codigo' => 'talhum-turnos-exportar',
                'descripcion' => 'Permite descargar/exportar el cuadro a Excel',
                'tipo' => 'boton',
                'icono' => 'download',
                'orden' => 7,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloCuadro->id ?? $moduloTurnos->id,
                'nombre' => 'Carga Masiva de Turnos',
                'codigo' => 'talhum-turnos-carga-masiva',
                'descripcion' => 'Permite importar turnos desde Excel',
                'tipo' => 'boton',
                'icono' => 'upload',
                'orden' => 8,
                'estado' => true
            ],

            // ═══════════════════════════════════════════════════════
            // CONFIGURACIÓN (Jornada + Conceptos + Cierre)
            // ═══════════════════════════════════════════════════════
            [
                'id_modulo' => $moduloConfig->id ?? $moduloTurnos->id,
                'nombre' => 'Ver Configuración de Turnos',
                'codigo' => 'talhum-turnos-config-ver',
                'descripcion' => 'Permite ver la configuración de jornada y conceptos',
                'tipo' => 'accion',
                'icono' => 'eye',
                'orden' => 10,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloConfig->id ?? $moduloTurnos->id,
                'nombre' => 'Editar Configuración de Turnos',
                'codigo' => 'talhum-turnos-config-editar',
                'descripcion' => 'Permite modificar parámetros de jornada y conceptos',
                'tipo' => 'boton',
                'icono' => 'pencil',
                'orden' => 11,
                'estado' => true
            ],

            // ═══════════════════════════════════════════════════════
            // PLANTILLAS
            // ═══════════════════════════════════════════════════════
            [
                'id_modulo' => $moduloConfig->id ?? $moduloTurnos->id,
                'nombre' => 'Ver Plantillas de Turnos',
                'codigo' => 'talhum-turnos-plantillas-ver',
                'descripcion' => 'Permite visualizar plantillas de turnos',
                'tipo' => 'accion',
                'icono' => 'eye',
                'orden' => 20,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloConfig->id ?? $moduloTurnos->id,
                'nombre' => 'Crear Plantilla de Turno',
                'codigo' => 'talhum-turnos-plantillas-crear',
                'descripcion' => 'Permite crear nuevas plantillas de turnos',
                'tipo' => 'boton',
                'icono' => 'plus-circle',
                'orden' => 21,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloConfig->id ?? $moduloTurnos->id,
                'nombre' => 'Editar Plantilla de Turno',
                'codigo' => 'talhum-turnos-plantillas-editar',
                'descripcion' => 'Permite editar plantillas existentes',
                'tipo' => 'boton',
                'icono' => 'pencil',
                'orden' => 22,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloConfig->id ?? $moduloTurnos->id,
                'nombre' => 'Eliminar Plantilla de Turno',
                'codigo' => 'talhum-turnos-plantillas-eliminar',
                'descripcion' => 'Permite eliminar plantillas de turnos',
                'tipo' => 'boton',
                'icono' => 'trash',
                'orden' => 23,
                'estado' => true
            ],

            // ═══════════════════════════════════════════════════════
            // REPORTES
            // ═══════════════════════════════════════════════════════
            [
                'id_modulo' => $moduloTurnos->id,
                'nombre' => 'Ver Reportes de Turnos',
                'codigo' => 'talhum-turnos-reportes-ver',
                'descripcion' => 'Permite visualizar reportes del cuadro de turnos',
                'tipo' => 'accion',
                'icono' => 'chart-bar',
                'orden' => 30,
                'estado' => true
            ],
            [
                'id_modulo' => $moduloTurnos->id,
                'nombre' => 'Exportar Reportes',
                'codigo' => 'talhum-turnos-reportes-exportar',
                'descripcion' => 'Permite exportar reportes a Excel',
                'tipo' => 'boton',
                'icono' => 'download',
                'orden' => 31,
                'estado' => true
            ],
        ];

        // Crear permisos
        $creados = 0;
        $existentes = 0;

        foreach ($permisos as $permisoData) {
            $existe = Permiso::where('codigo', $permisoData['codigo'])->first();

            if ($existe) {
                echo "  ⚠️  Ya existe: {$permisoData['nombre']} ({$permisoData['codigo']})\n";
                $existentes++;
            } else {
                Permiso::create($permisoData);
                echo "  ✅ Creado: {$permisoData['nombre']} ({$permisoData['codigo']})\n";
                $creados++;
            }
        }

        echo "\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "📊 RESUMEN:\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "  ✅ Permisos creados:    {$creados}\n";
        echo "  ⚠️  Permisos existentes: {$existentes}\n";
        echo "  📦 Total procesados:    " . ($creados + $existentes) . "\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        echo "✅ Permisos de Cuadro de Turnos creados!\n";
        echo "💡 Ahora asigna estos permisos a los perfiles desde la UI de administración.\n";
    }
}
