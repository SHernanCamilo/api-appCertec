<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desdoblamiento por CUM/lote (2026-08-27):
 *   - `cum_recibido`: CUM real con el que llegó cada fragmento. En un
 *     desdoblamiento un mismo renglón de la OC (pedido_detalle_id) llega en
 *     varios CUM/lote; cada fragmento se guarda como un detalle independiente
 *     y necesita su propio CUM para la trazabilidad y los reportes.
 *   - `es_desdoblamiento`: TRUE cuando el detalle es un fragmento (hijo) de un
 *     renglón desdoblado, para identificarlo en reportes y en el detalle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inv_recepcion_detalles', function (Blueprint $table) {
            if (!Schema::hasColumn('inv_recepcion_detalles', 'cum_recibido')) {
                $table->string('cum_recibido', 100)
                    ->nullable()
                    ->after('codigo_producto')
                    ->comment('CUM real recibido del fragmento (desdoblamiento por CUM/lote)');
            }
            if (!Schema::hasColumn('inv_recepcion_detalles', 'es_desdoblamiento')) {
                $table->boolean('es_desdoblamiento')
                    ->default(false)
                    ->after('pedido_detalle_id')
                    ->comment('TRUE si el detalle es un fragmento (hijo) de un renglón desdoblado');
            }
            // Reparación: la migración 2026_08_26 declaraba esta columna en su
            // docblock pero nunca la creaba. Se agrega aquí (idempotente) para que
            // el esquema sea reproducible. En producción ya existe → se salta.
            if (!Schema::hasColumn('inv_recepcion_detalles', 'invima_override_manual')) {
                $table->boolean('invima_override_manual')
                    ->default(false)
                    ->after('estado_invima')
                    ->comment('TRUE si el usuario confirmó el estado INVIMA manualmente');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inv_recepcion_detalles', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('inv_recepcion_detalles', 'cum_recibido'))       $cols[] = 'cum_recibido';
            if (Schema::hasColumn('inv_recepcion_detalles', 'es_desdoblamiento'))  $cols[] = 'es_desdoblamiento';
            if (!empty($cols)) $table->dropColumn($cols);
        });
    }
};
