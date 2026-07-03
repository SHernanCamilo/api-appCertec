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
        Schema::table('humtal_ct_festivos', function (Blueprint $table) {
            $table->string('tipo', 50)->nullable()->after('nombre')->comment('Tipo de festivo: religioso, civil, etc.');
            $table->string('origen', 50)->default('manual')->after('tipo')->comment('Origen: manual, api_externa');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('humtal_ct_festivos', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'origen']);
        });
    }
};
