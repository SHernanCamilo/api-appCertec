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
        {--timeout=300 : Segundos maximos de espera del export}';

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
        @unlink($path);

        if ($result === null) {
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

        return self::SUCCESS;
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
