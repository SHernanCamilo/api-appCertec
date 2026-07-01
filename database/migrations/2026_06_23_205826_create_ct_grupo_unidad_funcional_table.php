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
        Schema::create('humtal_ct_grupo_unidad_funcional', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_grupo');
            $table->unsignedBigInteger('id_unidad_funcional');
            $table->timestamps();

            $table->foreign('id_grupo')->references('id')->on('humtal_ct_grupos')->onDelete('cascade');
            $table->foreign('id_unidad_funcional')->references('id')->on('config_unidades_funcionales')->onDelete('cascade');
        });

        // Remove id_unidad_funcional from humtal_ct_grupos
        Schema::table('humtal_ct_grupos', function (Blueprint $table) {
            $table->dropForeign(['id_unidad_funcional']);
            $table->dropColumn('id_unidad_funcional');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('humtal_ct_grupos', function (Blueprint $table) {
            $table->unsignedBigInteger('id_unidad_funcional')->nullable()->after('id_sede');
            $table->foreign('id_unidad_funcional')->references('id')->on('config_unidades_funcionales')->onDelete('set null');
        });

        Schema::dropIfExists('humtal_ct_grupo_unidad_funcional');
    }
};
