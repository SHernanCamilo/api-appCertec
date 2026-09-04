<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Fabric\Export\StreamingExportWriter;
use App\Services\Fabric\GraphAsyncExportService;
use Illuminate\Console\Command;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Genera un .xlsx REAL de una vista y lo deja en disco para abrirlo y revisarlo.
 *
 * Hace el flujo completo de producción de punta a punta:
 *   1. Pide el export a Graph-Fabric (igual que "Actualizar todo").
 *   2. Descarga el NDJSON.gz.
 *   3. Genera el .xlsx con el MISMO writer que usa el job.
 *   4. Lo deja en una ruta fija (no lo borra) y mide la columna de texto largo.
 *
 * A diferencia de fabric:verify-xlsx, este NO borra el archivo: el objetivo es
 * que el usuario lo abra en Excel y confirme cómo se ve la columna Analisis.
 *
 * USO:
 *   php artisan fabric:build-xlsx hg VW_HC_EvolucionesEspecialistas_Tja
 *   php artisan fabric:build-xlsx hg VW_HC_EvolucionesEspecialistas_Tja --col=Analisis --max-rows=2000
 */
final class BuildXlsxSampleCommand extends Command
{
    protected $signature = 'fabric:build-xlsx
        {schema : Esquema de la vista, ej: hg}
        {view : Nombre de la vista, ej: VW_HC_EvolucionesEspecialistas_Tja}
        {--col=Analisis : Columna de texto a medir en el resultado}
        {--max-rows=5000 : Limite de filas del export (mas chico = mas rapido para probar)}
        {--user= : Email del usuario con el que se ejecuta (por defecto, el primer admin)}
        {--timeout=300 : Segundos maximos de espera del export}
        {--out= : Ruta de salida del .xlsx (por defecto storage/app/fabric_exports/muestra_<vista>.xlsx)}';

    protected $description = 'Genera un .xlsx real de una vista y lo deja en disco para revisarlo en Excel';

    public function handle(GraphAsyncExportService $exports): int
    {
        $schema  = (string) $this->argument('schema');
        $view    = (string) $this->argument('view');
        $col     = (string) $this->option('col');
        $maxRows = (int) $this->option('max-rows');

        $user = $this->resolveUser();
        if ($user === null) {
            $this->error('No se encontro el usuario. Pase --user=email.');
            return self::FAILURE;
        }

        $this->line("Vista   : {$schema}.{$view}");
        $this->line("Usuario : {$user->email}");
        $this->line("Filas   : hasta " . number_format($maxRows));
        $this->newLine();

        // ── 1. Iniciar el export ─────────────────────────────────────────────
        $this->info('[1/5] Pidiendo el export a Graph-Fabric...');
        $start = $exports->start($user, $schema, $view, ['max_rows' => $maxRows]);

        if (($start['success'] ?? false) !== true) {
            $this->error('  Fallo: ' . ($start['message'] ?? 'sin detalle'));
            return self::FAILURE;
        }

        $jobId = (string) $start['job_id'];
        $this->line("  job_id: {$jobId}");

        // ── 2. Esperar ───────────────────────────────────────────────────────
        $this->info('[2/5] Esperando a que termine...');
        $deadline = time() + (int) $this->option('timeout');
        $rows     = 0;

        while (true) {
            if (time() > $deadline) {
                $this->error('  Timeout esperando el export.');
                return self::FAILURE;
            }

            $status = $exports->status($jobId);
            $state  = (string) ($status['status'] ?? 'processing');

            if ($state === 'completed') {
                $rows = (int) ($status['rows'] ?? 0);
                $this->line('  Listo: ' . number_format($rows) . ' filas');
                break;
            }

            if ($state === 'failed') {
                $this->error('  Fallo: ' . ($status['message'] ?? 'sin detalle'));
                return self::FAILURE;
            }

            $this->line('  ' . ($status['message'] ?? $state) . ' (' . ($status['progress'] ?? 0) . '%)');
            sleep(3);
        }

        // ── 3. Descargar el .gz ──────────────────────────────────────────────
        $this->info('[3/5] Descargando el NDJSON.gz...');
        $download = $exports->download($jobId);

        if (($download['success'] ?? false) !== true) {
            $this->error('  Fallo: ' . ($download['message'] ?? 'sin detalle'));
            return self::FAILURE;
        }

        $gzPath = (string) $download['path'];
        $this->line('  Archivo: ' . $gzPath);

        // ── 4. Generar el .xlsx con el writer real ───────────────────────────
        $this->info('[4/5] Generando el .xlsx (writer de produccion)...');
        $dir  = dirname($gzPath);
        $base = 'muestra_' . $view;

        $result = StreamingExportWriter::fromNdjsonGzFile(
            $gzPath,
            $dir,
            $base,
            $schema,
            $view,
            $rows,
        );

        if ($result->isEmpty()) {
            $this->error('  El writer no genero ningun archivo.');
            return self::FAILURE;
        }

        // Mover a la ruta de salida elegida (o dejar donde quedo)
        $out = (string) ($this->option('out') ?? '');
        if ($out === '') {
            $out = storage_path("app/fabric_exports/muestra_{$view}.{$result->format}");
        }

        if ($result->path !== $out) {
            @copy($result->path, $out);
        }

        $this->line('  Formato : ' . $result->format);
        $this->line('  Filas   : ' . number_format($result->rows));
        $this->line('  Tamanio : ' . number_format($result->bytes) . ' bytes');
        $this->newLine();
        $this->info('  ARCHIVO LISTO PARA ABRIR:');
        $this->line('    ' . $out);
        $this->newLine();

        // ── 5. Medir la columna en el .xlsx ──────────────────────────────────
        if ($result->format === 'xlsx') {
            $this->info("[5/5] Midiendo la columna '{$col}' en el .xlsx generado...");
            $medida = $this->measureXlsx($out, $col);

            if ($medida === null) {
                $this->warn("  No se encontro la columna '{$col}' en el archivo.");
            } else {
                [$max, $filas, $sample, $cortesReales, $puntosLegitimos, $ejemplos] = $medida;
                $this->line('  Filas de datos          : ' . number_format($filas));
                $this->line('  Longitud MAXIMA         : ' . number_format($max) . ' caracteres');
                $this->line('  Cortes del codigo viejo : ' . number_format($cortesReales)
                    . '  ("…" al final + longitud ~300)');
                $this->line('  "…" dentro del texto     : ' . number_format($puntosLegitimos)
                    . '  (puntos suspensivos que escribio el medico, NO son corte)');
                $this->newLine();
                $this->line('  Valor mas largo (ultimos 80 chars):');
                $this->line('    ...' . mb_substr($sample, max(0, mb_strlen($sample) - 80)));

                if ($ejemplos !== []) {
                    $this->newLine();
                    $this->line('  Ejemplos de celdas con "…" (para ver que NO son cortes):');
                    foreach ($ejemplos as $ej) {
                        $this->line(sprintf('    [len %s] ...%s',
                            number_format($ej['len']),
                            mb_substr($ej['valor'], max(0, mb_strlen($ej['valor']) - 55))
                        ));
                    }
                }
                $this->newLine();

                if ($cortesReales > 0) {
                    $this->error("  >> Hay {$cortesReales} celdas cortadas por el codigo viejo (limite 300).");
                    $this->line('  >> Limpie cache y regenere: php artisan exports:cleanup --hours=0 && php artisan cache:clear');
                } else {
                    $this->info('  >> SIN cortes del codigo. El texto sale completo (tope real: 8000 desde Fabric).');
                    if ($puntosLegitimos > 0) {
                        $this->line('  >> Las celdas con "…" son puntos suspensivos que ESCRIBIO el medico en la');
                        $this->line('     nota (texto real), no un truncamiento. El dato esta integro.');
                    }
                    $this->line('  >> En Excel: la columna "' . $col . '" sale ancha. Para leer una nota entera,');
                    $this->line('     seleccione la columna y use Inicio > Formato > Autoajustar alto de fila.');
                }
            }
        } else {
            $this->warn("[5/5] El resultado es {$result->format} (dataset grande), no se mide con OpenSpout.");
        }

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $email = $this->option('user');

        return $email
            ? User::where('email', $email)->first()
            : User::orderBy('id')->first();
    }

    /**
     * Mide la columna distinguiendo dos cosas MUY distintas:
     *
     *   - Corte del código viejo: el "…" está al FINAL y la celda mide ~300
     *     caracteres (era `mb_substr(..., 0, 299) . '…'`). Eso SÍ es pérdida.
     *
     *   - Puntos suspensivos legítimos: el médico escribió "…" DENTRO de la nota
     *     (o al final de un texto largo). Es texto real, no un truncamiento.
     *
     * Confundir los dos fue el error del detector anterior: contaba cualquier
     * "…" y marcaba como "corte" celdas de 7.840 caracteres que estaban íntegras.
     *
     * @return array{0:int,1:int,2:string,3:int,4:int,5:list<array{len:int,valor:string}>}|null
     */
    private function measureXlsx(string $path, string $column): ?array
    {
        $reader = new Reader();
        $reader->open($path);

        $colIndex        = null;
        $max             = 0;
        $filas           = 0;
        $sample          = '';
        $cortesReales    = 0;
        $puntosLegitimos = 0;
        $ejemplos        = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->toArray();

                if ($colIndex === null) {
                    foreach ($cells as $i => $cell) {
                        if (trim((string) $cell) === $column) {
                            $colIndex = $i;
                            break;
                        }
                    }
                    continue;
                }

                $filas++;
                $value = (string) ($cells[$colIndex] ?? '');
                $len   = mb_strlen($value);

                if ($len > $max) {
                    $max    = $len;
                    $sample = $value;
                }

                if (str_contains($value, '…')) {
                    // Corte del código viejo: "…" al final Y longitud en el
                    // rango del límite viejo (300, con margen por si acaso).
                    $esCorteViejo = str_ends_with($value, '…') && $len >= 295 && $len <= 305;

                    if ($esCorteViejo) {
                        $cortesReales++;
                    } else {
                        $puntosLegitimos++;
                        if (count($ejemplos) < 3) {
                            $ejemplos[] = ['len' => $len, 'valor' => $value];
                        }
                    }
                }
            }
            break;
        }

        $reader->close();

        return $colIndex === null
            ? null
            : [$max, $filas, $sample, $cortesReales, $puntosLegitimos, $ejemplos];
    }
}
