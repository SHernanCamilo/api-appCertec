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
        Schema::table('users', function (Blueprint $table) {
            $table->string('tipo_identificacion', 10)->nullable()->after('name');
            $table->string('numero_identificacion', 50)->nullable()->after('tipo_identificacion');
            $table->string('direccion', 255)->nullable()->after('numero_identificacion');
            $table->string('telefono', 20)->nullable()->after('direccion');
            $table->unsignedBigInteger('id_sucursal')->nullable()->after('telefono');
            $table->unsignedBigInteger('id_sede')->nullable()->after('id_sucursal');
            
            // No agregamos foreign keys por ahora para evitar problemas de dependencias
            // Se pueden agregar manualmente después si es necesario
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Eliminar columnas
            $table->dropColumn([
                'tipo_identificacion',
                'numero_identificacion',
                'direccion',
                'telefono',
                'id_sucursal',
                'id_sede'
            ]);
        });
    }
};
