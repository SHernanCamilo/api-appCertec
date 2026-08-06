<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nueva estrategia de aprobador: ROL_SPATIE.
 *
 * El motor de flujos resolvía aprobadores por `seg_permisos.codigo` (cadena
 * Rol → Perfil → Permiso del sistema de seguridad propio). Fichas Técnicas usa
 * roles Spatie (`roles` / `model_has_roles`), por lo que se agrega una sexta
 * estrategia que lee el nombre del rol desde `wf_aprobadores.rol_spatie`.
 *
 * Es un cambio aditivo: los flujos de anticipos y eventos no se ven afectados
 * porque conservan su `tipo_aprobador` actual.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wf_aprobadores')) {
            return;
        }

        // El enum se amplía con SQL directo: `change()` sobre enum requiere DBAL.
        DB::statement("
            ALTER TABLE wf_aprobadores
            MODIFY COLUMN tipo_aprobador
            ENUM('USER', 'RESPONSABLE_UF', 'RESPONSABLE_GRUPO', 'GRUPO', 'PERMISO', 'ROL_SPATIE')
            NOT NULL DEFAULT 'USER'
        ");

        if (! Schema::hasColumn('wf_aprobadores', 'rol_spatie')) {
            Schema::table('wf_aprobadores', function (Blueprint $table): void {
                $table->string('rol_spatie', 100)
                    ->nullable()
                    ->after('permiso_codigo')
                    ->comment('Nombre del rol Spatie (roles.name) cuando tipo_aprobador = ROL_SPATIE');

                $table->index('rol_spatie');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('wf_aprobadores', 'rol_spatie')) {
            Schema::table('wf_aprobadores', function (Blueprint $table): void {
                $table->dropIndex(['rol_spatie']);
                $table->dropColumn('rol_spatie');
            });
        }

        DB::statement("
            ALTER TABLE wf_aprobadores
            MODIFY COLUMN tipo_aprobador
            ENUM('USER', 'RESPONSABLE_UF', 'RESPONSABLE_GRUPO')
            NOT NULL DEFAULT 'USER'
        ");
    }
};
