<?php

declare(strict_types=1);

namespace App\Console\Commands\FichasTecnicas;

use App\DTO\FichasTecnicas\MoneyParser;
use App\Enums\FichasTecnicas\EstadoFicha;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Migra los datos del sistema JADE legacy (BD `fichas`) al esquema `fich_*`.
 *
 * Uso:
 *   php artisan fichas:migrar-datos --dry-run
 *   php artisan fichas:migrar-datos --solo=catalogos
 *   php artisan fichas:migrar-datos --truncate
 *
 * Requiere una conexión `jade_legacy` en `config/database.php`.
 *
 * Estrategia:
 *  - Se conservan los IDs originales para que las FK del legacy sigan siendo
 *    válidas sin tablas de traducción.
 *  - Los usuarios se mapean por `usuarios.correo` → `users.email`. Los que no
 *    coincidan se reportan y su ficha se asigna al usuario de respaldo
 *    (`--usuario-respaldo`).
 *  - Los triggers de contadores se desactivan durante la carga y al final se
 *    ejecuta `sp_fich_recalcular_totales` sobre cada ficha, para evitar el
 *    coste de 37.000 UPDATE incrementales.
 */
class MigrarDatosJadeCommand extends Command
{
    protected $signature = 'fichas:migrar-datos
        {--conexion=jade_legacy : Nombre de la conexión a la BD legacy}
        {--solo= : catalogos|tarifarios|fichas (por defecto todo)}
        {--truncate : Vacía las tablas fich_* antes de migrar}
        {--dry-run : Solo reporta conteos, no escribe}
        {--usuario-respaldo= : ID de users para fichas cuyo usuario legacy no se pueda mapear}
        {--chunk=1000 : Tamaño de lote para las tablas grandes}';

    protected $description = 'Migra los datos del sistema JADE legacy al esquema fich_* de api-appCertec';

    private Connection $legacy;

    /** @var array<int, int> mapa usuario_legacy_id → users.id */
    private array $mapaUsuarios = [];

    private int $usuarioRespaldo = 0;

    /** @var list<string> */
    private array $advertencias = [];

    public function handle(): int
    {
        try {
            $this->legacy = DB::connection((string) $this->option('conexion'));
            $this->legacy->getPdo();
        } catch (Throwable $e) {
            $this->error('No se pudo conectar a la BD legacy: '.$e->getMessage());
            $this->line('');
            $this->line('Agregue en config/database.php dentro de "connections":');
            $this->line(<<<'PHP'

                'jade_legacy' => [
                    'driver'   => 'mysql',
                    'host'     => env('JADE_LEGACY_HOST', '127.0.0.1'),
                    'port'     => env('JADE_LEGACY_PORT', '3306'),
                    'database' => env('JADE_LEGACY_DATABASE', 'fichas'),
                    'username' => env('JADE_LEGACY_USERNAME', 'root'),
                    'password' => env('JADE_LEGACY_PASSWORD', ''),
                    'charset'  => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                    'strict'   => false,
                ],
                PHP);

            return self::FAILURE;
        }

        $this->info('Origen: '.$this->legacy->getDatabaseName().'  →  Destino: '.DB::getDatabaseName());
        $this->newLine();

        if ($this->option('dry-run')) {
            return $this->reportarConteos();
        }

        if ($this->option('truncate') && ! $this->confirmarTruncate()) {
            return self::FAILURE;
        }

        $solo = (string) ($this->option('solo') ?? '');

        try {
            $this->sinTriggers(function () use ($solo): void {
                if ($this->option('truncate')) {
                    $this->truncarDestino();
                }

                if ($solo === '' || $solo === 'catalogos') {
                    $this->migrarCatalogos();
                }

                if ($solo === '' || $solo === 'tarifarios') {
                    $this->migrarTarifarios();
                }

                if ($solo === '' || $solo === 'fichas') {
                    $this->prepararMapaUsuarios();
                    $this->migrarFichas();
                }
            });

            $this->recalcularTotales();
            $this->verificarIntegridad();
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Migración interrumpida: '.$e->getMessage());
            $this->line($e->getFile().':'.$e->getLine());

            return self::FAILURE;
        }

        if ($this->advertencias !== []) {
            $this->newLine();
            $this->warn('Advertencias ('.count($this->advertencias).'):');
            foreach (array_slice($this->advertencias, 0, 25) as $advertencia) {
                $this->line('  · '.$advertencia);
            }
            if (count($this->advertencias) > 25) {
                $this->line('  · … y '.(count($this->advertencias) - 25).' más');
            }
        }

        $this->newLine();
        $this->info('Migración completada.');

        return self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Catálogos
    // ─────────────────────────────────────────────────────────────────────

    private function migrarCatalogos(): void
    {
        $this->line('── Catálogos ──');

        $this->migrarTabla('objetos_contrato', 'fich_objetos_contrato', fn (object $r): array => [
            'id'          => (int) $r->id,
            'descripcion' => $this->limpiar($r->descripcion) ?? 'SIN DESCRIPCIÓN',
            'estado'      => true,
        ]);

        $this->migrarTabla('tipos_servicios', 'fich_tipos_servicio', fn (object $r): array => [
            'id'          => (int) $r->id,
            'descripcion' => $this->limpiar($r->descripcion) ?? 'SIN DESCRIPCIÓN',
            'estado'      => true,
        ]);

        $this->migrarTabla('especialidades', 'fich_especialidades', fn (object $r): array => [
            'id'          => (int) $r->id,
            'descripcion' => $this->limpiar($r->descripcion) ?? 'SIN DESCRIPCIÓN',
            'perfil'      => $this->limpiar($r->perfil ?? null),
            'estado'      => $this->aBool($r->estado ?? 1),
        ]);

        $this->migrarTabla('agremiaciones', 'fich_agremiaciones', fn (object $r): array => [
            'id'           => (int) $r->id,
            'nombre'       => $this->limpiar($r->nombre) ?? 'SIN NOMBRE',
            'nit'          => $this->limpiar((string) ($r->nit ?? '')) ?: null,
            'rep_legal'    => $this->limpiar($r->rep_legal ?? null),
            'cc_rep_legal' => $this->limpiar((string) ($r->cc_rep_legal ?? '')) ?: null,
            'direccion'    => $this->limpiar($r->direccion ?? null),
            'telefono'     => $this->limpiar((string) ($r->telefono ?? '')) ?: null,
            'estado'       => $this->aBool($r->estado ?? 1),
        ]);

        $this->migrarTabla('profesionales', 'fich_profesionales', fn (object $r): array => [
            'id'                  => (int) $r->id,
            'documento'           => $this->limpiar((string) ($r->doc ?? '')) ?: 'SIN-DOC-'.$r->id,
            'nombre'              => $this->limpiar($r->nombre) ?? 'SIN NOMBRE',
            'tarjeta_profesional' => $this->limpiar($r->tar_prof ?? null),
            'estado'              => $this->aBool($r->estado ?? 1),
        ]);

        $this->migrarTabla('inter_prof_esp', 'fich_profesional_especialidad', fn (object $r): array => [
            'id'              => (int) $r->id,
            'id_profesional'  => (int) $r->id_prof,
            'id_especialidad' => (int) $r->id_esp,
        ], 'id_prof IS NOT NULL AND id_esp IS NOT NULL');

        $this->migrarTabla('obs_detalles_ficha', 'fich_obs_items', fn (object $r): array => [
            'id'          => (int) $r->id,
            'descripcion' => $this->limpiar($r->descripcion) ?? 'SIN DESCRIPCIÓN',
            'estado'      => $this->aBool($r->estado ?? 1),
        ]);

        if ($this->tablaLegacyExiste('obs_servicio_detalle')) {
            $this->migrarTabla('obs_servicio_detalle', 'fich_obs_servicio_detalle', fn (object $r): array => [
                'id'               => (int) $r->id,
                'id_obs_item'      => (int) $r->id_obs_detalles_ficha,
                'id_tipo_servicio' => (int) $r->id_servicio,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Tarifarios
    // ─────────────────────────────────────────────────────────────────────

    private function migrarTarifarios(): void
    {
        $this->line('── Tarifarios ──');

        // Las tres tablas CUPS del legacy se consolidan en fich_cups.
        foreach (['2077', '2336', '2641'] as $resolucion) {
            $tabla = "cups_{$resolucion}";

            if (! $this->tablaLegacyExiste($tabla)) {
                $this->advertencias[] = "Tabla legacy {$tabla} no existe; se omite.";
                continue;
            }

            $this->migrarTabla(
                $tabla,
                'fich_cups',
                fn (object $r): array => [
                    'resolucion'   => $resolucion,
                    'es_vigente'   => $resolucion === '2641',
                    'subcategoria' => $this->limpiar($r->subcategoria) ?? '',
                    'desc_subcat'  => $this->limpiar($r->desc_subcat) ?? '',
                    'grupo'        => $this->limpiar($r->grupo ?? null),
                    'desc_grup'    => $this->limpiar($r->desc_grup ?? null),
                    'subgrupo'     => $this->limpiar($r->subgrupo ?? null),
                    'desc_subg'    => $this->limpiar($r->desc_subg ?? null),
                    'categoria'    => $this->limpiar($r->categoria ?? null),
                    'desc_cat'     => $this->limpiar($r->desc_cat ?? null),
                    'capitulo'     => $this->limpiar($r->capitulo ?? null),
                    'desc_cap'     => $this->limpiar($r->desc_cap ?? null),
                    'tipo_serv'    => $this->limpiar($r->tipo_serv ?? null),
                    'pbs'          => $this->limpiar((string) ($r->pbs ?? '')) ?: null,
                ],
                "subcategoria IS NOT NULL AND subcategoria <> ''",
                etiqueta: "cups_{$resolucion} → fich_cups",
                conservarId: false,
            );
        }

        $this->migrarTabla('homologos', 'fich_homologos', fn (object $r): array => [
            'id'               => (int) $r->id,
            'code_cups'        => $this->limpiar((string) $r->code_cups) ?? '',
            'desc_cups'        => $this->limpiar((string) $r->desc_cups) ?? '',
            'tipo_manual'      => $this->normalizarManual((string) ($r->tipo_manual ?? '')),
            'code_manual'      => $this->limpiar((string) $r->code_manual) ?? '',
            'desc_manual'      => $this->limpiar((string) $r->desc_manual) ?? '',
            'id_tipo_servicio' => ! empty($r->id_tipo) ? (int) $r->id_tipo : null,
            'uvr_grupo'        => $this->limpiar((string) ($r->uvr_grupo ?? '')) ?: null,
            'vlr_cirujano'     => $this->aDecimal($r->vlr_cirujano ?? null),
            'vlr_aneste'       => $this->aDecimal($r->vlr_aneste ?? null),
            'valor'            => $this->aDecimal($r->valor ?? null),
            'pbs'              => $this->aBool($r->pbs ?? 0),
            'observaciones'    => $this->limpiar($r->observaciones ?? null),
            'estado'           => true,
        ], "code_cups IS NOT NULL AND code_cups <> '' AND code_manual IS NOT NULL AND code_manual <> ''");

        if ($this->tablaLegacyExiste('soat_2023')) {
            $this->migrarTabla('soat_2023', 'fich_soat', fn (object $r): array => [
                'id'            => (int) $r->id,
                'vigencia'      => 2023,
                'cod'           => (string) $r->cod,
                'descripcion'   => $this->limpiar($r->descripcion) ?? '',
                'grupo'         => ! empty($r->grupo) ? (int) $r->grupo : null,
                'vlr_cirujano'  => $this->aDecimal($r->vlr_cirujano ?? 0) ?? 0,
                'vlr_anestesia' => $this->aDecimal($r->vlr_anestesia ?? 0) ?? 0,
                'valor'         => $this->aDecimal($r->valor ?? 0) ?? 0,
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Fichas
    // ─────────────────────────────────────────────────────────────────────

    private function prepararMapaUsuarios(): void
    {
        $respaldo = $this->option('usuario-respaldo');
        $this->usuarioRespaldo = $respaldo !== null
            ? (int) $respaldo
            : (int) (DB::table('users')->orderBy('id')->value('id') ?? 0);

        if ($this->usuarioRespaldo === 0) {
            throw new RuntimeException('No hay usuarios en la tabla users para usar como respaldo.');
        }

        // email → users.id
        $porEmail = DB::table('users')
            ->whereNotNull('email')
            ->pluck('id', 'email')
            ->mapWithKeys(static fn (int $id, string $email): array => [mb_strtolower(trim($email)) => $id])
            ->all();

        $sinMapear = 0;

        foreach ($this->legacy->table('usuarios')->get(['id', 'correo', 'nombre']) as $usuario) {
            $correo = mb_strtolower(trim((string) ($usuario->correo ?? '')));

            if ($correo !== '' && isset($porEmail[$correo])) {
                $this->mapaUsuarios[(int) $usuario->id] = $porEmail[$correo];
                continue;
            }

            $this->mapaUsuarios[(int) $usuario->id] = $this->usuarioRespaldo;
            $sinMapear++;
            $this->advertencias[] = "Usuario legacy #{$usuario->id} ({$usuario->nombre}) sin coincidencia por correo; asignado al usuario #{$this->usuarioRespaldo}.";
        }

        $mapeados = count($this->mapaUsuarios) - $sinMapear;
        $this->line("  usuarios mapeados: {$mapeados} / ".count($this->mapaUsuarios)." (respaldo: #{$this->usuarioRespaldo})");
    }

    private function migrarFichas(): void
    {
        $this->line('── Fichas ──');

        // Prefijo de empresa legacy → id_empresa de api-appCertec
        $empresasLegacy = $this->tablaLegacyExiste('empresas')
            ? $this->legacy->table('empresas')->get()->keyBy('id')
            : collect();

        $empresasDestino = DB::table('ent_empresas')
            ->get(['id', 'nombre', 'prefijo'])
            ->mapWithKeys(static fn (object $e): array => [mb_strtoupper(trim((string) $e->prefijo)) => (int) $e->id])
            ->all();

        $usuariosLegacy = $this->legacy->table('usuarios')->get()->keyBy('id');

        // Primero las fichas sin padre, luego las actualizaciones (FK autorreferenciada).
        foreach ([false, true] as $conPadre) {
            $this->migrarTabla(
                'ficha',
                'fich_fichas',
                function (object $r) use ($usuariosLegacy, $empresasLegacy, $empresasDestino): array {
                    $usuarioLegacy = $usuariosLegacy->get($r->id_user_reg);
                    $empresaLegacy = $usuarioLegacy !== null ? $empresasLegacy->get($usuarioLegacy->id_empresa) : null;

                    $prefijo    = mb_strtoupper(trim((string) ($empresaLegacy->prefijo ?? '')));
                    $idEmpresa  = $empresasDestino[$prefijo] ?? null;
                    $fechaIni   = $this->aFecha($r->fecha_ini);
                    $fechaFin   = $this->aFecha($r->fecha_fin);

                    // El CHECK chk_ffic_vigencia exige fecha_fin >= fecha_ini.
                    if ($fechaIni === null || $fechaFin === null) {
                        $this->advertencias[] = "Ficha legacy #{$r->id} sin fechas válidas; se usa la fecha de registro.";
                        $fechaIni ??= $this->aFecha($r->fecha_reg) ?? now()->toDateString();
                        $fechaFin ??= $fechaIni;
                    }

                    if ($fechaFin < $fechaIni) {
                        $this->advertencias[] = "Ficha legacy #{$r->id} con fecha_fin < fecha_ini; se igualan.";
                        $fechaFin = $fechaIni;
                    }

                    return [
                        'id'                 => (int) $r->id,
                        'consecutivo'        => $this->limpiar($r->consecutivo ?? null),
                        'id_padre'           => ! empty($r->id_padre) ? (int) $r->id_padre : null,
                        'version'            => 1,
                        'id_empresa'         => $idEmpresa,
                        'id_sucursal'        => null,
                        'sucursal_legacy'    => $this->limpiar($r->sucursal ?? null),
                        'id_agremiacion'     => (int) $r->id_agremiacion,
                        'id_objeto_contrato' => (int) $r->id_objeto,
                        'id_especialidad'    => (int) $r->id_esp,
                        'vlr_contrato'       => $this->aDecimal($r->vlr_contrato) ?? 0,
                        'fecha_ini'          => $fechaIni,
                        'fecha_fin'          => $fechaFin,
                        'id_estado'          => $this->normalizarEstado((int) $r->id_estado),
                        'id_user_reg'        => $this->mapaUsuarios[(int) $r->id_user_reg] ?? $this->usuarioRespaldo,
                        'fecha_reg'          => $this->aFechaHora($r->fecha_reg),
                        'user_autoriza_id'   => $this->mapearUsuario($r->user_dm ?? null),
                        'fecha_autoriza'     => $this->aFechaHora($r->fecha_dm ?? null),
                        'obs_autoriza'       => $this->limpiar($r->obs_dm ?? null),
                        'user_aprueba_id'    => $this->mapearUsuario($r->user_vf ?? null),
                        'fecha_aprueba'      => $this->aFechaHora($r->fecha_vf ?? null),
                        'obs_aprueba'        => $this->limpiar($r->obs_vf ?? null),
                        'obs_os'             => $this->limpiar($r->obs_os ?? null),
                        'novedad'            => $this->limpiar($r->novedad ?? null),
                    ];
                },
                $conPadre
                    ? 'id_padre IS NOT NULL AND id_agremiacion IS NOT NULL AND id_esp IS NOT NULL AND id_estado IS NOT NULL AND id_user_reg IS NOT NULL'
                    : 'id_padre IS NULL AND id_agremiacion IS NOT NULL AND id_esp IS NOT NULL AND id_estado IS NOT NULL AND id_user_reg IS NOT NULL',
                etiqueta: $conPadre ? 'ficha (actualizaciones) → fich_fichas' : 'ficha (originales) → fich_fichas',
            );
        }

        // Versión de cada actualización según el orden de creación.
        DB::statement(<<<'SQL'
            UPDATE fich_fichas f
            JOIN (
                SELECT id, id_padre,
                       ROW_NUMBER() OVER (PARTITION BY id_padre ORDER BY id) + 1 AS v
                  FROM fich_fichas
                 WHERE id_padre IS NOT NULL
            ) x ON x.id = f.id
            SET f.version = x.v
        SQL);

        $this->migrarTabla('detalles_ficha', 'fich_detalles', fn (object $r): array => [
            'id'               => (int) $r->id,
            'id_ficha'         => (int) $r->id_ficha,
            'tipo_liquidacion' => $this->limpiar($r->tipo_liquidacion ?? null),
            'tipo_servicio'    => $this->limpiar($r->tipo_servicio ?? null),
            'id_tipo_servicio' => null,
            'cups'             => $this->limpiar($r->cups ?? null),
            'grupo'            => $this->limpiar($r->grupo ?? null),
            'subgrupo'         => $this->limpiar($r->subgrupo ?? null),
            'forma_pago'       => $this->limpiar($r->forma_pago ?? null),
            'homologo'         => $this->limpiar($r->homologo ?? null),
            'variacion'        => $this->limpiar($r->variacion ?? null),
            'valor'            => $this->aDecimal($r->valor) ?? 0,
            'id_obs_item'      => ! empty($r->obs_item) && (int) $r->obs_item > 0 ? (int) $r->obs_item : null,
            'novedad'          => $this->limpiar($r->novedad ?? null),
        ], 'id_ficha IN (SELECT id FROM ficha)');

        $this->migrarTabla('inter_ficha_prof', 'fich_ficha_profesional', fn (object $r): array => [
            'id'             => (int) $r->id,
            'id_ficha'       => (int) $r->id_ficha,
            'id_profesional' => (int) $r->id_prof,
            'novedad'        => $this->limpiar($r->novedad ?? null),
        ], 'id_ficha IS NOT NULL AND id_prof IS NOT NULL');

        $this->migrarTabla('observaciones', 'fich_observaciones', fn (object $r): array => [
            'id'       => (int) $r->id,
            'id_ficha' => (int) $r->id_ficha,
            'desc_obs' => $this->limpiar($r->desc_obs) ?? '',
        ]);

        $this->migrarTabla('comentarios', 'fich_comentarios', fn (object $r): array => [
            'id'          => (int) $r->id,
            'id_ficha'    => (int) $r->id_ficha,
            'id_usuario'  => $this->mapaUsuarios[(int) $r->id_usuario] ?? $this->usuarioRespaldo,
            'descripcion' => (string) ($r->descripcion ?? ''),
            'created_at'  => $this->aFechaHora($r->fecha ?? null) ?? now(),
            'updated_at'  => $this->aFechaHora($r->fecha ?? null) ?? now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Motor de copia
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  callable(object): array<string, mixed>  $transformador
     */
    private function migrarTabla(
        string $tablaOrigen,
        string $tablaDestino,
        callable $transformador,
        ?string $where = null,
        ?string $etiqueta = null,
        bool $conservarId = true,
    ): void {
        if (! $this->tablaLegacyExiste($tablaOrigen)) {
            $this->advertencias[] = "Tabla legacy {$tablaOrigen} no existe; se omite.";

            return;
        }

        $etiqueta = $etiqueta ?? "{$tablaOrigen} → {$tablaDestino}";
        $chunk    = max((int) $this->option('chunk'), 100);

        $query = $this->legacy->table($tablaOrigen);
        if ($where !== null) {
            $query->whereRaw($where);
        }

        $total = (int) $query->count();

        if ($total === 0) {
            $this->line("  {$etiqueta}: 0 registros");

            return;
        }

        $barra      = $this->output->createProgressBar($total);
        $insertados = 0;
        $omitidos   = 0;
        $ahora      = now();

        $query->orderBy('id')->chunk($chunk, function ($filas) use (
            $transformador, $tablaDestino, $conservarId, $ahora, &$insertados, &$omitidos, $barra
        ): void {
            $lote = [];

            foreach ($filas as $fila) {
                try {
                    $registro = $transformador($fila);
                } catch (Throwable $e) {
                    $omitidos++;
                    $this->advertencias[] = "Fila #{$fila->id} de {$tablaDestino}: ".$e->getMessage();
                    $barra->advance();

                    continue;
                }

                if (! $conservarId) {
                    unset($registro['id']);
                }

                $registro['created_at'] ??= $ahora;
                $registro['updated_at'] ??= $ahora;

                $lote[] = $registro;
                $barra->advance();
            }

            if ($lote === []) {
                return;
            }

            try {
                // upsert por PK: idempotente, permite reejecutar el comando.
                DB::table($tablaDestino)->insertOrIgnore($lote);
                $insertados += count($lote);
            } catch (Throwable $e) {
                // Reintento fila por fila para aislar los registros problemáticos.
                foreach ($lote as $registro) {
                    try {
                        DB::table($tablaDestino)->insertOrIgnore([$registro]);
                        $insertados++;
                    } catch (Throwable $ex) {
                        $omitidos++;
                        $this->advertencias[] = "{$tablaDestino} id=".($registro['id'] ?? '?').': '.$ex->getMessage();
                    }
                }
            }
        });

        $barra->finish();
        $this->newLine();
        $this->line("  {$etiqueta}: {$insertados} insertados".($omitidos > 0 ? ", {$omitidos} omitidos" : ''));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Utilidades
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Desactiva los triggers de contadores durante la carga masiva.
     *
     * Con 37.000 detalles, dejarlos activos implicaría 37.000 UPDATE sobre
     * fich_fichas. Al final se recalcula con el SP.
     *
     * @param  callable(): void  $callback
     */
    private function sinTriggers(callable $callback): void
    {
        $triggers = [
            'trg_fich_detalles_ai', 'trg_fich_detalles_au', 'trg_fich_detalles_ad',
            'trg_fich_ficha_prof_ai', 'trg_fich_ficha_prof_ad',
            'trg_fich_fichas_ai', 'trg_fich_fichas_au',
        ];

        $definiciones = [];
        foreach ($triggers as $trigger) {
            $fila = DB::selectOne(
                'SELECT ACTION_STATEMENT s, ACTION_TIMING t, EVENT_MANIPULATION e, EVENT_OBJECT_TABLE o
                 FROM information_schema.TRIGGERS
                 WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?',
                [$trigger]
            );

            if ($fila !== null) {
                $definiciones[$trigger] = $fila;
                DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
            }
        }

        $this->line('  triggers desactivados: '.count($definiciones));
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        DB::statement('SET UNIQUE_CHECKS = 0');

        try {
            $callback();
        } finally {
            DB::statement('SET UNIQUE_CHECKS = 1');
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');

            foreach ($definiciones as $trigger => $def) {
                DB::unprepared(
                    "CREATE TRIGGER {$trigger} {$def->t} {$def->e} ON {$def->o} FOR EACH ROW {$def->s}"
                );
            }

            $this->line('  triggers restaurados: '.count($definiciones));
        }
    }

    private function recalcularTotales(): void
    {
        $total = (int) DB::table('fich_fichas')->count();

        if ($total === 0) {
            return;
        }

        $this->line('── Recalculando contadores ──');

        // Un solo UPDATE con subconsultas en lugar de N llamadas al SP.
        DB::statement(<<<'SQL'
            UPDATE fich_fichas f
            LEFT JOIN (SELECT id_ficha, COUNT(*) c, SUM(valor) v FROM fich_detalles GROUP BY id_ficha) d
                   ON d.id_ficha = f.id
            LEFT JOIN (SELECT id_ficha, COUNT(*) c FROM fich_ficha_profesional GROUP BY id_ficha) p
                   ON p.id_ficha = f.id
            SET f.total_detalles       = IFNULL(d.c, 0),
                f.valor_total_detalles = IFNULL(d.v, 0),
                f.total_profesionales  = IFNULL(p.c, 0)
        SQL);

        $this->line("  {$total} fichas actualizadas");
    }

    private function verificarIntegridad(): void
    {
        $this->newLine();
        $this->line('── Verificación ──');

        $pares = [
            ['objetos_contrato', 'fich_objetos_contrato'],
            ['tipos_servicios', 'fich_tipos_servicio'],
            ['especialidades', 'fich_especialidades'],
            ['agremiaciones', 'fich_agremiaciones'],
            ['profesionales', 'fich_profesionales'],
            ['inter_prof_esp', 'fich_profesional_especialidad'],
            ['obs_detalles_ficha', 'fich_obs_items'],
            ['homologos', 'fich_homologos'],
            ['ficha', 'fich_fichas'],
            ['detalles_ficha', 'fich_detalles'],
            ['inter_ficha_prof', 'fich_ficha_profesional'],
            ['observaciones', 'fich_observaciones'],
            ['comentarios', 'fich_comentarios'],
        ];

        $filas = [];
        foreach ($pares as [$origen, $destino]) {
            if (! $this->tablaLegacyExiste($origen)) {
                continue;
            }

            $o = (int) $this->legacy->table($origen)->count();
            $d = (int) DB::table($destino)->count();

            $filas[] = [$origen, $o, $destino, $d, $d >= $o ? 'OK' : 'DIFERENCIA ('.($o - $d).')'];
        }

        $this->table(['Legacy', 'Origen', 'Destino', 'Migrados', 'Estado'], $filas);
    }

    private function reportarConteos(): int
    {
        $this->line('── Conteos de la BD legacy (dry-run) ──');

        $tablas = [
            'objetos_contrato', 'tipos_servicios', 'especialidades', 'agremiaciones',
            'profesionales', 'inter_prof_esp', 'obs_detalles_ficha', 'obs_servicio_detalle',
            'cups_2077', 'cups_2336', 'cups_2641', 'homologos', 'soat_2023',
            'usuarios', 'empresas', 'ficha', 'detalles_ficha', 'inter_ficha_prof',
            'observaciones', 'comentarios',
        ];

        $filas = [];
        foreach ($tablas as $tabla) {
            $filas[] = $this->tablaLegacyExiste($tabla)
                ? [$tabla, number_format((float) $this->legacy->table($tabla)->count())]
                : [$tabla, 'NO EXISTE'];
        }

        $this->table(['Tabla legacy', 'Registros'], $filas);
        $this->newLine();
        $this->info('Sin cambios en la base de destino (--dry-run).');

        return self::SUCCESS;
    }

    private function confirmarTruncate(): bool
    {
        $this->warn('--truncate vaciará TODAS las tablas fich_* del destino. Esta acción no se puede deshacer.');

        return (bool) $this->confirm('¿Continuar?', false);
    }

    private function truncarDestino(): void
    {
        $tablas = [
            'fich_historial_estados', 'fich_comentarios', 'fich_observaciones',
            'fich_ficha_profesional', 'fich_detalles', 'fich_fichas',
            'fich_obs_servicio_detalle', 'fich_obs_items',
            'fich_profesional_especialidad', 'fich_profesionales',
            'fich_agremiaciones', 'fich_especialidades',
            'fich_tipos_servicio', 'fich_objetos_contrato',
            'fich_soat', 'fich_homologos', 'fich_cups',
        ];

        foreach ($tablas as $tabla) {
            DB::table($tabla)->delete();
            DB::statement("ALTER TABLE {$tabla} AUTO_INCREMENT = 1");
        }

        $this->line('  destino vaciado: '.count($tablas).' tablas');
    }

    private function tablaLegacyExiste(string $tabla): bool
    {
        return Schema::connection((string) $this->option('conexion'))->hasTable($tabla);
    }

    private function mapearUsuario(mixed $idLegacy): ?int
    {
        if (empty($idLegacy) || ! is_numeric($idLegacy)) {
            return null;
        }

        return $this->mapaUsuarios[(int) $idLegacy] ?? null;
    }

    private function limpiar(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        // El legacy guardaba la cadena literal 'NULL' en varias columnas.
        if ($texto === '' || strcasecmp($texto, 'NULL') === 0) {
            return null;
        }

        // Corrige el doble-encoding heredado de utf8_encode() del legacy.
        if (! mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'ISO-8859-1');
        }

        return $texto;
    }

    private function aBool(mixed $valor): bool
    {
        return in_array((string) $valor, ['1', 'true', 'TRUE', 'SI', 'S'], true);
    }

    /** Usa el mismo parser que los DTO, para que la conversión sea idéntica. */
    private function aDecimal(mixed $valor): ?float
    {
        return MoneyParser::aFloatONull($valor);
    }

    private function aFecha(mixed $valor): ?string
    {
        $fechaHora = $this->aFechaHora($valor);

        return $fechaHora !== null ? substr($fechaHora, 0, 10) : null;
    }

    /**
     * El legacy guardaba fechas como varchar(50) con formatos mixtos:
     * "2024-05-01", "2024-05-01, 14:22:10", "01/05/2024".
     */
    private function aFechaHora(mixed $valor): ?string
    {
        $texto = $this->limpiar($valor);

        if ($texto === null) {
            return null;
        }

        $texto = str_replace(',', ' ', $texto);
        $texto = preg_replace('/\s+/', ' ', $texto) ?? $texto;

        foreach (['Y-m-d H:i:s', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y', 'Y/m/d'] as $formato) {
            $fecha = \DateTimeImmutable::createFromFormat($formato, trim($texto));

            if ($fecha !== false) {
                return $fecha->format('Y-m-d H:i:s');
            }
        }

        try {
            return (new \DateTimeImmutable($texto))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizarManual(string $valor): string
    {
        $valor = mb_strtoupper(trim($valor));

        return match (true) {
            str_contains($valor, 'ISS')           => 'ISS 2001',
            str_contains($valor, 'SOAT')          => 'SOAT',
            str_contains($valor, 'INSTITUCIONAL') => 'INSTITUCIONAL',
            default                               => 'INSTITUCIONAL',
        };
    }

    /** Garantiza que el id_estado legacy exista en fich_estados. */
    private function normalizarEstado(int $idEstado): int
    {
        try {
            return EstadoFicha::fromId($idEstado)->id();
        } catch (Throwable) {
            $this->advertencias[] = "Estado legacy {$idEstado} desconocido; se asigna Borrador (1).";

            return EstadoFicha::Borrador->id();
        }
    }
}
