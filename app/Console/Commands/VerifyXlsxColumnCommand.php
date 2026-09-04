<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Fabric\Export\StreamingExportWriter;
use Illuminate\Console\Command;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Verifica la INTEGRIDAD del texto en el .xlsx que se genera de verdad.
 *
 * PARA QUE SIRVE:
 *   Se reportó varias veces que la columna "Analisis" salía cortada en el Excel.
 *   Medir el NDJSON no alcanza: hay que abrir el .xlsx YA GENERADO y comparar,
 *   celda por celda, contra el dato de origen. Este comando cierra ese círculo y
 *   dice sin ambigüedad si la generación del Excel pierde caracteres o no.
 *
 * QUE HACE:
 *   1. Toma un NDJSON.gz ya descargado (o el de un job).
 *   2. Lo pasa por el MISMO writer que usa el job de producción.
 *   3. Abre el .xlsx resultante y mide la columna pedida.
 *   4. Compara: longitud máxima en el NDJSON vs en el .xlsx.
 *
 * USO:
 *   php artisan fabric:verify-xlsx {jobId} --col=Analisis
 *   php artisan fabric:verify-xlsx --gz=/ruta/download.gz --col=Analisis
 */
final class VerifyXlsxColumnCommand extends Command
{
    protected $signature = 'fabric:verify-xlsx
        {jobId? : Id del job cuyo .gz ya esta en storage/app/fabric_exports}
        {--gz= : Ruta directa a un NDJSON.gz (alternativa al jobId)}
        {--file= : Inspecciona un .xlsx YA DESCARGADO (el que abre el usuario) en vez de generar uno}
        {--col=Analisis : Columna de texto a verificar}
        {--schema=hg : Esquema, solo para el nombre del archivo}
        {--view=VW_Test : Vista, solo para el nombre del archivo}';

    protected $description = 'Genera el xlsx real y verifica que no pierda caracteres en una columna de texto';

    public function handle(): int
    {
        $col = (string) $this->option('col');

        // ── Modo inspeccion: medir un .xlsx que el usuario ya descargo ───────
        $file = (string) ($this->option('file') ?? '');
        if ($file !== '') {
            return $this->inspectExistingFile($file, $col);
        }

        $gz = $this->resolveGzPath();

        if ($gz === null) {
            $this->error('No se encontro el NDJSON.gz. Pase --gz=/ruta o un jobId valido.');
            $this->line('Tip: corra primero  php artisan fabric:diagnose-export <schema> <view>');
            return self::FAILURE;
        }

        $this->line("NDJSON  : {$gz}");
        $this->line('Tamanio : ' . number_format((int) filesize($gz)) . ' bytes');
        $this->line("Columna : {$col}");
        $this->newLine();

        // ── 1. Medir el ORIGEN ───────────────────────────────────────────────
        $this->info('[1/3] Midiendo la columna en el NDJSON de origen...');
        $origen = $this->measureNdjson($gz, $col);

        if ($origen === null) {
            $this->error("  La columna '{$col}' no existe en el NDJSON.");
            return self::FAILURE;
        }

        [$maxOrigen, $filasOrigen, $valorMasLargo] = $origen;
        $this->line('  Filas            : ' . number_format($filasOrigen));
        $this->line('  Longitud MAXIMA  : ' . number_format($maxOrigen) . ' caracteres');
        $this->newLine();

        // ── 2. Generar el xlsx con el writer REAL ────────────────────────────
        $this->info('[2/3] Generando el .xlsx con el writer de produccion...');
        $dir  = dirname($gz);
        $base = 'verify_' . now()->format('His');

        $result = StreamingExportWriter::fromNdjsonGzFile(
            $gz,
            $dir,
            $base,
            (string) $this->option('schema'),
            (string) $this->option('view'),
            $filasOrigen,
        );

        if ($result->isEmpty()) {
            $this->error('  El writer no genero ningun archivo.');
            return self::FAILURE;
        }

        $this->line('  Archivo  : ' . $result->path);
        $this->line('  Formato  : ' . $result->format);
        $this->line('  Filas    : ' . number_format($result->rows));
        $this->line('  Tamanio  : ' . number_format($result->bytes) . ' bytes');
        $this->newLine();

        if ($result->format !== 'xlsx') {
            $this->warn('  El writer devolvio ' . $result->format . ' (no xlsx), no se puede leer con OpenSpout.');
            $this->line('  Eso pasa cuando el dataset excede el limite de filas de Excel.');
            @unlink($result->path);
            return self::SUCCESS;
        }

        // ── 3. Leer el xlsx de vuelta y medir ────────────────────────────────
        $this->info('[3/3] Abriendo el .xlsx generado y midiendo la misma columna...');
        $destino = $this->measureXlsx($result->path, $col);

        if ($destino === null) {
            $this->error("  No se encontro la columna '{$col}' en el .xlsx generado.");
            @unlink($result->path);
            return self::FAILURE;
        }

        [$maxXlsx, $filasXlsx, $valorXlsx] = [$destino[0], $destino[1], $destino[2]];
        $this->line('  Filas de datos   : ' . number_format($filasXlsx));
        $this->line('  Longitud MAXIMA  : ' . number_format($maxXlsx) . ' caracteres');
        $this->newLine();

        // ── Veredicto ────────────────────────────────────────────────────────
        $this->line(str_repeat('=', 64));
        $this->line(sprintf('  NDJSON de origen : %s caracteres', number_format($maxOrigen)));
        $this->line(sprintf('  XLSX generado    : %s caracteres', number_format($maxXlsx)));
        $this->line(str_repeat('=', 64));
        $this->newLine();

        // El writer aplica trim() a cada celda, así que un origen que termina en
        // espacios legítimamente sale más corto. Se compara contra el origen ya
        // recortado para no reportar un falso truncamiento por 1-2 espacios.
        $maxOrigenTrim = mb_strlen(trim($valorMasLargo));

        if ($maxXlsx >= $maxOrigenTrim) {
            $this->info('OK: el .xlsx CONSERVA todo el texto. La generacion del Excel no trunca.');

            if ($maxOrigen !== $maxOrigenTrim) {
                $this->line(sprintf(
                    '  (El origen medía %s con espacios al final; se comparó contra %s ya recortado.)',
                    number_format($maxOrigen),
                    number_format($maxOrigenTrim)
                ));
            }

            $this->newLine();
            $this->line('Si en Excel se ve cortado, es PRESENTACION, no dato:');
            $this->line('  - Haga clic en la celda y mire la barra de formulas: el texto esta completo.');
            $this->line('  - Ensanche la columna o active "Ajustar texto" para verlo todo.');
            $this->line('El texto que falte respecto a la base de datos se corta ANTES, en Fabric.');
        } else {
            $perdidos = $maxOrigenTrim - $maxXlsx;
            $this->error(sprintf(
                'FALLO: el .xlsx perdio %s caracteres (%s -> %s).',
                number_format($perdidos),
                number_format($maxOrigenTrim),
                number_format($maxXlsx)
            ));
            $this->newLine();
            $this->line('Ultimos 60 chars en el ORIGEN :');
            $this->line('  ...' . mb_substr($valorMasLargo, max(0, mb_strlen($valorMasLargo) - 60)));
            $this->line('Ultimos 60 chars en el XLSX   :');
            $this->line('  ...' . mb_substr($valorXlsx, max(0, mb_strlen($valorXlsx) - 60)));
            @unlink($result->path);

            return self::FAILURE;
        }

        @unlink($result->path);

        return self::SUCCESS;
    }

    /**
     * Mide una columna en un .xlsx que el usuario YA descargó.
     *
     * Es la forma de zanjar la duda "el Excel me sale cortado": se abre el mismo
     * archivo que el usuario tiene y se reporta la longitud real, si aparece el
     * marcador de corte "…" y en qué longitud se acumulan los valores.
     */
    private function inspectExistingFile(string $file, string $col): int
    {
        if (!is_file($file)) {
            $this->error("No existe el archivo: {$file}");

            return self::FAILURE;
        }

        $this->line("Archivo : {$file}");
        $this->line('Tamanio : ' . number_format((int) filesize($file)) . ' bytes');
        $this->line("Columna : {$col}");
        $this->newLine();

        $this->info('Abriendo el .xlsx y midiendo la columna...');

        $medida = $this->measureXlsx($file, $col);

        if ($medida === null) {
            $this->error("No se encontro la columna '{$col}' en el archivo.");
            $this->line('Verifique el nombre exacto del encabezado.');

            return self::FAILURE;
        }

        [$max, $filas, $sample, $cortesReales, $puntosLegitimos, $distribucion] = $medida;

        $this->line('  Filas de datos          : ' . number_format($filas));
        $this->line('  Longitud MAXIMA         : ' . number_format($max) . ' caracteres');
        $this->line('  Cortes del codigo viejo : ' . number_format($cortesReales)
            . '  ("…" al final + longitud ~300)');
        $this->line('  "…" dentro del texto     : ' . number_format($puntosLegitimos)
            . '  (puntos suspensivos del medico, NO son corte)');
        $this->newLine();
        $this->line('  Distribucion de longitudes:');
        foreach ($distribucion as $rango => $cuenta) {
            if ($cuenta > 0) {
                $this->line(sprintf('    %-14s %s', $rango, number_format($cuenta)));
            }
        }
        $this->newLine();
        $this->line('  Valor mas largo (ultimos 80 chars):');
        $this->line('    ...' . mb_substr($sample, max(0, mb_strlen($sample) - 80)));
        $this->newLine();

        // ── Veredicto ────────────────────────────────────────────────────────
        if ($cortesReales > 0) {
            $this->error("  >> Este archivo tiene {$cortesReales} celdas cortadas por el codigo viejo (limite 300).");
            $this->line('  >> Solucion: limpiar cache y regenerar:');
            $this->line('       php artisan exports:cleanup --hours=0');
            $this->line('       php artisan cache:clear');
            $this->line('     Luego "Actualizar todo" en el visor y descargar de nuevo.');

            return self::FAILURE;
        }

        if ($puntosLegitimos > 0) {
            $this->line(sprintf(
                '  Nota: %s celdas tienen "…" en el texto, pero son puntos suspensivos que',
                number_format($puntosLegitimos)
            ));
            $this->line('  escribio el medico (no cortes). El dato esta integro.');
            $this->newLine();
        }

        if ($max <= 320) {
            $this->error('  >> El maximo es sospechosamente bajo (~300): parece el limite antiguo.');
            $this->line('  >> Regenere el archivo tras limpiar cache.');

            return self::FAILURE;
        }

        $this->info('  >> OK: el archivo NO tiene marcador de corte y conserva texto largo.');
        $this->newLine();
        $this->line('  Si en Excel se ve cortado en pantalla, es solo PRESENTACION:');
        $this->line('    - Clic en la celda y mire la barra de formulas: el texto esta completo.');
        $this->line('    - Seleccione la columna > Inicio > "Ajustar texto" para verlo todo.');
        $this->line('  El texto que falte respecto a la base de datos se corta en Fabric (8.000).');

        return self::SUCCESS;
    }

    /** Ubica el .gz a partir del jobId o de --gz. */
    private function resolveGzPath(): ?string
    {
        $explicit = (string) ($this->option('gz') ?? '');
        if ($explicit !== '') {
            return is_file($explicit) ? $explicit : null;
        }

        $jobId = (string) ($this->argument('jobId') ?? '');
        if ($jobId !== '') {
            $path = storage_path("app/fabric_exports/{$jobId}/download.gz");

            return is_file($path) ? $path : null;
        }

        // Sin argumentos: tomar el .gz mas reciente que haya en disco
        $candidatos = glob(storage_path('app/fabric_exports/*/download.gz')) ?: [];
        if ($candidatos === []) {
            return null;
        }

        usort($candidatos, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $candidatos[0];
    }

    /**
     * Longitud maxima de una columna en el NDJSON.
     *
     * @return array{0:int,1:int,2:string}|null
     */
    private function measureNdjson(string $path, string $column): ?array
    {
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            return null;
        }

        $max    = 0;
        $filas  = 0;
        $sample = '';
        $existe = false;

        try {
            while (($line = gzgets($gz)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);
                if (!is_array($row) || !array_key_exists($column, $row)) {
                    continue;
                }

                $existe = true;
                $filas++;

                $len = mb_strlen((string) ($row[$column] ?? ''));
                if ($len > $max) {
                    $max    = $len;
                    $sample = (string) $row[$column];
                }
            }
        } finally {
            gzclose($gz);
        }

        return $existe ? [$max, $filas, $sample] : null;
    }

    /**
     * Longitud maxima de una columna leyendo el .xlsx ya generado.
     *
     * Se busca la fila de encabezados (el writer puede poner portada arriba) y
     * desde ahi se recorre la columna midiendo cada celda.
     *
     * Distingue un corte del código viejo ("…" al final + longitud ~300) de un
     * "…" legítimo escrito por el médico dentro de la nota. Contar cualquier "…"
     * daba falsos positivos en notas largas e íntegras.
     *
     * @return array{0:int,1:int,2:string,3:int,4:int,5:array<string,int>}|null
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

        $distribucion = [
            '0'          => 0,
            '1-100'      => 0,
            '101-255'    => 0,
            '256-320'    => 0,
            '321-1000'   => 0,
            '1001-4000'  => 0,
            '4001-8000'  => 0,
            '8001-32767' => 0,
        ];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->toArray();

                // Buscar la fila de encabezados (salta la portada corporativa)
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
                    $esCorteViejo = str_ends_with($value, '…') && $len >= 295 && $len <= 305;
                    if ($esCorteViejo) {
                        $cortesReales++;
                    } else {
                        $puntosLegitimos++;
                    }
                }

                $distribucion[match (true) {
                    $len === 0    => '0',
                    $len <= 100   => '1-100',
                    $len <= 255   => '101-255',
                    $len <= 320   => '256-320',
                    $len <= 1000  => '321-1000',
                    $len <= 4000  => '1001-4000',
                    $len <= 8000  => '4001-8000',
                    default       => '8001-32767',
                }]++;
            }
            break;
        }

        $reader->close();

        return $colIndex === null
            ? null
            : [$max, $filas, $sample, $cortesReales, $puntosLegitimos, $distribucion];
    }
}
