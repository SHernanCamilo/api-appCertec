<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mejora la tabla anti_solicitud_documentos para soportar OneDrive y más metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anti_solicitud_documentos', function (Blueprint $table) {
            if (!Schema::hasColumn('anti_solicitud_documentos', 'disco')) {
                $table->string('disco', 30)->default('onedrive')->after('ruta_archivo')
                    ->comment('Disco de almacenamiento (local, onedrive, s3, r2)');
            }
            if (!Schema::hasColumn('anti_solicitud_documentos', 'mime_type')) {
                $table->string('mime_type', 100)->nullable()->after('disco');
            }
            if (!Schema::hasColumn('anti_solicitud_documentos', 'tamano')) {
                $table->unsignedInteger('tamano')->nullable()->after('mime_type')
                    ->comment('Tamaño en bytes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('anti_solicitud_documentos', function (Blueprint $table) {
            $table->dropColumn(['disco', 'mime_type', 'tamano']);
        });
    }
};
