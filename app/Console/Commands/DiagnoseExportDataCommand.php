<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Fabric\GraphAsyncExportService;
use Illuminate\Console\Command;

/**
 * Diagnostica el camino de datos que alimenta la grilla del viewer de vistas
 * (GET /api/fabric/viewer/export/download/{jobId}?as=data).
 *
 * PARA QUE SIRVE:
 *   El viewer pinto la grilla con nombres de columna binarios ("Õÿù¯?þí/ðjõ")
 *   varias veces, y cada vez se perdio tiempo adivinando en que capa se rompia:
 *   Graph-Fabric, el .gz en disco, Laravel, Apache o el navegador. Este comando
 *   corta el problema en dos: verifica TODO lo que pasa del lado del servidor.
 *
 *   - Si el comando dice OK, el archivo esta bien y el problema esta en el
 *     transporte HTTP (compresion de Apache/proxy) o en el frontend. Ahi hay que
 *     mirar el log "[parseBlob] primeros bytes:" de la consola del navegador.
 *   - Si el comando falla, el problema viene de Graph-Fabric o del .gz.
 *
 * USO:
 *   php artisan fabric:diagnose-export pt VW_Treasury_ComprobantesEgresoTesoreria
 *   php artisan fabric:diagnose-export pt VW_... --user=admin@medilaser.com.co
 */
final class DiagnoseExportDataCommand extends Command
{
    protected $signature = 'fabric:diagnose-export
        {schema : Esquema de la vista, ej: pt}
        {view : Nombre de la vista, ej: VW_Treasury_ComprobantesEgresoTesoreria}
        {--user= : Email del usuario con el que se ejecuta (por defecto, el primer admin)}
        {--timeout=300 : Segundos maximos de espera del export}
        {--measure-col= : Mide la longitud MAXIMA de esta columna en el NDJSON crudo (ej: Analisis) para saber si Graph-Fabric ya la trunca}';

    protected $description = 'Verifica de punta a punta el NDJSON.gz que alimenta la grilla del viewer de vistas';

    /** Magic bytes conocidos, para identificar que llego de verdad. */
    private const MAGIC = [
        '1f8b' => 'gzip',
        '504b' => 'ZIP / xlsx',
        '7b22' => 'JSON (texto plano)',
        'efbb' => 'texto con BOM UTF-8',
    ];

    public function handle(GraphAsyncExportService $exports): int
    {
        $schema = (string) $this->argument('schema');
        $view   = (string) $this->argument('view');

        $user = $this->resolveUser();
        if ($user === null) {
            $this->error('No se encontro el usuario. Pase --user=email.');
            return self::FAILURE;
        }

        $this->line("Vista   : {$schema}.{$view}");
        $this->line("Usuario : {$user->email}");
        $this->newLine();

        // ── 1. Iniciar el export en Graph-Fabric ────────────────────────────
        $this->info('[1/4] Iniciando export en Graph-Fabric...');
        $start = $exports->start($user, $schema, $view, ['max_rows' => 500_000]);

        if (($start['success'] ?? false) !== true) {
            $this->error('  Fallo: ' . ($start['message'] ?? 'sin detalle'));
            return self::FAILURE;
        }

        $jobId = (string) $start['job_id'];
        $this->line("  job_id: {$jobId}");

        // ── 2. Esperar a que termine ────────────────────────────────────────
        $this->info('[2/4] Esperando a que el export termine...');
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
                $this->line("  Listo: " . number_format($rows) . ' filas');
                break;
            }

            if ($state === 'failed') {
                $this->error('  Fallo: ' . ($status['message'] ?? 'sin detalle'));
                return self::FAILURE;
            }

            $this->line('  ' . ($status['message'] ?? $state) . ' (' . ($status['progress'] ?? 0) . '%)');
            sleep(3);
        }

        // ── 3. Descargar el .gz a disco ─────────────────────────────────────
        $this->info('[3/4] Descargando el archivo desde Graph-Fabric...');
        $download = $exports->download($jobId);

        if (($download['success'] ?? false) !== true) {
            $this->error('  Fallo: ' . ($download['message'] ?? 'sin detalle'));
            return self::FAILURE;
        }

        $path  = (string) $download['path'];
        $bytes = (int) @filesize($path);
        $this->line('  Archivo : ' . $path);
        $this->line('  Tamanio : ' . number_format($bytes) . ' bytes');
        $this->line('  Formato : ' . ($download['format'] ?? '?'));

        // ── 4. Verificar el contenido ───────────────────────────────────────
        $this->info('[4/4] Verificando el contenido...');

        $hex = $this->hexPreview($path);
        $this->line('  Primeros 16 bytes : ' . $hex);
        $this->line('  Identificado como : ' . $this->identify($hex));

        $result = $this->inspectNdjson($path);

        if ($result === null) {
            @unlink($path);
            $this->newLine();
            $this->error('FALLO: el archivo no contiene NDJSON valido.');
            $this->line('El problema esta ANTES de Laravel: en Graph-Fabric o en la descarga.');
            return self::FAILURE;
        }

        [$lineCount, $columns, $sample] = $result;

        $this->line('  Lineas leidas     : ' . number_format($lineCount));
        $this->line('  Columnas por fila : ' . count($columns));
        $this->newLine();
        $this->line('  Columnas: ' . implode(', ', array_slice($columns, 0, 10))
            . (count($columns) > 10 ? ', ...' : ''));
        $this->newLine();
        $this->line('  Primera fila:');
        foreach (array_slice($sample, 0, 5, true) as $key => $value) {
            $this->line(sprintf('    %-28s %s', $key, mb_strimwidth((string) $value, 0, 60, '...')));
        }

        // ── 4b. Medir la longitud real de una columna (diagnostico truncamiento) ──
        $measureCol = (string) ($this->option('measure-col') ?? '');
        if ($measureCol !== '') {
            $this->newLine();
            $this->info("[extra] Midiendo longitud de la columna '{$measureCol}' en el NDJSON CRUDO...");

            $stats = $this->measureColumn($path, $measureCol);

            if ($stats === null) {
                $this->warn("  La columna '{$measureCol}' no existe en el NDJSON. Columnas: "
                    . implode(', ', array_slice($columns, 0, 15)));
            } else {
                [$maxLen, $maxRowSample, $filasMedidas, $filasNoVacias, $distribucion] = $stats;
                $this->line('  Filas medidas       : ' . number_format($filasMedidas));
                $this->line('  Filas con contenido : ' . number_format($filasNoVacias));
                $this->line('  Longitud MAXIMA     : ' . number_format($maxLen) . ' caracteres');
                $this->newLine();
                $this->line('  Distribucion de longitudes (cuantas filas caen en cada rango):');
                foreach ($distribucion as $rango => $cuenta) {
                    if ($cuenta > 0) {
                        $this->line(sprintf('    %-14s %s', $rango, number_format($cuenta)));
                    }
                }
                $this->newLine();
                $this->line('  Valor mas largo encontrado (ultimos 80 chars):');
                $this->line('    ...' . mb_substr($maxRowSample, max(0, mb_strlen($maxRowSample) - 80)));
                $this->newLine();

                // Veredicto automatico sobre el punto de truncamiento
                if (in_array($maxLen, [255, 256, 1000, 4000, 8000], true)
                    || ($maxLen >= 250 && $maxLen <= 256)
                    || ($maxLen >= 3990 && $maxLen <= 4000)
                    || ($maxLen >= 7990 && $maxLen <= 8000)) {
                    $this->error("  >> El maximo cae JUSTO en un limite clasico de SQL/ODBC ({$maxLen}).");
                    $this->error('  >> El truncamiento OCURRE EN GRAPH-FABRIC (SQL/ODBC), no en Laravel.');
                    $this->line('  >> Revisar en Graph-Fabric: CAST/CONVERT/LEFT sobre la columna, un');
                    $this->line('     VARCHAR(n)/NVARCHAR(n) de longitud fija en la vista, o el driver');
                    $this->line('     ODBC/pyodbc que trunca long-data. Cast explicito a VARCHAR(MAX).');
                } elseif ($maxLen > 8000) {
                    $this->info("  >> El NDJSON YA trae textos largos (> 8000). El dato NO viene truncado de Graph.");
                    $this->line('  >> Si el Excel sale corto, el problema esta en Laravel o en el visor.');
                } else {
                    $this->warn("  >> Maximo {$maxLen}. Si esperabas mas, el corte esta en Graph-Fabric.");
                    $this->line('  >> Compara este numero con lo que ves en la BD directamente.');
                }
            }
        }

        $this->newLine();
        $this->info('OK: el archivo del servidor esta correcto.');
        $this->newLine();
        $this->line('Si la grilla sigue mostrando datos binarios, el problema NO esta aqui:');
        $this->line('  1. Abra la consola del navegador y busque "[parseBlob] primeros bytes:".');
        $this->line('  2. Si dice 7b22... el body llego bien y el fallo es del parseo.');
        $this->line('  3. Si dice 1f8b... Apache no esta descomprimiendo; revise Content-Encoding.');
        $this->line('  4. Si no coincide con ningun magic conocido, un filtro comprimio la');
        $this->line('     respuesta (brotli/deflate) sin dejar el Content-Encoding. Revise');
        $this->line('     public/.htaccess y que no exista un "Header unset Content-Encoding".');

        @unlink($path);

        return self::SUCCESS;
    }

    /**
     * Recorre TODO el NDJSON y mide la longitud de una columna de texto.
     *
     * Sirve para probar de forma objetiva si el dato ya viene truncado desde
     * Graph-Fabric: si el maximo cae en 255/4000/8000, el corte es de SQL/ODBC.
     *
     * @return array{0:int,1:string,2:int,3:int,4:array<string,int>}|null
     */
    private function measureColumn(string $path, string $column): ?array
    {
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            return null;
        }

        $maxLen        = 0;
        $maxSample     = '';
        $filasMedidas  = 0;
        $filasNoVacias = 0;
        $existe        = false;

        // Rangos para ver dónde se acumulan las longitudes (detecta el "techo").
        $distribucion = [
            '0'          => 0,
            '1-100'      => 0,
            '101-255'    => 0,
            '256-1000'   => 0,
            '1001-4000'  => 0,
            '4001-8000'  => 0,
            '8001-32767' => 0,
            '>32767'     => 0,
        ];

        try {
            while (($line = gzgets($gz)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }

                if (!array_key_exists($column, $row)) {
                    // La columna no está: se confirma en la primera fila y se sale.
                    if ($filasMedidas === 0) {
                        return null;
                    }
                    continue;
                }

                $existe = true;
                $filasMedidas++;

                $value = (string) ($row[$column] ?? '');
                $len   = mb_strlen($value);

                if ($len > 0) {
                    $filasNoVacias++;
                }

                if ($len > $maxLen) {
                    $maxLen    = $len;
                    $maxSample = $value;
                }

                $distribucion[match (true) {
                    $len === 0     => '0',
                    $len <= 100    => '1-100',
                    $len <= 255    => '101-255',
                    $len <= 1000   => '256-1000',
                    $len <= 4000   => '1001-4000',
                    $len <= 8000   => '4001-8000',
                    $len <= 32767  => '8001-32767',
                    default        => '>32767',
                }]++;
            }
        } finally {
            gzclose($gz);
        }

        return $existe
            ? [$maxLen, $maxSample, $filasMedidas, $filasNoVacias, $distribucion]
            : null;
    }

    private function resolveUser(): ?User
    {
        $email = $this->option('user');

        if ($email) {
            return User::where('email', $email)->first();
        }

        return User::orderBy('id')->first();
    }

    /** Primeros 16 bytes en hex, separados por espacios. */
    private function hexPreview(string $path): string
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '(ilegible)';
        }

        $bytes = (string) fread($handle, 16);
        fclose($handle);

        return implode(' ', array_map(
            static fn (string $b): string => str_pad(dechex(ord($b)), 2, '0', STR_PAD_LEFT),
            str_split($bytes) ?: []
        ));
    }

    /** Traduce los primeros bytes a un formato conocido. */
    private function identify(string $hex): string
    {
        $prefix = str_replace(' ', '', substr($hex, 0, 5));

        foreach (self::MAGIC as $magic => $label) {
            if (str_starts_with($prefix, $magic)) {
                return $label;
            }
        }

        return 'DESCONOCIDO (posible brotli u otra compresion sin magic bytes)';
    }

    /**
     * Descomprime y valida el NDJSON.
     *
     * @return array{0:int,1:list<string>,2:array<string,mixed>}|null
     */
    private function inspectNdjson(string $path): ?array
    {
        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            return null;
        }

        $lineCount = 0;
        $columns   = [];
        $sample    = [];

        try {
            // Se leen unas pocas lineas: basta para saber si el formato es sano.
            while ($lineCount < 50 && ($line = gzgets($gz)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);
                if (!is_array($row) || $row === []) {
                    return null;
                }

                if ($columns === []) {
                    $columns = array_map('strval', array_keys($row));
                    $sample  = $row;
                }

                $lineCount++;
            }
        } finally {
            gzclose($gz);
        }

        return $lineCount > 0 ? [$lineCount, $columns, $sample] : null;
    }
}
