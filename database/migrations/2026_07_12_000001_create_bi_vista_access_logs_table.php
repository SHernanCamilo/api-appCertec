<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bi_vista_access_logs')) {
            return;
        }

        Schema::create('bi_vista_access_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_email', 150)->nullable();
            $table->string('user_name', 200)->nullable();
            $table->unsignedBigInteger('empresa_id')->nullable();
            $table->string('empresa_nombre', 200)->nullable();
            $table->string('schema_name', 30);
            $table->string('view_name', 200);
            $table->enum('accion', [
                'consulta',
                'exportacion_inicio',
                'exportacion_descarga',
                'exportacion_sync',
            ]);
            $table->json('filters')->nullable();
            $table->unsignedInteger('rows_returned')->default(0);
            $table->unsignedInteger('elapsed_ms')->default(0);
            $table->boolean('success')->default(true);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('accessed_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['accessed_at', 'accion']);
            $table->index(['empresa_id', 'schema_name']);
            $table->index(['user_id', 'view_name']);
            $table->index(['schema_name', 'view_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bi_vista_access_logs');
    }
};
