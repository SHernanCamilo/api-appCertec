<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de Flujos Genérico - Reutilizable para cualquier módulo del sistema.
 *
 * Soporta:
 *   - Multi-empresa
 *   - Multi-módulo (Anticipos, Eventos, Horas Extras, Solicitudes, etc.)
 *   - Pasos dinámicos con condiciones
 *   - Aprobadores parametrizables
 *   - Escalamiento automático
 *   - Notificaciones
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * Módulos del sistema que usan flujos.
         * Cada módulo puede tener N flujos.
         */
        Schema::create('wf_modulos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique()->comment('anticipos, eventos, horas_extras, etc.');
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();
        });

        /**
         * Definiciones de flujos.
         * Un flujo es una secuencia de pasos de aprobación.
         */
        Schema::create('wf_definiciones', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->unsignedBigInteger('id_modulo')->comment('FK a wf_modulos');
            $table->unsignedBigInteger('id_empresa')->nullable()->comment('null = aplica a todas las empresas');
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->foreign('id_modulo')->references('id')->on('wf_modulos')->onDelete('restrict');
            $table->foreign('id_empresa')->references('id')->on('ent_empresas')->onDelete('cascade');
            
            $table->index(['id_modulo', 'id_empresa', 'estado']);
        });

        /**
         * Pasos de un flujo (secuencia ordenada).
         */
        Schema::create('wf_pasos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_definicion');
            $table->unsignedTinyInteger('orden')->comment('1, 2, 3... orden de ejecución');
            $table->string('nombre_paso', 100);
            $table->string('rol_aprobador', 50)->comment('jefe_inmediato, financiero, tesoreria, etc.');
            $table->boolean('es_opcional')->default(false);
            $table->boolean('permite_rechazo')->default(true);
            $table->boolean('requiere_monto')->default(false)->comment('Si requiere ingresar monto autorizado');
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->foreign('id_definicion')->references('id')->on('wf_definiciones')->onDelete('cascade');
            $table->unique(['id_definicion', 'orden']);
            $table->index(['id_definicion', 'orden', 'estado']);
        });

        /**
         * Reglas de asignación de flujo.
         * Determina qué flujo se aplica según condiciones.
         */
        Schema::create('wf_reglas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_definicion');
            $table->unsignedTinyInteger('prioridad')->default(100)->comment('Menor número = mayor prioridad');
            
            // Condiciones (JSON para flexibilidad)
            $table->json('condiciones')->comment('Ej: {"nivel_min":1,"nivel_max":3,"prefijo":"MA","monto_min":0,"monto_max":5000000}');
            
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->foreign('id_definicion')->references('id')->on('wf_definiciones')->onDelete('cascade');
            $table->index(['id_definicion', 'prioridad', 'estado']);
        });

        /**
         * Aprobadores por paso.
         * Resuelve quién aprueba cada paso.
         */
        Schema::create('wf_aprobadores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_paso');

            // Opción 1: Aprobador fijo
            $table->unsignedBigInteger('id_user')->nullable();

            // Opción 2: Aprobador por unidad funcional (dinámico)
            $table->unsignedBigInteger('id_unidad_funcional')->nullable();

            // Opción 3: Aprobador por prefijo de sucursal
            $table->string('prefijo_sucursal', 10)->nullable();

            // Sede específica (null = aplica a todas)
            $table->unsignedBigInteger('id_sede')->nullable();

            $table->boolean('es_suplente')->default(false);
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->foreign('id_paso')->references('id')->on('wf_pasos')->onDelete('cascade');
            $table->foreign('id_user')->references('id')->on('users')->onDelete('set null');
            $table->foreign('id_unidad_funcional')->references('id')->on('anti_unidades_funcionales')->onDelete('set null');
            $table->foreign('id_sede')->references('id')->on('config_ubi_sede')->onDelete('set null');

            $table->index(['id_paso', 'estado']);
            $table->index('prefijo_sucursal');
        });

        /**
         * Instancias de flujo (cada solicitud tiene una instancia).
         */
        Schema::create('wf_instancias', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_definicion');
            $table->unsignedBigInteger('id_modulo');
            $table->unsignedBigInteger('modulo_record_id')->comment('ID del registro en la tabla del módulo (ej: anti_solicitudes.id)');
            $table->unsignedBigInteger('id_paso_actual')->nullable();
            $table->enum('estado', ['en_progreso', 'completado', 'rechazado', 'cancelado'])->default('en_progreso');
            $table->timestamps();

            $table->foreign('id_definicion')->references('id')->on('wf_definiciones')->onDelete('restrict');
            $table->foreign('id_modulo')->references('id')->on('wf_modulos')->onDelete('restrict');
            $table->foreign('id_paso_actual')->references('id')->on('wf_pasos')->onDelete('set null');

            $table->index(['id_modulo', 'modulo_record_id']);
            $table->index(['estado', 'id_paso_actual']);
        });

        /**
         * Historial de aprobaciones.
         */
        Schema::create('wf_aprobaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_instancia');
            $table->unsignedBigInteger('id_paso');
            $table->unsignedBigInteger('id_user');
            $table->enum('accion', ['aprobado', 'rechazado', 'observacion', 'devuelto']);
            $table->text('comentario')->nullable();
            $table->decimal('monto_autorizado', 15, 2)->nullable();
            $table->timestamp('fecha_accion');
            $table->timestamps();

            $table->foreign('id_instancia')->references('id')->on('wf_instancias')->onDelete('cascade');
            $table->foreign('id_paso')->references('id')->on('wf_pasos')->onDelete('restrict');
            $table->foreign('id_user')->references('id')->on('users')->onDelete('restrict');

            $table->index(['id_instancia', 'id_paso']);
            $table->index('fecha_accion');
        });

        /**
         * Notificaciones de flujo.
         */
        Schema::create('wf_notificaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_instancia');
            $table->unsignedBigInteger('id_user');
            $table->string('tipo', 50)->comment('pendiente_aprobacion, aprobado, rechazado, etc.');
            $table->text('mensaje');
            $table->boolean('leida')->default(false);
            $table->timestamp('fecha_lectura')->nullable();
            $table->timestamps();

            $table->foreign('id_instancia')->references('id')->on('wf_instancias')->onDelete('cascade');
            $table->foreign('id_user')->references('id')->on('users')->onDelete('cascade');

            $table->index(['id_user', 'leida']);
            $table->index('created_at');
        });

        /**
         * Agrega prefijo a sucursales si no existe.
         */
        if (!Schema::hasColumn('config_ubi_sucursales', 'prefijo')) {
            Schema::table('config_ubi_sucursales', function (Blueprint $table) {
                $table->string('prefijo', 10)->nullable()->after('nombre')->comment('MA, NVA, EAL, TJA, FLA...');
                $table->index('prefijo');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wf_notificaciones');
        Schema::dropIfExists('wf_aprobaciones');
        Schema::dropIfExists('wf_instancias');
        Schema::dropIfExists('wf_aprobadores');
        Schema::dropIfExists('wf_reglas');
        Schema::dropIfExists('wf_pasos');
        Schema::dropIfExists('wf_definiciones');
        Schema::dropIfExists('wf_modulos');

        if (Schema::hasColumn('config_ubi_sucursales', 'prefijo')) {
            Schema::table('config_ubi_sucursales', function (Blueprint $table) {
                $table->dropIndex(['prefijo']);
                $table->dropColumn('prefijo');
            });
        }
    }
};
