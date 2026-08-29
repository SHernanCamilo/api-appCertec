<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use XMLReader;
use ZipArchive;

/**
 * Diagnostica un .xlsx que Excel abre "reparando y quitando el contenido".
 *
 * Dice exactamente QUÉ está mal, en vez de dejarnos adivinar:
 *   - Si el ZIP está completo y qué partes tiene
 *   - Si el XML de la hoja está truncado (no cierra en </worksheet>)
 *   - Si tiene caracteres que XML prohíbe, y en qué línea/columna
 *   - Muestra el fragmento alrededor del error
 *
 * Uso:
 *   php artisan fabric:diagnose-xlsx storage/app/fabric_exports/<id>/archivo.xlsx
 */
final class DiagnoseXlsxCommand extends Command
{
    protected $signature = 'fabric:diagnose-xlsx
        {path : Ruta al .xlsx a revisar}
        {--dump= : Guardar el XML de la hoja en esta ruta para inspeccionarlo}';

    protected $description = 'Dice por qué un .xlsx sale corrupto (Excel lo abre reparando)';

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        if (!is_file($path)) {
            $this->error("No existe el archivo: {$path}");

            return self::FAILURE;
        }

        $this->line('Archivo : ' . $path);
        $this->line('Tamaño  : ' . $this->human((int) filesize($path)));
        $this->newLine();

        // ── 1. Estructura del ZIP ────────────────────────────────────────────
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            $this->error('El archivo NO es un ZIP válido (un .xlsx es un ZIP).');

            return self::FAILURE;
        }

        $this->info('── Partes del ZIP ──');
        $esperadas = [
            '[Content_Types].xml',
            '_rels/.rels',
            'xl/workbook.xml',
            'xl/_rels/workbook.xml.rels',
            'xl/styles.xml',
            'xl/worksheets/sheet1.xml',
        ];

        foreach ($esperadas as $parte) {
            $idx = $zip->locateName($parte);
            if ($idx === false) {
                $this->line("  ✗ FALTA  {$parte}");
                continue;
            }
            $stat = $zip->statIndex($idx);
            $this->line(sprintf('  ✓ %-32s %s', $parte, $this->human((int) ($stat['size'] ?? 0))));
        }

        // ── 2. Extraer la hoja ───────────────────────────────────────────────
        $sheetIdx = $zip->locateName('xl/worksheets/sheet1.xml');
        if ($sheetIdx === false) {
            $zip->close();
            $this->error('No hay xl/worksheets/sheet1.xml: el archivo está incompleto.');

            return self::FAILURE;
        }

        $tmp = ($this->option('dump') ?: sys_get_temp_dir() . '/diag_sheet_' . getmypid() . '.xml');
        $ok  = copy("zip://{$path}#xl/worksheets/sheet1.xml", $tmp);
        $zip->close();

        if (!$ok || !is_file($tmp)) {
            $this->error('No se pudo extraer la hoja del ZIP.');

            return self::FAILURE;
        }

        $sheetSize = (int) filesize($tmp);
        $this->newLine();
        $this->info('── XML de la hoja ──');
        $this->line('  Tamaño descomprimido: ' . $this->human($sheetSize));

        // ── 3. ¿Está truncado? ───────────────────────────────────────────────
        $fh = fopen($tmp, 'rb');
        fseek($fh, -60, SEEK_END);
        $tail = (string) fread($fh, 60);
        fclose($fh);

        if (str_contains($tail, '</worksheet>')) {
            $this->line('  ✓ Cierra correctamente en </worksheet>');
        } else {
            $this->error('  ✗ TRUNCADO: no termina en </worksheet>');
            $this->line('    Últimos bytes: ' . $this->visible(substr($tail, -50)));
            $this->newLine();
            $this->warn('  CAUSA: la escritura del XML se cortó (disco lleno, cuota, o fwrite parcial).');
            $this->warn('  Revise el espacio libre: df -h  y la cuota de la cuenta.');
            $this->limpiar($tmp);

            return self::FAILURE;
        }

        // ── 4. Validar el XML completo en streaming ──────────────────────────
        libxml_use_internal_errors(true);
        libxml_clear_errors();

        $reader = new XMLReader();
        $reader->open($tmp);
        while (@$reader->read()) {
            // recorrer todo
        }
        $errores = libxml_get_errors();
        $reader->close();
        libxml_clear_errors();

        if ($errores === []) {
            $this->line('  ✓ XML bien formado (Excel NO debería reparar este archivo)');
            $this->newLine();
            $this->info('Resultado: el archivo está correcto.');
            $this->limpiar($tmp);

            return self::SUCCESS;
        }

        $this->error('  ✗ XML INVÁLIDO');
        $this->newLine();
        $this->info('── Errores encontrados ──');

        foreach (array_slice($errores, 0, 5) as $e) {
            $this->line(sprintf(
                '  línea %d, columna %d (code %d): %s',
                $e->line,
                $e->column,
                $e->code,
                trim($e->message)
            ));
        }

        // ── 5. Mostrar el fragmento culpable ─────────────────────────────────
        $primerError = $errores[0];
        $this->newLine();
        $this->info('── Fragmento alrededor de la línea ' . $primerError->line . ' ──');

        $fh    = fopen($tmp, 'rb');
        $linea = 0;
        while (($l = fgets($fh)) !== false) {
            $linea++;
            if ($linea === $primerError->line) {
                $frag = mb_strimwidth($l, 0, 400, '...');
                $this->line('  ' . $this->visible($frag));
                break;
            }
        }
        fclose($fh);

        $this->newLine();
        $this->warn('Copie este bloque completo y páselo al equipo de desarrollo.');
        $this->limpiar($tmp);

        return self::FAILURE;
    }

    /** Hace visibles los bytes no imprimibles para poder identificarlos. */
    private function visible(string $s): string
    {
        return (string) preg_replace_callback(
            '/[^\x20-\x7E]/',
            static fn (array $m): string => '[' . strtoupper(bin2hex($m[0])) . ']',
            $s
        );
    }

    private function limpiar(string $tmp): void
    {
        if (!$this->option('dump')) {
            @unlink($tmp);
        } else {
            $this->line('XML guardado en: ' . $tmp);
        }
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size  = (float) $bytes;
        $i     = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 2) . ' ' . $units[$i];
    }
}
