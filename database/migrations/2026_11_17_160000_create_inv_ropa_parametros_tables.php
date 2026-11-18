<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parámetros del inventario de ropa hospitalaria.
 *
 * Los cupos (camas, camas de médico y personal) viven aquí y no en
 * config_unidades_funcionales, porque esa tabla la usan el resto de módulos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_ropa_prendas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->nullable()->unique();
            $table->string('nombre', 150);
            $table->string('regla', 20)->default('cama')->comment('cama, estar_medico o personal');
            $table->unsignedTinyInteger('estado')->default(1)->comment('1 vigente, 0 baja');
            $table->timestamps();

            $table->index('estado');
            $table->index('regla');
        });

        Schema::create('inv_ropa_unidad_cupos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_unidad_funcional')->unique()->constrained('config_unidades_funcionales');
            $table->unsignedInteger('camas')->default(0);
            $table->unsignedInteger('camas_medico')->default(0);
            $table->unsignedInteger('personal')->default(0);
            $table->boolean('aplica_estar_medico')->default(false);
            $table->boolean('aplica_personal')->default(false);
            $table->timestamps();
        });

        Schema::create('inv_ropa_constantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_prenda')->constrained('inv_ropa_prendas');
            $table->foreignId('id_unidad_funcional')->constrained('config_unidades_funcionales');
            $table->decimal('constante', 8, 2);
            $table->unsignedTinyInteger('estado')->default(1)->comment('1 vigente, 0 baja');
            $table->foreignId('id_usuario')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['id_prenda', 'id_unidad_funcional', 'estado'], 'inv_ropa_const_par_estado');
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_ropa_constantes');
        Schema::dropIfExists('inv_ropa_unidad_cupos');
        Schema::dropIfExists('inv_ropa_prendas');
    }
};
