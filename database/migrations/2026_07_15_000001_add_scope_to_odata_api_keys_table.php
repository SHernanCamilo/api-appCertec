<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega campo 'scope' a odata_api_keys para distinguir entre:
 * - 'private':  Key personal, solo el dueño puede usarla con su email.
 * - 'shared':   Key compartida, cualquier usuario con permiso en bi_vista_user_permissions
 *               puede usarla con su propio email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odata_api_keys', function (Blueprint $table) {
            $table->enum('scope', ['private', 'shared'])->default('private')
                ->after('key_prefix')
                ->comment('private=solo el dueño, shared=cualquiera con permiso Excel');
        });
    }

    public function down(): void
    {
        Schema::table('odata_api_keys', function (Blueprint $table) {
            $table->dropColumn('scope');
        });
    }
};
