<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('glpi_param_plantilla_categorias', function (Blueprint $table): void {
            $table->string('nombre', 150)->nullable()->after('plantilla_id');
            $table->unsignedBigInteger('parent_id')->nullable()->after('nombre');
            $table->unsignedTinyInteger('nivel')->default(1)->after('parent_id');
        });

        Schema::table('glpi_param_plantilla_categorias', function (Blueprint $table): void {
            $table->foreign('parent_id', 'fk_glpi_param_cat_parent')
                ->references('id')
                ->on('glpi_param_plantilla_categorias')
                ->nullOnDelete();
            $table->index(['plantilla_id', 'parent_id'], 'idx_glpi_param_cat_parent');
        });
    }

    public function down(): void
    {
        Schema::table('glpi_param_plantilla_categorias', function (Blueprint $table): void {
            $table->dropForeign('fk_glpi_param_cat_parent');
            $table->dropIndex('idx_glpi_param_cat_parent');
            $table->dropColumn(['nombre', 'parent_id', 'nivel']);
        });
    }
};
