<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de flujos parametrizable para anticipos.
 *
 * Permite definir reglas de enrutamiento dinámicas basadas en:
 *   - Nivel jerárquico del solicitante
 *   - Prefijo de sucursal (MA, NVA, EAL, TJA, FLA...)
 *   - Monto del anticipo
 *   - Tipo de anticipo (nacional, internacional)
 *
 * Cada flujo tiene pasos secuenciales con condiciones de escalamiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        /**
         * Definición de flujos de aprobación.
         * Un flujo agrupa los pasos que debe seguir una solicitud.
         */
        Schema::create('anti_flujos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('nombre', 150);
            $table->text('descripcion')->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();
        });

        /**
         * Pasos dentro de un flujo (secuencia ordenada).
         * Cada paso define quién aprueba y bajo qué condiciones.
         */
        Schema::create('anti_flujo_pasos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_flujo');
            $table->unsignedTinyInteger('orden')->comment('1, 2, 3... orden de ejecución');
            $table->string('nombre_paso', 100)->comment('Ej: Aprobación Jefe Inmediato');
            $table->enum('rol_aprobador', ['jefe_inmediato', 'financiero', 'tesoreria', 'contabilidad', 'vicepresidente'])
                  ->comment('Rol que debe aprobar este paso');
            $table->boolean('es_opcional')->default(false)->comment('Si es opcional, se puede saltar');
            $table->boolean('permite_rechazo')->default(true);
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->foreign('id_flujo')->references('id')->on('anti_flujos')->onDelete('cascade');
            $table->unique(['id_flujo', 'orden']);
            $table->index(['id_flujo', 'orden', 'estado']);
        });

        /**
         * Reglas de asignación de flujo.
         * Determina qué flujo se aplica según las características de la solicitud.
         *
         * Lógica de evaluación (todas las condiciones deben cumplirse):
         *   - nivel_jerarquico_min/max: rango del nivel del solicitante
         *   - prefijo_sucursal: null = aplica a todas, o específico (MA, NVA...)
         *   - monto_min/max: rango del monto solicitado
         *   - cobertura: null = aplica a todas, o específico (nacional, internacional)
         *
         * Prioridad: se evalúa por orden de prioridad (menor número = mayor prioridad).
         */
        Schema::create('anti_flujo_reglas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_flujo');
            $table->unsignedTinyInteger('prioridad')->default(100)->comment('Menor número = mayor prioridad');

            // Condiciones de nivel jerárquico
            $table->unsignedTinyInteger('nivel_jerarquico_min')->nullable()->comment('null = sin límite inferior');
            $table->unsignedTinyInteger('nivel_jerarquico_max')->nullable()->comment('null = sin límite superior');

            // Condiciones de sucursal
            $table->string('prefijo_sucursal', 10)->nullable()->comment('null = aplica a todas, ej: MA, NVA, EAL');

            // Condiciones de monto
            $table->decimal('monto_min', 15, 2)->nullable()->comment('null = sin límite inferior');
            $table->decimal('monto_max', 15, 2)->nullable()->comment('null = sin límite superior');

            // Condiciones de cobertura
            $table->enum('cobertura', ['nacional', 'internacional'])->nullable()->comment('null = aplica a ambas');

            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->foreign('id_flujo')->references('id')->on('anti_flujos')->onDelete('cascade');
            $table->index(['id_flujo', 'prioridad', 'estado']);
            $table->index('prefijo_sucursal');
        });

        /**
         * Asignación de aprobadores por paso.
         * Resuelve quién es el aprobador concreto para un paso dado.
         *
         * Lógica de resolución:
         *   1. Si id_user está definido → aprobador fijo
         *   2. Si id_unidad_funcional está definido → busca en anti_aprobadores
         *   3. Si prefijo_sucursal está definido → busca aprobador de esa sucursal
         *
         * Permite suplentes por sede.
         */
        Schema::create('anti_flujo_aprobadores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_flujo_paso');

            // Opción 1: Aprobador fijo (ej: VP siempre es el mismo user_id)
            $table->unsignedBigInteger('id_user')->nullable()->comment('Aprobador fijo');

            // Opción 2: Aprobador por unidad funcional (dinámico según solicitante)
            $table->unsignedBigInteger('id_unidad_funcional')->nullable()->comment('Busca en anti_aprobadores');

            // Opción 3: Aprobador por prefijo de sucursal (ej: Dir. Financiera NVA)
            $table->string('prefijo_sucursal', 10)->nullable()->comment('Busca aprobador de esa sucursal');

            // Sede específica (null = aplica a todas)
            $table->unsignedBigInteger('id_sede')->nullable();

            $table->boolean('es_suplente')->default(false);
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->foreign('id_flujo_paso')->references('id')->on('anti_flujo_pasos')->onDelete('cascade');
            $table->foreign('id_user')->references('id')->on('users')->onDelete('set null');
            $table->foreign('id_unidad_funcional')->references('id')->on('anti_unidades_funcionales')->onDelete('set null');
            $table->foreign('id_sede')->references('id')->on('config_ubi_sede')->onDelete('set null');

            $table->index(['id_flujo_paso', 'estado']);
        });

        /**
         * Agrega prefijo a sucursales para poder enrutar por prefijo.
         */
        Schema::table('config_ubi_sucursales', function (Blueprint $table) {
            if (!Schema::hasColumn('config_ubi_sucursales', 'prefijo')) {
                $table->string('prefijo', 10)->nullable()->after('nombre')->comment('MA, NVA, EAL, TJA, FLA...');
                $table->index('prefijo');
            }
        });

        /**
         * Agrega nivel jerárquico superior a config_cargo.
         * Nivel 4+ = Gerente/VP que escala a Vicepresidente.
         */
        if (!Schema::hasColumn('config_cargo', 'nivel_jerarquico')) {
            Schema::table('config_cargo', function (Blueprint $table) {
                $table->unsignedTinyInteger('nivel_jerarquico')->default(3)
                      ->comment('1=Estratégico, 2=Táctico, 3=Operativo, 4+=Gerencia/VP')
                      ->after('nombre_cargo');
                $table->index('nivel_jerarquico');
            });
        }

        /**
         * Agrega id_flujo a la solicitud (se asigna al crear la solicitud).
         */
        Schema::table('anti_solicitudes', function (Blueprint $table) {
            $table->unsignedBigInteger('id_flujo')->nullable()->after('numero_solicitud');
            $table->unsignedBigInteger('id_paso_actual')->nullable()->comment('Paso en el que está actualmente');

            $table->foreign('id_flujo')->references('id')->on('anti_flujos')->onDelete('set null');
            $table->foreign('id_paso_actual')->references('id')->on('anti_flujo_pasos')->onDelete('set null');

            $table->index('id_flujo');
            $table->index('id_paso_actual');
        });
    }

    public function down(): void
    {
        Schema::table('anti_solicitudes', function (Blueprint $table) {
            $table->dropForeign(['id_flujo']);
            $table->dropForeign(['id_paso_actual']);
            $table->dropIndex(['id_flujo']);
            $table->dropIndex(['id_paso_actual']);
            $table->dropColumn(['id_flujo', 'id_paso_actual']);
        });

        Schema::table('config_ubi_sucursales', function (Blueprint $table) {
            if (Schema::hasColumn('config_ubi_sucursales', 'prefijo')) {
                $table->dropIndex(['prefijo']);
                $table->dropColumn('prefijo');
            }
        });

        Schema::dropIfExists('anti_flujo_aprobadores');
        Schema::dropIfExists('anti_flujo_reglas');
        Schema::dropIfExists('anti_flujo_pasos');
        Schema::dropIfExists('anti_flujos');
    }
};
