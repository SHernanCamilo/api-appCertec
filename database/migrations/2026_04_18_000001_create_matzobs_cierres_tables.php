<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración: Tablas de Cierre de Inventario - Matriz de Obsolescencia
 *
 * Crea tres tablas:
 *   matzobs_cierres          → cabecera del cierre (una fila por cierre ejecutado)
 *   matzobs_cierre_detalle   → snapshot de cada activo en el momento del cierre
 *   matzobs_cierre_config    → parámetros que controlan el comportamiento del cierre
 */
return new class extends Migration
{
    // ─────────────────────────────────────────────────────────────────────────
    // UP
    // ─────────────────────────────────────────────────────────────────────────
    public function up(): void
    {
        // ── 1. Configuración global del cierre ────────────────────────────────
        // Una sola fila (id=1) que el admin puede editar desde el frontend.
        if (!Schema::hasTable('matzobs_cierre_config')) {
            Schema::create('matzobs_cierre_config', function (Blueprint $table) {
                $table->id();

                // ── Comportamiento del proceso ────────────────────────────────
                $table->boolean('recalcular_antes_de_cerrar')
                    ->default(true)
                    ->comment('Si true, recalcula puntajes antes de tomar el snapshot');

                $table->boolean('incluir_sin_puntaje')
                    ->default(true)
                    ->comment('Si true, incluye activos con puntaje = 0 o NULL');

                $table->boolean('incluir_inactivos')
                    ->default(false)
                    ->comment('Si true, incluye activos con estado = 0');

                // ── Notificaciones ────────────────────────────────────────────
                $table->boolean('notificar_al_cerrar')
                    ->default(false)
                    ->comment('Enviar notificación cuando el cierre finalice');

                $table->string('emails_notificacion', 1000)
                    ->nullable()
                    ->comment('Lista de emails separados por coma para notificar');

                // ── Retención ─────────────────────────────────────────────────
                $table->unsignedSmallInteger('max_cierres_a_conservar')
                    ->default(24)
                    ->comment('Número máximo de cierres a conservar (0 = sin límite)');

                // ── Auditoría ─────────────────────────────────────────────────
                $table->string('modificado_por', 150)->nullable();
                $table->timestamps();
            });

            // Insertar fila de configuración por defecto
            DB::table('matzobs_cierre_config')->insert([
                'recalcular_antes_de_cerrar' => true,
                'incluir_sin_puntaje'        => true,
                'incluir_inactivos'          => false,
                'notificar_al_cerrar'        => false,
                'emails_notificacion'        => null,
                'max_cierres_a_conservar'    => 24,
                'modificado_por'             => 'sistema',
                'created_at'                 => now(),
                'updated_at'                 => now(),
            ]);
        }

        // ── 2. Cabecera del cierre ─────────────────────────────────────────────
        if (!Schema::hasTable('matzobs_cierres')) {
            Schema::create('matzobs_cierres', function (Blueprint $table) {
                $table->id();

                // ── Identificación ────────────────────────────────────────────
                $table->string('nombre', 200)
                    ->comment('Nombre descriptivo, ej: "Cierre Q1 2026"');

                $table->string('periodo', 20)
                    ->nullable()
                    ->comment('Período del cierre, ej: "2026-Q1", "2026-04"');

                $table->text('descripcion')
                    ->nullable()
                    ->comment('Descripción libre del cierre');

                // ── Estado del proceso ────────────────────────────────────────
                $table->enum('estado', ['pendiente', 'procesando', 'cerrado', 'error'])
                    ->default('pendiente')
                    ->comment('pendiente=creado, procesando=job corriendo, cerrado=ok, error=falló');

                $table->timestamp('fecha_inicio_proceso')
                    ->nullable()
                    ->comment('Cuándo empezó a ejecutarse el Job');

                $table->timestamp('fecha_fin_proceso')
                    ->nullable()
                    ->comment('Cuándo terminó de ejecutarse el Job');

                $table->unsignedSmallInteger('duracion_segundos')
                    ->nullable()
                    ->comment('Duración total del proceso en segundos');

                $table->text('mensaje_error')
                    ->nullable()
                    ->comment('Detalle del error si estado = error');

                // ── Resumen estadístico (desnormalizado para consultas rápidas) ─
                $table->unsignedInteger('total_activos')
                    ->default(0)
                    ->comment('Total de activos incluidos en el cierre');

                $table->unsignedInteger('total_optimo')
                    ->default(0)
                    ->comment('Activos con puntaje >= 100');

                $table->unsignedInteger('total_funcional')
                    ->default(0)
                    ->comment('Activos con puntaje >= 60 y < 100');

                $table->unsignedInteger('total_potencial')
                    ->default(0)
                    ->comment('Activos con puntaje > 0 y < 60');

                $table->unsignedInteger('total_obsoleto')
                    ->default(0)
                    ->comment('Activos con puntaje = 0 o NULL');

                $table->decimal('puntaje_promedio', 5, 2)
                    ->default(0)
                    ->comment('Puntaje promedio de todos los activos del cierre');

                // ── Configuración usada (snapshot de config al momento del cierre) ─
                $table->boolean('config_recalculo_aplicado')
                    ->default(false)
                    ->comment('Si se recalculó antes de cerrar');

                $table->boolean('config_incluyo_sin_puntaje')
                    ->default(true);

                $table->boolean('config_incluyo_inactivos')
                    ->default(false);

                // ── Auditoría ─────────────────────────────────────────────────
                $table->unsignedBigInteger('creado_por')
                    ->nullable()
                    ->comment('ID del usuario que creó el cierre');

                $table->string('nombre_creador', 150)
                    ->nullable()
                    ->comment('Nombre del usuario (desnormalizado)');

                $table->timestamps();

                // ── Índices ───────────────────────────────────────────────────
                $table->index('estado');
                $table->index('periodo');
                $table->index('created_at');
                $table->index('creado_por');

                $table->foreign('creado_por')
                    ->references('id')
                    ->on('users')
                    ->onDelete('set null');
            });
        }

        // ── 3. Detalle del cierre (snapshot por activo) ───────────────────────
        if (!Schema::hasTable('matzobs_cierre_detalle')) {
            Schema::create('matzobs_cierre_detalle', function (Blueprint $table) {
                $table->id();

                // ── FK al cierre ──────────────────────────────────────────────
                $table->unsignedBigInteger('cierre_id')
                    ->comment('FK a matzobs_cierres');

                // ── Referencia al activo original (nullable: puede haberse eliminado) ─
                $table->unsignedBigInteger('activo_c_id')
                    ->nullable()
                    ->comment('FK a matzobs_activos_c (nullable por si se elimina el activo)');

                // ── Snapshot de matzobs_activos_c ─────────────────────────────
                $table->integer('id_activo_glpi')
                    ->nullable()
                    ->comment('ID del activo en GLPI al momento del cierre');

                $table->string('nombre_equipo', 255)
                    ->nullable();

                $table->unsignedBigInteger('id_empresa')
                    ->nullable();

                $table->string('nombre_empresa', 255)
                    ->nullable()
                    ->comment('Nombre desnormalizado para consultas históricas');

                $table->unsignedBigInteger('id_sucursal')
                    ->nullable();

                $table->string('nombre_sucursal', 255)
                    ->nullable();

                $table->unsignedBigInteger('id_sede')
                    ->nullable();

                $table->string('nombre_sede', 255)
                    ->nullable();

                $table->string('agente', 100)->nullable();
                $table->string('placa', 100)->nullable();
                $table->string('serial', 100)->nullable();
                $table->string('ubicacion', 255)->nullable();
                $table->string('usuario_glpi', 255)->nullable();

                $table->decimal('puntaje', 5, 2)
                    ->default(0)
                    ->comment('Puntaje al momento del cierre');

                $table->enum('estado_obsolescencia', ['optimo', 'funcional', 'potencial', 'obsoleto'])
                    ->default('obsoleto')
                    ->comment('Estado calculado a partir del puntaje');

                // ── Snapshot de matzobs_activos_d ─────────────────────────────
                $table->string('marca', 100)->nullable();
                $table->string('tipo', 100)->nullable();
                $table->string('referencia', 255)->nullable();
                $table->string('tipo_unidad', 100)->nullable();
                $table->date('fecha_compra')->nullable();
                $table->string('modalidad', 100)->nullable();
                $table->string('proveedor', 255)->nullable();
                $table->string('sistema_operativo', 255)->nullable();

                // Edad
                $table->decimal('edad', 4, 1)->nullable();
                $table->float('edad_v_util')->nullable();
                $table->decimal('valoracion_edad', 5, 2)->nullable();

                // RAM
                $table->decimal('tamano_ram', 8, 2)->nullable();
                $table->decimal('max_ram', 10, 2)->nullable();
                $table->string('generacion_ram', 50)->nullable();
                $table->decimal('valoracion_ram', 5, 2)->nullable();

                // Procesador
                $table->string('procesador', 255)->nullable();
                $table->integer('numero_procesador')->nullable();
                $table->decimal('valoracion_procesador', 5, 2)->nullable();

                // Disco
                $table->string('tipo_disco', 100)->nullable();
                $table->decimal('tamano_disco', 10, 2)->nullable();
                $table->string('interfaz_conexion', 100)->nullable();
                $table->decimal('valoracion_disco', 5, 2)->nullable();

                // Incidencias
                $table->integer('incidencias_6_meses')->default(0);

                $table->timestamps();

                // ── Constraints ───────────────────────────────────────────────
                $table->foreign('cierre_id')
                    ->references('id')
                    ->on('matzobs_cierres')
                    ->onDelete('cascade');

                // activo_c_id sin FK forzada (el activo puede eliminarse)
                $table->index('cierre_id');
                $table->index('activo_c_id');
                $table->index('id_empresa');
                $table->index('id_sucursal');
                $table->index('id_sede');
                $table->index('puntaje');
                $table->index('estado_obsolescencia');
                $table->index(['cierre_id', 'estado_obsolescencia']);
                $table->index(['cierre_id', 'id_empresa']);
            });
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DOWN
    // ─────────────────────────────────────────────────────────────────────────
    public function down(): void
    {
        Schema::dropIfExists('matzobs_cierre_detalle');
        Schema::dropIfExists('matzobs_cierres');
        Schema::dropIfExists('matzobs_cierre_config');
    }
};
