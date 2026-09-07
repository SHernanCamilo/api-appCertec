<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración correctiva idempotente del motor de flujos.
 *
 * La migración 2026_06_02_000001_enhance_workflow_engine quedó registrada como
 * ejecutada en `jadeonedevs` pero solo aplicó parte de sus columnas: agregó
 * `wf_pasos.reglas` y `wf_aprobadores.id_grupo`, pero no `wf_pasos.descripcion_contexto`
 * ni los campos de contexto/auditoría de `wf_instancias` (el ALTER combinado
 * falló a mitad y dejó el esquema a medias).
 *
 * Esta migración completa lo que faltó, verificando cada columna antes de
 * agregarla, de modo que es segura de re-ejecutar en cualquier entorno,
 * independientemente de cuánto avanzó la migración original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wf_pasos', function (Blueprint $table): void {
            if (! Schema::hasColumn('wf_pasos', 'reglas')) {
                $table->json('reglas')->nullable()->after('requiere_monto');
            }
            if (! Schema::hasColumn('wf_pasos', 'descripcion_contexto')) {
                $table->text('descripcion_contexto')->nullable()->after('reglas');
            }
        });

        if (! Schema::hasColumn('wf_aprobadores', 'id_grupo')) {
            Schema::table('wf_aprobadores', function (Blueprint $table): void {
                $table->unsignedBigInteger('id_grupo')->nullable()->after('prefijo_sucursal');
                $table->foreign('id_grupo')->references('id')->on('wf_grupos')->nullOnDelete();
            });
        }

        Schema::table('wf_instancias', function (Blueprint $table): void {
            if (! Schema::hasColumn('wf_instancias', 'solicitante_id')) {
                $table->unsignedBigInteger('solicitante_id')->nullable()->after('modulo_record_id');
            }
            if (! Schema::hasColumn('wf_instancias', 'contexto')) {
                $table->json('contexto')->nullable()->after('solicitante_id');
            }
            if (! Schema::hasColumn('wf_instancias', 'consecutivo')) {
                $table->string('consecutivo')->nullable()->after('contexto');
            }
            if (! Schema::hasColumn('wf_instancias', 'fecha_completado')) {
                $table->timestamp('fecha_completado')->nullable()->after('updated_at');
            }
            if (! Schema::hasColumn('wf_instancias', 'fecha_rechazado')) {
                $table->timestamp('fecha_rechazado')->nullable()->after('fecha_completado');
            }
        });

        // La FK de solicitante_id se agrega por separado para poder verificar
        // que la columna quedó creada sin arrastrar el estado del bloque anterior.
        if (Schema::hasColumn('wf_instancias', 'solicitante_id') && ! $this->foreignKeyExiste('wf_instancias', 'wf_instancias_solicitante_id_foreign')) {
            Schema::table('wf_instancias', function (Blueprint $table): void {
                $table->foreign('solicitante_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // No revierte: son columnas que la migración original ya debía haber
        // creado. Su rollback lo maneja enhance_workflow_engine.
    }

    private function foreignKeyExiste(string $tabla, string $nombre): bool
    {
        $fila = \Illuminate\Support\Facades\DB::selectOne(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = "FOREIGN KEY"',
            [$tabla, $nombre]
        );

        return $fila !== null;
    }
};
