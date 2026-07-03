<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('humtal_ct_asignacion', function (Blueprint $table) {
            $table->unsignedBigInteger('creado_por')->nullable()->after('observacion');
            $table->unsignedBigInteger('actualizado_por')->nullable()->after('creado_por');
        });
    }

    public function down(): void
    {
        Schema::table('humtal_ct_asignacion', function (Blueprint $table) {
            $table->dropColumn(['creado_por', 'actualizado_por']);
        });
    }
};
