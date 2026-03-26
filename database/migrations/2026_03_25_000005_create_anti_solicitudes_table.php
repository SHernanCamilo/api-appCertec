<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla principal de solicitudes de anticipo.
 *
 * Flujo de estados según los diagramas:
 *
 *   ANTICIPO:
 *   borrador → pendiente_jefe → pendiente_financiero → autorizado
 *           ↘ rechazado_jefe  ↘ rechazado_financiero
 *   autorizado → en_viaje → pendiente_legalizacion → legalizado → cerrado
 *
 *   REINTEGRO (cuando gasta menos del anticipo):
 *   legalizado → pendiente_reintegro → reintegrado → cerrado_tesoreria
 *
 *   EXCEDENTE (cuando gasta más del anticipo):
 *   legalizado → pendiente_excedente → [aprobado_excedente | rechazado_excedente] → cerrado
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anti_solicitudes', function (Blueprint $table) {
            $table->id();
            $table->string('numero_solicitud', 30)->unique()->comment('Consecutivo legible: ANT-2026-00001');

            // Quién viaja
            $table->unsignedBigInteger('id_empleado')->comment('config_person_tercero.id');
            $table->unsignedBigInteger('id_unidad_funcional');
            $table->unsignedBigInteger('id_sede_origen');

            // Destino
            $table->unsignedBigInteger('id_ciudad_destino');
            $table->date('fecha_salida');
            $table->date('fecha_regreso');
            $table->text('motivo');

            // Clasificación del viaje (determina qué reglas aplican)
            $table->enum('cobertura', ['nacional', 'internacional'])
                  ->comment('Nacional (MA/NAL) o Internacional');

            // Montos
            $table->decimal('monto_solicitado', 15, 2)->default(0);
            $table->decimal('monto_autorizado', 15, 2)->nullable();
            $table->decimal('monto_legalizado', 15, 2)->nullable()->comment('Lo que realmente gastó');
            $table->decimal('monto_reintegro', 15, 2)->nullable()->comment('Sobrante a devolver');
            $table->decimal('monto_excedente', 15, 2)->nullable()->comment('Gasto extra sobre lo autorizado');

            // Estado del flujo
            $table->enum('estado', [
                'borrador',
                'pendiente_jefe',
                'rechazado_jefe',
                'pendiente_financiero',
                'rechazado_financiero',
                'autorizado',
                'en_viaje',
                'pendiente_legalizacion',
                'legalizado',
                'pendiente_reintegro',
                'reintegrado',
                'pendiente_excedente',
                'aprobado_excedente',
                'rechazado_excedente',
                'cerrado',
            ])->default('borrador');

            // Quién radica (Asistente/Solicitante)
            $table->unsignedBigInteger('radicado_por')->comment('users.id');

            // Observaciones
            $table->text('observaciones')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('id_empleado')->references('id')->on('config_person_tercero')->onDelete('restrict');
            $table->foreign('id_unidad_funcional')->references('id')->on('anti_unidades_funcionales')->onDelete('restrict');
            $table->foreign('id_sede_origen')->references('id')->on('config_ubi_sede')->onDelete('restrict');
            $table->foreign('id_ciudad_destino')->references('id')->on('anti_ciudades')->onDelete('restrict');
            $table->foreign('radicado_por')->references('id')->on('users')->onDelete('restrict');

            $table->index(['id_empleado', 'estado']);
            $table->index(['id_unidad_funcional', 'estado']);
            $table->index('estado');
            $table->index('fecha_salida');
        });

        /**
         * Detalle de conceptos dentro de una solicitud.
         * Cada ítem referencia el concepto parametrizado y guarda el monto calculado.
         */
        Schema::create('anti_solicitud_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_solicitud');
            $table->unsignedBigInteger('id_concepto');
            $table->unsignedBigInteger('id_regla')->comment('Regla aplicada según nivel del empleado');
            $table->string('descripcion', 255)->nullable()->comment('Sub-ítem: Desayuno, Almuerzo, etc.');
            $table->integer('cantidad')->default(1);
            $table->decimal('valor_unitario', 15, 2);
            $table->decimal('valor_total', 15, 2);
            $table->timestamps();

            $table->foreign('id_solicitud')->references('id')->on('anti_solicitudes')->onDelete('cascade');
            $table->foreign('id_concepto')->references('id')->on('anti_conceptos')->onDelete('restrict');
            $table->foreign('id_regla')->references('id')->on('anti_reglas')->onDelete('restrict');

            $table->index('id_solicitud');
        });

        /**
         * Historial de aprobaciones/rechazos por solicitud.
         * Trazabilidad completa del flujo.
         */
        Schema::create('anti_solicitud_aprobaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_solicitud');
            $table->unsignedBigInteger('user_id')->comment('Quien aprobó/rechazó');
            $table->enum('rol_aprobador', ['jefe_inmediato', 'financiero', 'tesoreria', 'contabilidad']);
            $table->enum('accion', ['aprobado', 'rechazado', 'observacion']);
            $table->text('comentario')->nullable();
            $table->decimal('monto_autorizado', 15, 2)->nullable()->comment('Solo en aprobación financiera');
            $table->timestamp('fecha_accion');
            $table->timestamps();

            $table->foreign('id_solicitud')->references('id')->on('anti_solicitudes')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');

            $table->index(['id_solicitud', 'rol_aprobador']);
        });

        /**
         * Documentos adjuntos a la solicitud (PDFs, facturas electrónicas).
         */
        Schema::create('anti_solicitud_documentos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_solicitud');
            $table->enum('tipo_documento', ['soporte_solicitud', 'factura', 'recibo_caja', 'comprobante_reintegro', 'otro']);
            $table->string('nombre_archivo', 255);
            $table->string('ruta_archivo', 500);
            $table->unsignedBigInteger('subido_por');
            $table->timestamps();

            $table->foreign('id_solicitud')->references('id')->on('anti_solicitudes')->onDelete('cascade');
            $table->foreign('subido_por')->references('id')->on('users')->onDelete('restrict');

            $table->index('id_solicitud');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anti_solicitud_documentos');
        Schema::dropIfExists('anti_solicitud_aprobaciones');
        Schema::dropIfExists('anti_solicitud_items');
        Schema::dropIfExists('anti_solicitudes');
    }
};
