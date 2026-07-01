<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('humtal_ct_grupos', function (Blueprint $table) {
            if (!Schema::hasColumn('humtal_ct_grupos', 'id_unidad_funcional')) {
                $table->unsignedBigInteger('id_unidad_funcional')->nullable()->after('id_sede');
                $table->foreign('id_unidad_funcional')->references('id')->on('config_unidades_funcionales')->onDelete('set null');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('humtal_ct_grupos', function (Blueprint $table) {
            $table->dropForeign(['id_unidad_funcional']);
            $table->dropColumn('id_unidad_funcional');
        });
    }
};
