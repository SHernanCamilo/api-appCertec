<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega campos faltantes para la Vista Excel de recepción técnica.
 *
 * Estos campos existían en el sistema legacy (JadeInventory) y son necesarios
 * para replicar la experiencia de recepción tipo spreadsheet.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('inv_recepcion_detalles', function (Blueprint $table) {
            $table->string('fabricante', 200)->nullable()->after('codigo_sanitario');
            $table->string('vida_util', 50)->nullable()->after('fabricante')
                ->comment('Vida útil del producto (ej: 24 meses)');
            $table->string('estado_invima', 30)->nullable()->after('vida_util')
                ->comment('Vigente, Vencido, No encontrado');
            $table->unsignedInteger('muestra_poblacion')->nullable()->after('cantidad_recibida')
                ->comment('Cantidad de muestra según tabla de muestreo');
            $table->string('marca', 150)->nullable()->after('producto_nombre');
            $table->string('tipo_producto', 50)->nullable()->after('marca')
                ->comment('Medicamento, Dispositivo Médico, Insumo');
            $table->string('forma_farmaceutica', 100)->nullable()->after('tipo_producto');
            $table->string('concentracion', 100)->nullable()->after('forma_farmaceutica');
            $table->string('unidad_empaque', 50)->nullable()->after('concentracion');
        });
    }

    public function down(): void
    {
        Schema::table('inv_recepcion_detalles', function (Blueprint $table) {
            $table->dropColumn([
                'fabricante', 'vida_util', 'estado_invima', 'muestra_poblacion',
                'marca', 'tipo_producto', 'forma_farmaceutica', 'concentracion', 'unidad_empaque'
            ]);
        });
    }
};
