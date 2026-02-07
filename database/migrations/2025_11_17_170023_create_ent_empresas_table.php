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
        Schema::create('ent_empresas', function (Blueprint $table) {
            // 1. ID Primaria
            $table->id();
            
            // 2. Nombre (índice)
            $table->string('nombre', 50)->charset('utf8')->collation('utf8_general_ci');
            $table->index('nombre');
            
            // 3. Prefijo
            $table->string('prefijo', 5)->charset('utf8')->collation('utf8_general_ci');
            
            // 4. Representante Legal (índice)
            $table->string('rep_legal', 50)->charset('utf8')->collation('utf8_general_ci');
            $table->index('rep_legal');
            
            // 5. CC Representante Legal
            $table->integer('cc_rep_legal');
            
            // 6. Dirección (índice)
            $table->string('direccion', 50)->charset('utf8')->collation('utf8_general_ci');
            $table->index('direccion');
            
            // 7. Teléfono (índice)
            $table->bigInteger('telefono');
            $table->index('telefono');
            
            // 8. NIT (índice)
            $table->bigInteger('nit');
            $table->index('nit');
            
            // 9. Logo
            $table->string('logo', 255)->charset('utf8')->collation('utf8_general_ci')->nullable();
            
            // 10. Estado (1=activo, 0=inactivo)
            $table->tinyInteger('estado')->default(1);
            
            // Timestamps (created_at, updated_at)
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ent_empresas');
    }
};
