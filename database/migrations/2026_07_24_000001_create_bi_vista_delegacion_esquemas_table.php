<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bi_vista_delegacion_esquemas')) {
            return;
        }

        Schema::create('bi_vista_delegacion_esquemas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empresa_id')->comment('Empresa dueña de ambos esquemas');
            $table->unsignedBigInteger('id_bi_grupos_origen')->comment('Esquema dueño de la vista (ej. DF)');
            $table->unsignedBigInteger('id_bi_grupos_destino')->comment('Esquema receptor (ej. AA)');
            $table->unsignedBigInteger('id_bi_vista');
            $table->timestamps();

            $table->foreign('empresa_id')
                ->references('id')
                ->on('ent_empresas')
                ->cascadeOnDelete();

            $table->foreign('id_bi_grupos_origen')
                ->references('id')
                ->on('bi_grupos')
                ->cascadeOnDelete();

            $table->foreign('id_bi_grupos_destino')
                ->references('id')
                ->on('bi_grupos')
                ->cascadeOnDelete();

            $table->foreign('id_bi_vista')
                ->references('id')
                ->on('bi_vistas')
                ->cascadeOnDelete();

            $table->unique(
                ['id_bi_grupos_destino', 'id_bi_vista'],
                'bi_vista_deleg_esquema_destino_vista_unique'
            );
            $table->index(
                ['id_bi_grupos_destino', 'empresa_id'],
                'bi_vista_deleg_esquema_destino_empresa_idx'
            );
            $table->index(
                ['id_bi_grupos_origen', 'id_bi_grupos_destino'],
                'bi_vista_deleg_esquema_origen_destino_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_vista_delegacion_esquemas');
    }
};
