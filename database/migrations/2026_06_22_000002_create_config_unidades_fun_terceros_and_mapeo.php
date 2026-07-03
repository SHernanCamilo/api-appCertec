<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TABLA 1: Pivote terceros ↔ unidades funcionales
        if (!Schema::hasTable('config_unidades_fun_terceros')) {
            Schema::create('config_unidades_fun_terceros', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_unidad_funcional');
                $table->unsignedBigInteger('id_tercero');
                $table->timestamps();

                $table->unique(['id_unidad_funcional', 'id_tercero'], 'uq_unidad_tercero');

                $table->foreign('id_unidad_funcional')
                      ->references('id')
                      ->on('config_unidades_funcionales')
                      ->onDelete('cascade');
            });
        }

        // TABLA 2: Mapeo texto del tenant → unidad funcional propia
        if (!Schema::hasTable('turnos_tercero_unidad_map')) {
            Schema::create('turnos_tercero_unidad_map', function (Blueprint $table) {
                $table->id();
                $table->string('unidad_tercero');
                $table->unsignedBigInteger('id_empresa');
                $table->unsignedBigInteger('id_unidad_funcional');
                $table->unsignedBigInteger('creado_por')->nullable();
                $table->timestamps();

                $table->unique(['unidad_tercero', 'id_empresa'], 'uq_mapa_unidad_empresa');

                $table->foreign('id_unidad_funcional')
                      ->references('id')
                      ->on('config_unidades_funcionales')
                      ->onDelete('restrict');
            });
        }

        // Agregar tipo_empleado a humtal_ct_cuadro si no existe
        if (!Schema::hasColumn('humtal_ct_cuadro', 'tipo_empleado')) {
            Schema::table('humtal_ct_cuadro', function (Blueprint $table) {
                $table->enum('tipo_empleado', ['user', 'tercero'])
                      ->default('user')
                      ->after('id_empleado')
                      ->comment('Indica la tabla origen de id_empleado');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('turnos_tercero_unidad_map');
        Schema::dropIfExists('config_unidades_fun_terceros');

        if (Schema::hasColumn('humtal_ct_cuadro', 'tipo_empleado')) {
            Schema::table('humtal_ct_cuadro', function (Blueprint $table) {
                $table->dropColumn('tipo_empleado');
            });
        }
    }
};
