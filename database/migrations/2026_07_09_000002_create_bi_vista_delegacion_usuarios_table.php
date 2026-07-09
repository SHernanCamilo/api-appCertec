<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bi_vista_delegacion_usuarios')) {
            return;
        }

        Schema::create('bi_vista_delegacion_usuarios', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('empresa_id');
            $table->unsignedBigInteger('id_bi_grupos');
            $table->unsignedBigInteger('id_bi_vista');
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('empresa_id')
                ->references('id')
                ->on('ent_empresas')
                ->cascadeOnDelete();

            $table->foreign('id_bi_grupos')
                ->references('id')
                ->on('bi_grupos')
                ->cascadeOnDelete();

            $table->foreign('id_bi_vista')
                ->references('id')
                ->on('bi_vistas')
                ->cascadeOnDelete();

            $table->unique(['user_id', 'id_bi_vista'], 'bi_vista_delegacion_usuarios_user_vista_unique');
            $table->index(['empresa_id', 'id_bi_grupos', 'user_id'], 'bi_vista_delegacion_usuarios_emp_grupo_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_vista_delegacion_usuarios');
    }
};
