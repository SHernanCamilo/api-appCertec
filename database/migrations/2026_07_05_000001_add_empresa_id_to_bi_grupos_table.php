<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('bi_grupos')) {
            return;
        }

        Schema::table('bi_grupos', function (Blueprint $table) {
            if (!Schema::hasColumn('bi_grupos', 'empresa_id')) {
                $table->unsignedBigInteger('empresa_id')->nullable()->after('id');
                $table->foreign('empresa_id')
                    ->references('id')
                    ->on('ent_empresas')
                    ->nullOnDelete();
                $table->index('empresa_id');
            }
        });

        Schema::table('bi_grupos', function (Blueprint $table) {
            $table->dropUnique(['codigo']);
            $table->unique(['empresa_id', 'codigo'], 'bi_grupos_empresa_codigo_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('bi_grupos')) {
            return;
        }

        Schema::table('bi_grupos', function (Blueprint $table) {
            $table->dropUnique('bi_grupos_empresa_codigo_unique');
            $table->unique('codigo');
        });

        Schema::table('bi_grupos', function (Blueprint $table) {
            if (Schema::hasColumn('bi_grupos', 'empresa_id')) {
                $table->dropForeign(['empresa_id']);
                $table->dropIndex(['empresa_id']);
                $table->dropColumn('empresa_id');
            }
        });
    }
};
