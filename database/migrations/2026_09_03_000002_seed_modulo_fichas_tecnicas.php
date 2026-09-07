<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registra el módulo "Fichas Técnicas" en el sistema propio de módulos
 * (`seg_modulos`), colgando de "Contabilidad" (código CONT), junto con sus
 * permisos (`seg_permisos`) y la asignación a todas las empresas
 * (`seg_modulo_empresa`).
 *
 * Este es el registro que alimenta la pantalla "Gestión de Módulos" y el menú
 * lateral de Contabilidad. Es independiente de:
 *   - Los roles Spatie del flujo (seg_roles: generador/autorizador/aprobador…),
 *     que crea FichasTecnicasModuloSeeder.
 *   - El módulo del motor de flujos (wf_modulos), que crea FichasTecnicasWorkflowSeeder.
 *
 * Permisos alineados al flujo del proceso:
 *   Asistente Administrativo GENERA → Director Médico AUTORIZA → VP Financiera APRUEBA
 * con rechazo (+motivo) en cada nivel.
 *
 * Idempotente: usa updateOrInsert por `codigo`, se puede re-ejecutar sin duplicar.
 */
return new class extends Migration
{
    private const MODULO_PADRE = 'CONT';

    /** Submódulos: [codigo, nombre, ruta, icono, orden]. */
    private const SUBMODULOS = [
        ['CONT-FICHAS',        'Fichas Técnicas',        '/contabilidad/fichas-tecnicas',            'bi bi-file-medical', 1],
        ['CONT-FICHAS-GEN',    'Generar Ficha',          '/contabilidad/fichas-tecnicas/formulario', 'bi bi-file-earmark-plus', 1],
        ['CONT-FICHAS-BANDEJA','Bandeja de Fichas',      '/contabilidad/fichas-tecnicas/bandeja/borradores', 'bi bi-inboxes', 2],
        ['CONT-FICHAS-PARAM',  'Parámetros de Fichas',   '/contabilidad/fichas-tecnicas/parametros', 'bi bi-sliders', 3],
        ['CONT-FICHAS-CUPS',   'Buscador CUPS',          '/contabilidad/fichas-tecnicas/cups',       'bi bi-search', 4],
    ];

    /**
     * Permisos por submódulo: [submodulo_codigo, permiso_codigo, nombre, tipo, icono, orden].
     *
     * Distribución por dónde se ejerce cada acción:
     *   CONT-FICHAS         → visible (menú de navegación raíz)
     *   CONT-FICHAS-GEN     → generar, editar, enviar, solicitar-modif, cancelar, pdf
     *   CONT-FICHAS-BANDEJA → ver, autorizar, aprobar, rechazar
     *   CONT-FICHAS-PARAM   → parametrizar
     *   CONT-FICHAS-CUPS    → buscar CUPS
     * Cada submódulo recibe además su propio "Visible en Menú".
     */
    private const PERMISOS = [
        // ── Raíz ──
        ['CONT-FICHAS',         'fichas-visible',          'Visible en Menú',               'menu',   'bi bi-list',              0],

        // ── Generar Ficha ──
        ['CONT-FICHAS-GEN',     'fichas-generar',          'Generar ficha',                 'boton',  'bi bi-plus-circle',       1],
        ['CONT-FICHAS-GEN',     'fichas-editar',           'Editar borrador',               'boton',  'bi bi-pencil',            2],
        ['CONT-FICHAS-GEN',     'fichas-enviar',           'Enviar a autorización',         'boton',  'bi bi-send',              3],
        ['CONT-FICHAS-GEN',     'fichas-solicitar-modif',  'Solicitar modificación (OS)',   'boton',  'bi bi-arrow-repeat',      4],
        ['CONT-FICHAS-GEN',     'fichas-cancelar',         'Cancelar ficha',                'boton',  'bi bi-slash-circle',      5],
        ['CONT-FICHAS-GEN',     'fichas-pdf',              'Descargar PDF',                 'accion', 'bi bi-file-pdf',          6],
        ['CONT-FICHAS-GEN',     'fichas-gen-visible',      'Visible en Menú',               'menu',   'bi bi-file-earmark-plus', 0],

        // ── Bandeja de Fichas ──
        ['CONT-FICHAS-BANDEJA', 'fichas-ver',              'Ver fichas',                    'accion', 'bi bi-eye',               1],
        ['CONT-FICHAS-BANDEJA', 'fichas-autorizar',        'Autorizar (Dirección Médica)',  'boton',  'bi bi-check2-circle',     2],
        ['CONT-FICHAS-BANDEJA', 'fichas-aprobar',          'Aprobar (VP Financiera)',       'boton',  'bi bi-check2-all',        3],
        ['CONT-FICHAS-BANDEJA', 'fichas-rechazar',         'Rechazar con motivo',           'boton',  'bi bi-x-circle',         4],
        ['CONT-FICHAS-BANDEJA', 'fichas-bandeja-visible',  'Visible en Menú',               'menu',   'bi bi-inboxes',          0],

        // ── Parámetros de Fichas ──
        ['CONT-FICHAS-PARAM',   'fichas-parametrizar',     'Administrar catálogos',         'accion', 'bi bi-gear',              1],
        ['CONT-FICHAS-PARAM',   'fichas-param-visible',    'Visible en Menú',               'menu',   'bi bi-sliders',           0],

        // ── Buscador CUPS ──
        ['CONT-FICHAS-CUPS',    'fichas-cups-buscar',      'Buscar CUPS y Homólogos',       'accion', 'bi bi-search',            1],
        ['CONT-FICHAS-CUPS',    'fichas-cups-visible',     'Visible en Menú',               'menu',   'bi bi-search',            0],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('seg_modulos')) {
            return;
        }

        $padre = DB::table('seg_modulos')->where('codigo', self::MODULO_PADRE)->first();

        if ($padre === null) {
            // Sin el módulo Contabilidad no hay dónde colgar fichas; se omite.
            return;
        }

        $ahora = now();

        // ── 1. Módulo raíz de fichas (hijo de Contabilidad) ──────────────
        [$codRaiz, $nomRaiz, $rutaRaiz, $icoRaiz, $ordRaiz] = self::SUBMODULOS[0];

        DB::table('seg_modulos')->updateOrInsert(
            ['codigo' => $codRaiz],
            [
                'id_modulo_padre' => $padre->id,
                'nombre'          => $nomRaiz,
                'descripcion'     => 'Gestión de fichas técnicas de contratación de servicios médicos '
                    .'con agremiaciones y profesionales de la salud.',
                'icono'           => $icoRaiz,
                'ruta'            => $rutaRaiz,
                'orden'           => $ordRaiz,
                'nivel'           => (int) $padre->nivel + 1,
                'estado'          => 1,
                'updated_at'      => $ahora,
                'created_at'      => $ahora,
            ]
        );

        $raiz = DB::table('seg_modulos')->where('codigo', $codRaiz)->first();

        // ── 2. Submódulos (pantallas) colgando del raíz de fichas ────────
        foreach (array_slice(self::SUBMODULOS, 1) as [$cod, $nom, $ruta, $ico, $ord]) {
            DB::table('seg_modulos')->updateOrInsert(
                ['codigo' => $cod],
                [
                    'id_modulo_padre' => $raiz->id,
                    'nombre'          => $nom,
                    'descripcion'     => null,
                    'icono'           => $ico,
                    'ruta'            => $ruta,
                    'orden'           => $ord,
                    'nivel'           => (int) $raiz->nivel + 1,
                    'estado'          => 1,
                    'updated_at'      => $ahora,
                    'created_at'      => $ahora,
                ]
            );
        }

        // ── 3. Permisos distribuidos por submódulo ───────────────────────
        // Cada permiso indica en su primera columna el código del módulo al que pertenece.
        // Se resuelven los IDs una sola vez para no repetir queries.
        if (Schema::hasTable('seg_permisos')) {
            $idsPorCodigo = DB::table('seg_modulos')
                ->whereIn('codigo', array_unique(array_column(self::PERMISOS, 0)))
                ->pluck('id', 'codigo')
                ->all();

            foreach (self::PERMISOS as [$submoduloCod, $cod, $nom, $tipo, $ico, $ord]) {
                $idModulo = $idsPorCodigo[$submoduloCod] ?? $raiz->id;

                DB::table('seg_permisos')->updateOrInsert(
                    ['codigo' => $cod],
                    [
                        'id_modulo'   => $idModulo,
                        'nombre'      => $nom,
                        'descripcion' => null,
                        'tipo'        => $tipo,
                        'icono'       => $ico,
                        'orden'       => $ord,
                        'estado'      => 1,
                        'updated_at'  => $ahora,
                        'created_at'  => $ahora,
                    ]
                );
            }
        }

        // ── 4. Asignar el módulo a todas las empresas (con herencia) ─────
        if (Schema::hasTable('seg_modulo_empresa') && Schema::hasTable('ent_empresas')) {
            $empresas = DB::table('ent_empresas')->pluck('id');

            foreach ($empresas as $idEmpresa) {
                DB::table('seg_modulo_empresa')->updateOrInsert(
                    ['id_modulo' => $raiz->id, 'id_empresa' => $idEmpresa],
                    [
                        'activo'       => 1,
                        'hereda_hijos' => 1,
                        'updated_at'   => $ahora,
                        'created_at'   => $ahora,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('seg_modulos')) {
            return;
        }

        $codigos = array_column(self::SUBMODULOS, 0);

        $raiz = DB::table('seg_modulos')->where('codigo', 'CONT-FICHAS')->first();

        if ($raiz !== null && Schema::hasTable('seg_permisos')) {
            DB::table('seg_permisos')->where('id_modulo', $raiz->id)->delete();
        }

        if ($raiz !== null && Schema::hasTable('seg_modulo_empresa')) {
            DB::table('seg_modulo_empresa')->where('id_modulo', $raiz->id)->delete();
        }

        // Los hijos caen en cascada por la FK, pero se borran explícitamente por claridad.
        DB::table('seg_modulos')->whereIn('codigo', $codigos)->delete();
    }
};
