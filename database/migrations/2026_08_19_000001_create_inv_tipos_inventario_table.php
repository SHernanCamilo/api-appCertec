<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea la tabla de tipos de inventario para parametrizar las reglas
     * de periodicidad (anual, mensual, etc.) en la toma de inventarios.
     */
    public function up(): void
    {
        Schema::create('inv_tipos_inventario', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique()->comment('Ej: Inventario General, Inventario Aleatorio');
            $table->enum('periodicidad', ['anual', 'mensual', 'semestral', 'trimestral', 'semanal', 'ninguna'])
                ->default('anual')
                ->comment('Define la frecuencia máxima permitida para registrar un activo con este tipo');
            $table->boolean('activo')->default(true)->comment('Define si el tipo está habilitado para uso');
            $table->text('descripcion')->nullable()->comment('Descripción o notas sobre el tipo de inventario');
            $table->timestamps();

            $table->index('activo');
            $table->index(['activo', 'periodicidad']);
        });

        // Insertar los dos tipos iniciales requeridos
        DB::table('inv_tipos_inventario')->insert([
            [
                'nombre' => 'Inventario General',
                'periodicidad' => 'anual',
                'activo' => true,
                'descripcion' => 'Inventario completo realizado una vez al año. Un activo no puede registrarse más de una vez durante el mismo año con este tipo.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'nombre' => 'Inventario Aleatorio',
                'periodicidad' => 'mensual',
                'activo' => true,
                'descripcion' => 'Inventario selectivo realizado mensualmente. Un activo no puede registrarse más de una vez durante el mismo mes con este tipo.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Revierte la migración eliminando la tabla.
     */
    public function down(): void
    {
        Schema::dropIfExists('inv_tipos_inventario');
    }
};
