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
        Schema::table('wf_aprobadores', function (Blueprint $table) {
            $table->enum('tipo_aprobador', ['USER', 'RESPONSABLE_UF', 'RESPONSABLE_GRUPO'])->default('USER')->after('id_paso');
            $table->json('condiciones')->nullable()->after('es_suplente');
            
            // Allow id_user to be nullable if we use dynamic approvers
            $table->unsignedBigInteger('id_user')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wf_aprobadores', function (Blueprint $table) {
            $table->dropColumn(['tipo_aprobador', 'condiciones']);
            // We revert id_user to not null, assuming it was not null before. (If it was nullable, no need, but better safe).
            // Usually we'd need doctrine/dbal, so let's just leave id_user nullable for down migration if it fails, or explicitly change it back if needed.
            $table->unsignedBigInteger('id_user')->nullable(false)->change();
        });
    }
};
