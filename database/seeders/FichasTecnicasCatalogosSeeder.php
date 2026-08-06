<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\FichasTecnicas\EstadoFicha;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de estados del workflow de Fichas Técnicas.
 *
 * Los IDs se derivan del enum `EstadoFicha` para que el código de aplicación y
 * la base de datos no puedan desalinearse. Ver
 * `2026_08_05_000001_redesign_fich_estados_workflow` para el mapeo desde los
 * estados del sistema JADE legacy.
 *
 * Flujo de ficha:
 *   borrador → pendiente_autorizacion → pendiente_revision_financiera
 *            → aprobada → vigente
 *   (rechazo en cualquier nivel → correccion_requerida → reinicia flujo)
 */
class FichasTecnicasCatalogosSeeder extends Seeder
{
    /** Metadatos por estado: [descripción, orden en su flujo]. */
    private const META = [
        'borrador'                      => ['BORRADOR', 1],
        'pendiente_autorizacion'        => ['PENDIENTE DE AUTORIZACIÓN', 2],
        'correccion_requerida'          => ['CORRECCIÓN REQUERIDA', 3],
        'pendiente_revision_financiera' => ['PENDIENTE DE REVISIÓN FINANCIERA', 4],
        'aprobada'                      => ['APROBADA', 5],
        'vigente'                       => ['VIGENTE', 6],
        'cancelada'                     => ['CANCELADA', 7],

        'os_borrador'                      => ['ACTUALIZACIÓN — BORRADOR', 1],
        'os_pendiente_autorizacion'        => ['ACTUALIZACIÓN — PENDIENTE DE AUTORIZACIÓN', 2],
        'os_correccion_requerida'          => ['ACTUALIZACIÓN — CORRECCIÓN REQUERIDA', 3],
        'os_pendiente_revision_financiera' => ['ACTUALIZACIÓN — PENDIENTE REVISIÓN FINANCIERA', 4],
        'os_aprobada'                      => ['ACTUALIZACIÓN — APROBADA', 5],
        'os_vigente'                       => ['ACTUALIZACIÓN — VIGENTE', 6],
        'os_cancelada'                     => ['ACTUALIZACIÓN — CANCELADA', 7],
    ];

    public function run(): void
    {
        $ahora = now();
        $total = 0;

        foreach (EstadoFicha::cases() as $estado) {
            [$descripcion, $orden] = self::META[$estado->value];

            DB::table('fich_estados')->updateOrInsert(
                ['id' => $estado->id()],
                [
                    'codigo'          => $estado->value,
                    'descripcion'     => $descripcion,
                    'tipo'            => $estado->esActualizacion() ? 'actualizacion' : 'ficha',
                    'orden'           => $orden,
                    'color_hex'       => $estado->colorHex(),
                    'es_editable'     => $estado->esEditable(),
                    'es_final'        => $estado->esTerminal(),
                    'cuenta_vigencia' => $estado->cuentaVigencia(),
                    'estado'          => true,
                    'created_at'      => $ahora,
                    'updated_at'      => $ahora,
                ]
            );

            $total++;
        }

        $this->command?->info("✓ fich_estados: {$total} estados del workflow cargados");
    }
}
