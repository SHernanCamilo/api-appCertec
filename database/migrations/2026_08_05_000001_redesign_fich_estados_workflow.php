<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 1 — Rediseño del workflow de Fichas Técnicas.
 *
 * 1. Reemplaza los 12 estados heredados del legacy por 14 (7 ficha + 7 OS)
 *    con semántica explícita.
 * 2. Remapea las fichas existentes al nuevo esquema de IDs.
 * 3. Vincula `fich_fichas` con el motor de flujos (`wf_instancias`).
 * 4. Agrega columnas de trazabilidad de vigencia y reinicio de flujo.
 *
 * Mapeo legacy → nuevo:
 *   1  borrador                  → 1  borrador
 *   2  generada                  → 2  pendiente_autorizacion
 *   3  autorizada                → 4  pendiente_revision_financiera
 *   4  por_aprobar               → 4  pendiente_revision_financiera
 *   5  finalizada                → 5 aprobada | 6 vigente (según fecha_ini)
 *   6  rechazada                 → 3  correccion_requerida
 *   7  cancelada                 → 7  cancelada
 *   8  actualizacion_generada    → 8  os_borrador
 *   9  actualizacion_en_proceso  → 9  os_pendiente_autorizacion
 *   10 actualizacion_autorizada  → 11 os_pendiente_revision_financiera
 *   11 actualizacion_finalizada  → 12 os_aprobada | 13 os_vigente
 *   12 actualizacion_rechazada   → 10 os_correccion_requerida
 */
return new class extends Migration
{
    /** Estados nuevos: [id, codigo, descripcion, tipo, orden, color, editable, final, vigencia] */
    private const ESTADOS = [
        [1,  'borrador',                      'BORRADOR',                                'ficha',         1, '#6c757d', true,  false, false],
        [2,  'pendiente_autorizacion',        'PENDIENTE DE AUTORIZACIÓN',               'ficha',         2, '#0dcaf0', false, false, false],
        [3,  'correccion_requerida',          'CORRECCIÓN REQUERIDA',                    'ficha',         3, '#dc3545', true,  false, false],
        [4,  'pendiente_revision_financiera', 'PENDIENTE DE REVISIÓN FINANCIERA',        'ficha',         4, '#6610f2', false, false, false],
        [5,  'aprobada',                      'APROBADA',                                'ficha',         5, '#20c997', false, false, true],
        [6,  'vigente',                       'VIGENTE',                                 'ficha',         6, '#198754', false, true,  true],
        [7,  'cancelada',                     'CANCELADA',                               'ficha',         7, '#343a40', false, true,  false],
        [8,  'os_borrador',                      'ACTUALIZACIÓN — BORRADOR',                       'actualizacion', 1, '#6c757d', true,  false, false],
        [9,  'os_pendiente_autorizacion',        'ACTUALIZACIÓN — PENDIENTE DE AUTORIZACIÓN',      'actualizacion', 2, '#0dcaf0', false, false, false],
        [10, 'os_correccion_requerida',          'ACTUALIZACIÓN — CORRECCIÓN REQUERIDA',           'actualizacion', 3, '#dc3545', true,  false, false],
        [11, 'os_pendiente_revision_financiera', 'ACTUALIZACIÓN — PENDIENTE REVISIÓN FINANCIERA',  'actualizacion', 4, '#6610f2', false, false, false],
        [12, 'os_aprobada',                      'ACTUALIZACIÓN — APROBADA',                       'actualizacion', 5, '#20c997', false, false, true],
        [13, 'os_vigente',                       'ACTUALIZACIÓN — VIGENTE',                        'actualizacion', 6, '#198754', false, true,  true],
        [14, 'os_cancelada',                     'ACTUALIZACIÓN — CANCELADA',                      'actualizacion', 7, '#343a40', false, true,  false],
    ];

    public function up(): void
    {
        // ── 1. Columnas nuevas en fich_fichas ────────────────────────────
        Schema::table('fich_fichas', function (Blueprint $table): void {
            if (! Schema::hasColumn('fich_fichas', 'wf_instancia_id')) {
                $table->unsignedBigInteger('wf_instancia_id')->nullable()->after('id_estado')
                    ->comment('Instancia activa en el motor de flujos (wf_instancias)');
            }

            if (! Schema::hasColumn('fich_fichas', 'fecha_envio_flujo')) {
                $table->dateTime('fecha_envio_flujo')->nullable()->after('fecha_reg')
                    ->comment('Última vez que el generador envió la ficha a autorización');
            }

            if (! Schema::hasColumn('fich_fichas', 'ciclos_flujo')) {
                $table->unsignedSmallInteger('ciclos_flujo')->default(0)->after('fecha_envio_flujo')
                    ->comment('Veces que la ficha ha recorrido el flujo (reinicios por corrección)');
            }

            if (! Schema::hasColumn('fich_fichas', 'fecha_vigencia_inicio')) {
                $table->dateTime('fecha_vigencia_inicio')->nullable()->after('fecha_aprueba')
                    ->comment('Momento en que la ficha pasó a estado vigente');
            }

            if (! Schema::hasColumn('fich_fichas', 'reemplazada_por_id')) {
                $table->unsignedBigInteger('reemplazada_por_id')->nullable()->after('id_padre')
                    ->comment('Versión (OS) que reemplaza a esta ficha una vez aprobada');
            }

            if (! Schema::hasColumn('fich_fichas', 'motivo_modificacion')) {
                $table->string('motivo_modificacion', 500)->nullable()->after('obs_os')
                    ->comment('Motivo por el que el generador solicitó modificar una ficha vigente');
            }
        });

        Schema::table('fich_fichas', function (Blueprint $table): void {
            $table->index('wf_instancia_id', 'idx_ffic_wf_instancia');
            $table->index('reemplazada_por_id', 'idx_ffic_reemplazada');
        });

        if (Schema::hasTable('wf_instancias')) {
            Schema::table('fich_fichas', function (Blueprint $table): void {
                $table->foreign('wf_instancia_id', 'fk_ffic_wf_instancia')
                    ->references('id')->on('wf_instancias')->nullOnDelete();
            });
        }

        Schema::table('fich_fichas', function (Blueprint $table): void {
            $table->foreign('reemplazada_por_id', 'fk_ffic_reemplazada')
                ->references('id')->on('fich_fichas')->nullOnDelete();
        });

        // ── 2. Remapear las fichas existentes ────────────────────────────
        // Se hace antes de reescribir el catálogo para no violar la FK.
        $this->remapearFichas();

        // ── 3. Reescribir el catálogo de estados ─────────────────────────
        $this->sembrarEstados();
    }

    /**
     * Remapea `fich_fichas.id_estado` del esquema legacy al nuevo.
     *
     * Los estados 5 (finalizada) y 11 (actualización finalizada) se separan en
     * `aprobada` / `vigente` según si la vigencia ya arrancó, que es la
     * distinción que el legacy no hacía.
     */
    private function remapearFichas(): void
    {
        if (! Schema::hasTable('fich_fichas')) {
            return;
        }

        // Los IDs 8..12 del esquema viejo colisionan con los nuevos 8..14.
        // Se desplazan a un rango temporal alto para evitar ambigüedad.
        $temporal = [
            8  => 908,
            9  => 909,
            10 => 910,
            11 => 911,
            12 => 912,
        ];

        // El catálogo debe admitir los IDs temporales mientras se remapea.
        foreach ($temporal as $viejo => $tmp) {
            DB::table('fich_estados')->where('id', $viejo)->update(['id' => $tmp]);
            DB::table('fich_fichas')->where('id_estado', $viejo)->update(['id_estado' => $tmp]);
            DB::table('fich_comentarios')->where('id_estado', $viejo)->update(['id_estado' => $tmp]);
        }

        // Flujo de ficha original.
        $this->mover(2, 2);   // generada        → pendiente_autorizacion
        $this->mover(3, 4);   // autorizada      → pendiente_revision_financiera
        $this->mover(4, 4);   // por_aprobar     → pendiente_revision_financiera
        $this->mover(6, 3);   // rechazada       → correccion_requerida

        // finalizada → vigente si la vigencia ya arrancó, aprobada si no.
        DB::table('fich_fichas')
            ->where('id_estado', 5)
            ->whereRaw('fecha_ini <= CURDATE()')
            ->update(['id_estado' => 6, 'fecha_vigencia_inicio' => DB::raw('fecha_aprueba')]);
        // El resto de las 5 se queda en 5 (aprobada), que ya es el ID correcto.

        // Flujo de actualización (OS), desde el rango temporal.
        $this->mover(908, 8);   // actualizacion_generada    → os_borrador
        $this->mover(909, 9);   // actualizacion_en_proceso  → os_pendiente_autorizacion
        $this->mover(910, 11);  // actualizacion_autorizada  → os_pendiente_revision_financiera
        $this->mover(912, 10);  // actualizacion_rechazada   → os_correccion_requerida

        DB::table('fich_fichas')
            ->where('id_estado', 911)
            ->whereRaw('fecha_ini <= CURDATE()')
            ->update(['id_estado' => 13, 'fecha_vigencia_inicio' => DB::raw('fecha_aprueba')]);

        $this->mover(911, 12);  // el resto → os_aprobada
    }

    /** Reasigna todas las referencias de un estado a otro. */
    private function mover(int $desde, int $hacia): void
    {
        if ($desde === $hacia) {
            return;
        }

        DB::table('fich_fichas')->where('id_estado', $desde)->update(['id_estado' => $hacia]);
        DB::table('fich_comentarios')->where('id_estado', $desde)->update(['id_estado' => $hacia]);
        DB::table('fich_historial_estados')->where('id_estado_anterior', $desde)->update(['id_estado_anterior' => $hacia]);
        DB::table('fich_historial_estados')->where('id_estado_nuevo', $desde)->update(['id_estado_nuevo' => $hacia]);
    }

    /** Escribe el catálogo completo de estados nuevos. */
    private function sembrarEstados(): void
    {
        $ahora = now();

        foreach (self::ESTADOS as [$id, $codigo, $descripcion, $tipo, $orden, $color, $editable, $final, $vigencia]) {
            DB::table('fich_estados')->updateOrInsert(
                ['id' => $id],
                [
                    'codigo'          => $codigo,
                    'descripcion'     => $descripcion,
                    'tipo'            => $tipo,
                    'orden'           => $orden,
                    'color_hex'       => $color,
                    'es_editable'     => $editable,
                    'es_final'        => $final,
                    'cuenta_vigencia' => $vigencia,
                    'estado'          => true,
                    'updated_at'      => $ahora,
                    'created_at'      => $ahora,
                ]
            );
        }

        // Elimina los IDs temporales y cualquier estado legacy sin uso.
        $idsValidos = array_column(self::ESTADOS, 0);

        DB::table('fich_estados')
            ->whereNotIn('id', $idsValidos)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('fich_fichas')
                    ->whereColumn('fich_fichas.id_estado', 'fich_estados.id');
            })
            ->delete();
    }

    public function down(): void
    {
        Schema::table('fich_fichas', function (Blueprint $table): void {
            if (Schema::hasColumn('fich_fichas', 'wf_instancia_id')) {
                $table->dropForeign('fk_ffic_wf_instancia');
                $table->dropIndex('idx_ffic_wf_instancia');
            }

            if (Schema::hasColumn('fich_fichas', 'reemplazada_por_id')) {
                $table->dropForeign('fk_ffic_reemplazada');
                $table->dropIndex('idx_ffic_reemplazada');
            }
        });

        Schema::table('fich_fichas', function (Blueprint $table): void {
            $table->dropColumn(array_values(array_filter([
                'wf_instancia_id',
                'fecha_envio_flujo',
                'ciclos_flujo',
                'fecha_vigencia_inicio',
                'reemplazada_por_id',
                'motivo_modificacion',
            ], static fn (string $c): bool => Schema::hasColumn('fich_fichas', $c))));
        });
    }
};
