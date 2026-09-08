<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo parametrizable de formas de pago del contrato (plazo de pago).
 *
 * Reemplaza la lógica del legacy (ficha_pdf.php) donde la "Forma de Pago" se
 * resolvía con un `if/elseif` gigante por empresa/especialidad/agremiación.
 * Ahora es un catálogo administrable (60, 90, 120 días…) y la ficha guarda
 * cuál se seleccionó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fich_formas_pago', function (Blueprint $tabla): void {
            $tabla->id();
            $tabla->string('descripcion', 150);
            $tabla->unsignedSmallInteger('dias')->nullable();
            $tabla->boolean('estado')->default(true)->index();
            $tabla->timestamps();
        });

        Schema::table('fich_fichas', function (Blueprint $tabla): void {
            if (! Schema::hasColumn('fich_fichas', 'id_forma_pago')) {
                $tabla->foreignId('id_forma_pago')
                    ->nullable()
                    ->after('id_objeto_contrato')
                    ->constrained('fich_formas_pago')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fich_fichas', function (Blueprint $tabla): void {
            if (Schema::hasColumn('fich_fichas', 'id_forma_pago')) {
                $tabla->dropConstrainedForeignId('id_forma_pago');
            }
        });

        Schema::dropIfExists('fich_formas_pago');
    }
};
