<?php
/**
 * Script para instalar la funcionalidad de Personificar Usuario
 * Ejecutar desde la raíz del proyecto Laravel: php database/scripts/install_personificar.php
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Cargar configuración de Laravel
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    echo "🎭 Instalando funcionalidad de Personificar Usuario...\n\n";

    // Verificar si el permiso ya existe
    $permisoExiste = DB::table('seg_permisos')
        ->where('codigo', 'org-personificar')
        ->exists();

    if ($permisoExiste) {
        echo "⚠️  El permiso 'org-personificar' ya existe en la base de datos.\n";
    } else {
        // Insertar el permiso
        $permisoId = DB::table('seg_permisos')->insertGetId([
            'id_modulo' => 15, // Gestión de Usuarios
            'nombre' => 'Personificar Usuario',
            'codigo' => 'org-personificar',
            'descripcion' => 'Permite actuar como otro usuario del sistema (similar a GLPI)',
            'tipo' => 'boton',
            'icono' => 'user-secret',
            'orden' => 10,
            'estado' => 1,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        echo "✅ Permiso 'org-personificar' creado exitosamente (ID: {$permisoId})\n";
    }

    // Verificar módulo de Gestión de Usuarios
    $modulo = DB::table('seg_modulos')
        ->where('id', 15)
        ->where('codigo', 'USU_ADMIN')
        ->first();

    if ($modulo) {
        echo "✅ Módulo 'Gestión de Usuarios' encontrado: {$modulo->nombre}\n";
    } else {
        echo "⚠️  Módulo 'Gestión de Usuarios' (ID: 15) no encontrado. Verifica la configuración.\n";
    }

    // Mostrar información del permiso creado
    $permiso = DB::table('seg_permisos as p')
        ->join('seg_modulos as m', 'p.id_modulo', '=', 'm.id')
        ->where('p.codigo', 'org-personificar')
        ->select('p.*', 'm.nombre as modulo_nombre')
        ->first();

    if ($permiso) {
        echo "\n📋 Información del permiso:\n";
        echo "   - ID: {$permiso->id}\n";
        echo "   - Nombre: {$permiso->nombre}\n";
        echo "   - Código: {$permiso->codigo}\n";
        echo "   - Módulo: {$permiso->modulo_nombre}\n";
        echo "   - Tipo: {$permiso->tipo}\n";
        echo "   - Estado: " . ($permiso->estado ? 'Activo' : 'Inactivo') . "\n";
    }

    echo "\n🎯 Próximos pasos:\n";
    echo "   1. Asignar el permiso a un perfil o rol\n";
    echo "   2. Integrar los componentes en el frontend\n";
    echo "   3. Agregar <app-personificar-banner> al layout principal\n";
    echo "   4. El botón de personificar ya está integrado en la gestión de usuarios\n";

    echo "\n✅ Instalación completada exitosamente!\n";

} catch (Exception $e) {
    echo "❌ Error durante la instalación: " . $e->getMessage() . "\n";
    echo "   Verifica la conexión a la base de datos y los permisos.\n";
    exit(1);
}