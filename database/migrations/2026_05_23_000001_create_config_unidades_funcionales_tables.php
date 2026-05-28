<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('config_unidades_funcionales')) {
            Schema::create('config_unidades_funcionales', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_empresa');
                $table->unsignedBigInteger('id_sucursal');
                $table->unsignedBigInteger('id_sede')->nullable();
                $table->string('codigo', 20);
                $table->string('nombre', 150);
                $table->boolean('estado')->default(true);
                $table->timestamps();

                $table->foreign('id_empresa')->references('id')->on('ent_empresas')->onDelete('cascade');
                $table->foreign('id_sucursal')->references('id')->on('config_ubi_sucursales')->onDelete('cascade');
                $table->foreign('id_sede')->references('id')->on('config_ubi_sede')->onDelete('set null');

                $table->unique(['id_empresa', 'codigo'], 'uq_unidad_funcional_empresa_codigo');
                $table->index(['id_empresa', 'estado']);
                $table->index('id_sucursal');
                $table->index('id_sede');
            });
        }

        if (!Schema::hasTable('config_unidades_fun_usuarios')) {
            Schema::create('config_unidades_fun_usuarios', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_unidad_funcional');
                $table->unsignedBigInteger('id_user');
                $table->timestamps();

                $table->foreign('id_unidad_funcional')
                    ->references('id')
                    ->on('config_unidades_funcionales')
                    ->onDelete('cascade');
                $table->foreign('id_user')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');

                $table->unique(
                    ['id_unidad_funcional', 'id_user'],
                    'uq_unidad_funcional_usuario'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('config_unidades_fun_usuarios');
        Schema::dropIfExists('config_unidades_funcionales');
    }
};
