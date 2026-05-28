<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('config_unidades_fun_responsable')) {
            Schema::create('config_unidades_fun_responsable', function (Blueprint $table) {
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
                    'uq_unidad_funcional_responsable'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('config_unidades_fun_responsable');
    }
};
