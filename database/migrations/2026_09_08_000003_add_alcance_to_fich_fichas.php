<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alcance de la ficha: nacional / sucursal / sede.
 *
 * Una ficha puede aplicar a nivel nacional (todas las sucursales), a una o
 * varias sucursales, o a sedes específicas. El legacy solo guardaba un campo
 * de texto `sucursal`; aquí se modela con un tipo de alcance y tablas pivote
 * para las selecciones múltiples.
 *
 *   tipo_alcance = 'nacional' → aplica a todo, sin filas en los pivotes.
 *   tipo_alcance = 'sucursal' → 1..N filas en fich_ficha_sucursal.
 *   tipo_alcance = 'sede'     → 1..N filas en fich_ficha_sede.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fich_fichas', function (Blueprint $tabla): void {
            if (! Schema::hasColumn('fich_fichas', 'tipo_alcance')) {
                $tabla->enum('tipo_alcance', ['nacional', 'sucursal', 'sede'])
                    ->default('sucursal')
                    ->after('sucursal_legacy');
            }
        });

        Schema::create('fich_ficha_sucursal', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('id_ficha')->constrained('fich_fichas')->cascadeOnDelete();
            $tabla->foreignId('id_sucursal')->constrained('config_ubi_sucursales')->cascadeOnDelete();
            $tabla->timestamps();
            $tabla->unique(['id_ficha', 'id_sucursal']);
        });

        Schema::create('fich_ficha_sede', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->foreignId('id_ficha')->constrained('fich_fichas')->cascadeOnDelete();
            $tabla->foreignId('id_sede')->constrained('config_ubi_sede')->cascadeOnDelete();
            $tabla->timestamps();
            $tabla->unique(['id_ficha', 'id_sede']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fich_ficha_sede');
        Schema::dropIfExists('fich_ficha_sucursal');

        Schema::table('fich_fichas', function (Blueprint $tabla): void {
            if (Schema::hasColumn('fich_fichas', 'tipo_alcance')) {
                $tabla->dropColumn('tipo_alcance');
            }
        });
    }
};
