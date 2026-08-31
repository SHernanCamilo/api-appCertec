<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina la tabla legacy `inv_secuencias`.
 *
 * Era el esquema de secuencias del software anterior (tipo_documento, prefijo,
 * ultimo_numero, longitud). El sistema actual usa el esquema centralizado
 * config_sec_secuencias / config_sec_detalles / config_sec_patrones, por lo que
 * `inv_secuencias` quedó en desuso.
 *
 * Seguridad: se verificó que NINGUNA foreign key apunta a esta tabla, así que
 * eliminarla no rompe integridad referencial. Los datos que contenía eran solo
 * contadores legacy (ya reflejados en config_sec_detalles por el seeder de
 * inventario), no información de negocio que se pierda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('inv_secuencias');
    }

    /**
     * Recrea la estructura vacía (sin datos) por reversibilidad del esquema.
     * Los datos legacy no se restauran porque ya no se usan.
     */
    public function down(): void
    {
        if (!Schema::hasTable('inv_secuencias')) {
            Schema::create('inv_secuencias', function (Blueprint $table) {
                $table->id();
                $table->string('tipo_documento', 50);
                $table->string('prefijo', 20)->nullable();
                $table->unsignedBigInteger('ultimo_numero')->default(0);
                $table->unsignedTinyInteger('longitud')->default(6);
                $table->timestamps();
            });
        }
    }
};
