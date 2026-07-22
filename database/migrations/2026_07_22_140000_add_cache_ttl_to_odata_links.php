<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega cache_ttl y page_size a odata_links para control granular de rendimiento.
 *
 * cache_ttl: segundos que se cachea la respuesta en Redis (default 120 = 2 min)
 * page_size: tamaño de página por link (default 20000, max 50000)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odata_links', function (Blueprint $table) {
            $table->unsignedInteger('cache_ttl')
                ->default(120)
                ->after('max_rows')
                ->comment('Cache TTL en segundos (120=2min, 1800=30min, 3600=1h)');
            $table->unsignedInteger('page_size')
                ->default(20000)
                ->after('cache_ttl')
                ->comment('Filas por página OData (max 50000)');
        });
    }

    public function down(): void
    {
        Schema::table('odata_links', function (Blueprint $table) {
            $table->dropColumn(['cache_ttl', 'page_size']);
        });
    }
};
