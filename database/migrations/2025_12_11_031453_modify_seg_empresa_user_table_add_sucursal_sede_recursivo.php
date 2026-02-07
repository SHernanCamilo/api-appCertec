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
        Schema::table('seg_empresa_user', function (Blueprint $table) {
            $table->unsignedBigInteger('id_sucursal')->nullable()->after('empresa_id');
            $table->unsignedBigInteger('id_sede')->nullable()->after('id_sucursal');
            $table->boolean('recursivo')->default(false)->after('id_sede');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seg_empresa_user', function (Blueprint $table) {
            $table->dropColumn(['id_sucursal', 'id_sede', 'recursivo']);
        });
    }
};
