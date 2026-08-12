<?php

declare(strict_types=1);

namespace App\Services\Fabric\Export;

/**
 * Reglas de formato de valores para exports de Fabric.
 *
 * Lógica pura y sin dependencias: se extrajo de FabricStreamExportJob para
 * poder cubrirla con tests unitarios. El problema que resuelve es que Excel
 * interpreta "036004835" como el número 36004835 y borra el cero inicial,
 * lo que corrompe cuentas bancarias, NIT, placas y códigos.
 */
final class ExportValueFormatter
{
    /**
     * Patrones de nombres de columna que SIEMPRE se tratan como texto.
     * Actúa como respaldo cuando Python ya convirtió el valor a entero y el
     * cero inicial se perdió antes de llegar aquí.
     *
     * @var list<string>
     */
    public const TEXT_COLUMN_PATTERNS = [
        'nro_cuenta', 'num_cuenta', 'numero_cuenta', 'cuenta_bancaria',
        'placa', 'codigo', 'cod_', 'nit', 'documento', 'cedula',
        'identificacion', 'telefono', 'celular', 'consecutivo',
        'codigo_proveedor', 'codigo_banco', 'num_', 'nro_',
        'referencia', 'poliza', 'contrato', 'cuenta',
    ];

    /**
     * Determina qué columnas deben escribirse como texto.
     *
     * Usa dos estrategias complementarias:
     *   1. El nombre de la columna coincide con TEXT_COLUMN_PATTERNS
     *   2. El valor de la primera fila tiene forma de número con cero inicial
     *
     * @param  list<string>    $headers  Nombres de columna (siempre string)
     * @param  list<mixed>|null $firstRow Valores de la primera fila, en el mismo orden
     * @return array<int, true>          Índices de columna que van como texto
     */
    public static function detectTextColumns(array $headers, ?array $firstRow = null): array
    {
        $textColumns = [];

        foreach ($headers as $index => $header) {
            // Las columnas con nombre numérico (ej. "315" en vistas pivot) llegan
            // como int desde array_keys(), por eso el cast explícito.
            $headerLower = strtolower((string) $header);

            foreach (self::TEXT_COLUMN_PATTERNS as $pattern) {
                if (str_contains($headerLower, $pattern)) {
                    $textColumns[$index] = true;
                    break;
                }
            }

            if (isset($textColumns[$index]) || $firstRow === null) {
                continue;
            }

            if (self::looksLikeLeadingZeroNumber($firstRow[$index] ?? null)) {
                $textColumns[$index] = true;
            }
        }

        return $textColumns;
    }

    /**
     * Dígitos que Excel representa sin pérdida de precisión.
     *
     * Excel guarda los números como double IEEE 754, con 15 dígitos
     * significativos. Un valor más largo (llaves compuestas, IDs de 20+ dígitos)
     * se redondea y se muestra en notación científica (6,00621E+36), o si excede
     * el rango del double se convierte en INF. En ambos casos el dato se pierde.
     */
    private const MAX_EXCEL_DIGITS = 15;

    /**
     * ¿El valor se puede escribir como número en Excel sin perder información?
     *
     * Devuelve false para:
     *   - Números con más de 15 dígitos (se redondearían: 6006205000000000001 → 6,00621E+18)
     *   - Valores que desbordan el double (→ INF)
     *   - Notación científica en el origen ("1E36")
     *   - Ceros iniciales ("036004835"), que deben conservarse como texto
     *
     * Estos casos se escriben como string: es la única forma de que Excel
     * muestre el valor exacto.
     */
    public static function isSafeExcelNumber(mixed $value): bool
    {
        if (is_int($value)) {
            return abs($value) < 10 ** self::MAX_EXCEL_DIGITS;
        }

        if (is_float($value)) {
            return is_finite($value) && abs($value) < 10 ** self::MAX_EXCEL_DIGITS;
        }

        if (!is_string($value)) {
            return false;
        }

        $trimmed = trim($value);

        // Solo enteros o decimales en notación normal. El patrón rechaza a
        // propósito los ceros iniciales y la notación científica.
        if (preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/', $trimmed) !== 1) {
            return false;
        }

        $digits = strlen(str_replace(['-', '.'], '', $trimmed));

        return $digits <= self::MAX_EXCEL_DIGITS;
    }

    /**
     * Formatea un valor para escribirlo en CSV.
     *
     * Las columnas de texto se envuelven en la fórmula ="valor", que es la
     * forma que Excel respeta sin reinterpretar el contenido.
     */
    public static function forCsv(mixed $value, bool $isTextColumn): mixed
    {
        if ($isTextColumn && $value !== null && $value !== '') {
            $asString = (string) $value;

            return is_numeric($asString) ? '="' . $asString . '"' : $value;
        }

        if (self::looksLikeLeadingZeroNumber($value)) {
            return '="' . $value . '"';
        }

        // Número demasiado largo para el double de Excel: protegerlo como texto
        // para que no se muestre como 6,00621E+36 al abrir el CSV.
        if (is_string($value) && $value !== '' && is_numeric($value) && !self::isSafeExcelNumber($value)) {
            return '="' . $value . '"';
        }

        // "1500.00" → "1500": evita que Excel muestre decimales que no existen
        if (is_string($value) && is_numeric($value) && str_contains($value, '.')) {
            return rtrim(rtrim($value, '0'), '.');
        }

        if (is_float($value)) {
            return floor($value) === $value ? (int) $value : $value;
        }

        return $value;
    }

    /**
     * Normaliza un valor de celda: quita saltos de línea y tabs que romperían
     * la estructura del CSV.
     */
    public static function sanitize(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return str_replace(["\r\n", "\r", "\n", "\t"], ' ', $value);
    }

    /**
     * ¿El valor es una fecha ISO? Se usa para aplicar formato de fecha en xlsx.
     * Soporta: "2026-08-06T14:15:52", "2026-08-06 14:15:52", "2026-08-06T14:15:52.000Z"
     */
    public static function looksLikeIsoDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value) === 1;
    }

    /**
     * ¿El valor es solo una fecha sin hora? (ej: "2026-08-06")
     */
    public static function looksLikeDateOnly(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    /**
     * Convierte una fecha ISO (con T) a formato legible para Excel/CSV.
     *
     * "2026-08-06T14:15:52"     → "2026-08-06 14:15:52"
     * "2026-08-06T14:15:52.000" → "2026-08-06 14:15:52"
     * "2026-08-06T14:15:52.000Z"→ "2026-08-06 14:15:52"
     * "2026-08-06"              → "2026-08-06"
     * null / vacío              → valor original
     */
    public static function normalizeDate(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        // Quita la T y los milisegundos/Z del final
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})(?:\.\d+)?Z?$/', $value, $m)) {
            return "{$m[1]} {$m[2]}";
        }

        // Solo fecha sin hora: dejar como está
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        return $value;
    }

    /**
     * Convierte una fecha ISO a un número serial de Excel (OLE Automation Date).
     *
     * Excel cuenta los días desde 1900-01-00 (sí, es un bug histórico que
     * incluye el falso 29/feb/1900). Para que Excel reconozca la celda como
     * fecha, hay que escribir este número + aplicar un NumberFormat de fecha.
     *
     * @return float|null Null si no es una fecha válida
     */
    public static function toExcelSerial(mixed $value): ?float
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            $dt = new \DateTime(str_replace('T', ' ', $value));
        } catch (\Throwable) {
            return null;
        }

        // Días desde 1900-01-01 (Excel serial: 1 = 1900-01-01)
        $epoch   = new \DateTime('1899-12-30'); // Excel epoch (incluye el bug del 29/feb/1900)
        $diff    = $epoch->diff($dt);
        $days    = (int) $diff->format('%a');
        $seconds = $dt->format('H') * 3600 + $dt->format('i') * 60 + $dt->format('s');

        if ($diff->invert) {
            return null; // Fecha anterior a 1900, Excel no la soporta
        }

        return $days + ($seconds / 86400);
    }

    /**
     * ¿El valor es un número con cero inicial? (ej. "036004835")
     */
    private static function looksLikeLeadingZeroNumber(mixed $value): bool
    {
        return is_string($value) && preg_match('/^0\d+$/', $value) === 1;
    }
}
