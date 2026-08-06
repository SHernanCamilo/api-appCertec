<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas faltantes para completar el módulo de Inventario.
 * Migradas desde JadeInventory (digipharma) con prefijo inv_.
 *
 * Las creaciones se guardan con `Schema::hasTable` porque `inv_compras_pedidos`
 * ya la crea `2026_07_09_070425_create_inv_compras_pedidos_table`. Sin la
 * guarda, la migración no es reejecutable y rompe cualquier `migrate:fresh`.
 */
return new class extends Migration
{
    /** Crea la tabla solo si no existe, para que la migración sea reejecutable. */
    private function crearSiFalta(string $tabla, callable $definicion): void
    {
        if (! Schema::hasTable($tabla)) {
            Schema::create($tabla, $definicion);
        }
    }

    public function up(): void
    {
        // ═══════════════════════════════════════════════════════════════════
        // 1. Relación N:N entre Órdenes de Compra y Pedidos
        //    Ya existe si corrió 2026_07_09_070425_create_inv_compras_pedidos_table.
        // ═══════════════════════════════════════════════════════════════════
        $this->crearSiFalta('inv_compras_pedidos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('compra_id')->comment('FK inv_ordenes_compra');
            $table->unsignedBigInteger('pedido_id')->comment('FK inv_pedidos');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['compra_id', 'pedido_id']);
            $table->index('compra_id');
            $table->index('pedido_id');

            $table->foreign('compra_id')->references('id')->on('inv_ordenes_compra')->cascadeOnDelete();
            $table->foreign('pedido_id')->references('id')->on('inv_pedidos')->cascadeOnDelete();
        });

        // ═══════════════════════════════════════════════════════════════════
        // 2. Auditoría de modificaciones a Órdenes de Compra
        // ═══════════════════════════════════════════════════════════════════
        $this->crearSiFalta('inv_compras_auditoria', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('compra_id');
            $table->string('campo_modificado', 50);
            $table->text('valor_anterior')->nullable();
            $table->text('valor_nuevo')->nullable();
            $table->text('motivo_modificacion');
            $table->unsignedBigInteger('modificado_por');
            $table->timestamp('created_at')->useCurrent();

            $table->index('compra_id');
            $table->index('created_at');

            $table->foreign('compra_id')->references('id')->on('inv_ordenes_compra')->cascadeOnDelete();
            $table->foreign('modificado_por')->references('id')->on('users');
        });

        // ═══════════════════════════════════════════════════════════════════
        // 3. Log de validaciones de cantidades en compras
        // ═══════════════════════════════════════════════════════════════════
        $this->crearSiFalta('inv_compras_validacion_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pedido_detalle_id');
            $table->unsignedBigInteger('compra_id')->nullable();
            $table->decimal('cantidad_solicitada', 10, 2);
            $table->decimal('cantidad_disponible', 10, 2);
            $table->enum('resultado', ['APROBADO', 'RECHAZADO']);
            $table->text('motivo_rechazo')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('pedido_detalle_id');
            $table->index('compra_id');
        });

        // ═══════════════════════════════════════════════════════════════════
        // 4. Items sincronizados desde Indigo ERP
        // ═══════════════════════════════════════════════════════════════════
        $this->crearSiFalta('inv_indigo_items', function (Blueprint $table) {
            $table->id();
            $table->string('numero_pedido', 50);
            $table->unsignedBigInteger('pedido_id')->nullable();
            $table->unsignedBigInteger('pedido_detalle_id')->nullable();
            $table->unsignedBigInteger('sucursal_id')->nullable();
            $table->string('orden_compra', 50);
            $table->string('codigo_producto', 50);
            $table->string('proveedor', 255)->nullable();
            $table->decimal('cantidad_origen', 12, 4)->default(0);
            $table->decimal('cantidad_aplicada', 12, 4)->default(0);
            $table->dateTime('fecha_indigo')->nullable();
            $table->string('estado_orden', 50)->nullable();
            $table->text('descripcion_orden')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['orden_compra', 'codigo_producto', 'numero_pedido'], 'uk_indigo_item');
            $table->index('numero_pedido');
            $table->index('pedido_detalle_id');
        });

        // ═══════════════════════════════════════════════════════════════════
        // 5. Trazabilidad de sincronización Indigo
        // ═══════════════════════════════════════════════════════════════════
        $this->crearSiFalta('inv_indigo_trazabilidad', function (Blueprint $table) {
            $table->id();
            $table->string('numero_pedido', 50);
            $table->unsignedBigInteger('sucursal_id')->nullable();
            $table->string('estado_indigo', 50)->default('pendiente');
            $table->dateTime('fecha_sincronizacion')->nullable();
            $table->text('diferencias_pendientes')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->nullable();

            $table->index('numero_pedido');
            $table->index('sucursal_id');
        });

        // ═══════════════════════════════════════════════════════════════════
        // 6. Eventos de sincronización Indigo (log detallado)
        // ═══════════════════════════════════════════════════════════════════
        $this->crearSiFalta('inv_indigo_eventos', function (Blueprint $table) {
            $table->id();
            $table->string('numero_pedido', 50)->nullable();
            $table->string('orden_compra', 50)->nullable();
            $table->string('codigo_producto', 50)->nullable();
            $table->unsignedBigInteger('sucursal_id')->nullable();
            $table->string('nivel', 20)->default('info'); // info, warning, error
            $table->text('mensaje');
            $table->timestamp('created_at')->useCurrent();

            $table->index('numero_pedido');
            $table->index('sucursal_id');
            $table->index('created_at');
        });

        // ═══════════════════════════════════════════════════════════════════
        // 7. Almacenes (catálogo por sucursal)
        // ═══════════════════════════════════════════════════════════════════
        $this->crearSiFalta('inv_almacenes', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_almacen', 50)->unique();
            $table->string('nombre', 150);
            $table->string('sucursal', 150)->nullable();
            $table->string('empresa', 150)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->index('sucursal');
        });

        // ═══════════════════════════════════════════════════════════════════
        // 8. Niveles de inspección para muestreo GMP (ISO 2859-1)
        // ═══════════════════════════════════════════════════════════════════
        $this->crearSiFalta('inv_muestreo_niveles', function (Blueprint $table) {
            $table->id();
            $table->string('nivel_inspeccion', 10)->comment('I, II, III');
            $table->integer('lote_min');
            $table->integer('lote_max');
            $table->string('letra_codigo', 2);
            $table->integer('tamano_muestra');
            $table->boolean('activo')->default(true);
            $table->timestamp('created_at')->useCurrent();
        });

        // ═══════════════════════════════════════════════════════════════════
        // 9. Productos excluidos de muestreo (no requieren inspección)
        // ═══════════════════════════════════════════════════════════════════
        $this->crearSiFalta('inv_muestreo_exclusiones', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_producto', 50);
            $table->string('nombre_producto', 255)->nullable();
            $table->string('motivo', 255)->nullable()->comment('Razón de exclusión');
            $table->boolean('activo')->default(true);
            $table->timestamp('created_at')->useCurrent();

            $table->index('codigo_producto');
        });

        // ═══════════════════════════════════════════════════════════════════
        // 10. Columnas faltantes en inv_ordenes_compra
        // ═══════════════════════════════════════════════════════════════════
        Schema::table('inv_ordenes_compra', function (Blueprint $table) {
            if (!Schema::hasColumn('inv_ordenes_compra', 'proveedor_nombre')) {
                $table->string('proveedor_nombre', 255)->nullable()->after('observaciones');
            }
        });

        // ═══════════════════════════════════════════════════════════════════
        // 11. Columnas faltantes en inv_orden_compra_detalles
        // ═══════════════════════════════════════════════════════════════════
        Schema::table('inv_orden_compra_detalles', function (Blueprint $table) {
            if (!Schema::hasColumn('inv_orden_compra_detalles', 'codigo_producto_indigo')) {
                $table->string('codigo_producto_indigo', 50)->nullable()->after('compra_id');
            }
            if (!Schema::hasColumn('inv_orden_compra_detalles', 'producto_nombre')) {
                $table->string('producto_nombre', 255)->nullable()->after('codigo_producto_indigo');
            }
        });
    }

    public function down(): void
    {
        Schema::table('inv_orden_compra_detalles', function (Blueprint $table) {
            $table->dropColumn(['codigo_producto_indigo', 'producto_nombre']);
        });
        Schema::table('inv_ordenes_compra', function (Blueprint $table) {
            $table->dropColumn(['proveedor_nombre']);
        });

        Schema::dropIfExists('inv_muestreo_exclusiones');
        Schema::dropIfExists('inv_muestreo_niveles');
        Schema::dropIfExists('inv_almacenes');
        Schema::dropIfExists('inv_indigo_eventos');
        Schema::dropIfExists('inv_indigo_trazabilidad');
        Schema::dropIfExists('inv_indigo_items');
        Schema::dropIfExists('inv_compras_validacion_log');
        Schema::dropIfExists('inv_compras_auditoria');
        Schema::dropIfExists('inv_compras_pedidos');
    }
};
