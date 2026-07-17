<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('config_ubi_sede', 'prefijo')) {
            Schema::table('config_ubi_sede', function (Blueprint $table) {
                $table->string('prefijo', 10)
                    ->nullable()
                    ->after('nombre')
                    ->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('config_ubi_sede', 'prefijo')) {
            Schema::table('config_ubi_sede', function (Blueprint $table) {
                $table->dropColumn('prefijo');
            });
        }
    }
};
