<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unidades Funcionales: reemplaza el campo 'unidad' (texto libre) de config_person_tercero.
 *
 * Cada unidad tiene un Aprobador Financiero parametrizable (no hardcodeado).
 * Cuando cambia quién ocupa el rol, solo se actualiza esta tabla.
 * Soporta suplente por sede.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anti_unidades_funcionales', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 50)->unique();
            $table->string('nombre', 150);
            $table->unsignedBigInteger('id_empresa')->nullable();
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->foreign('id_empresa')->references('id')->on('ent_empresas')->onDelete('set null');
            $table->index('id_empresa');
            $table->index('estado');
        });

        /**
         * Aprobadores parametrizables por unidad funcional.
         * Un aprobador puede ser titular o suplente, y puede estar limitado a una sede.
         */
        Schema::create('anti_aprobadores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_unidad_funcional');
            $table->unsignedBigInteger('user_id')->comment('Usuario aprobador (de la tabla users)');
            $table->enum('rol_aprobador', ['financiero', 'jefe_inmediato', 'tesoreria', 'contabilidad'])
                  ->comment('Rol que cumple en el flujo de aprobación');
            $table->unsignedBigInteger('id_sede')->nullable()->comment('null = aplica a todas las sedes');
            $table->boolean('es_suplente')->default(false);
            $table->boolean('estado')->default(true);
            $table->timestamps();

            $table->foreign('id_unidad_funcional')->references('id')->on('anti_unidades_funcionales')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('id_sede')->references('id')->on('config_ubi_sede')->onDelete('set null');

            $table->index(['id_unidad_funcional', 'rol_aprobador', 'estado']);
        });

        // Agrega FK de unidad funcional al empleado (reemplaza el campo unidad texto libre)
        Schema::table('config_person_tercero', function (Blueprint $table) {
            $table->unsignedBigInteger('id_unidad_funcional')->nullable()->after('unidad');
            $table->foreign('id_unidad_funcional')->references('id')->on('anti_unidades_funcionales')->onDelete('set null');
            $table->index('id_unidad_funcional');
        });
    }

    public function down(): void
    {
        Schema::table('config_person_tercero', function (Blueprint $table) {
            $table->dropForeign(['id_unidad_funcional']);
            $table->dropIndex(['id_unidad_funcional']);
            $table->dropColumn('id_unidad_funcional');
        });

        Schema::dropIfExists('anti_aprobadores');
        Schema::dropIfExists('anti_unidades_funcionales');
    }
};
