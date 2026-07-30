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
     */
    public static function looksLikeIsoDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1;
    }

    /**
     * ¿El valor es un número con cero inicial? (ej. "036004835")
     */
    private static function looksLikeLeadingZeroNumber(mixed $value): bool
    {
        return is_string($value) && preg_match('/^0\d+$/', $value) === 1;
    }
}
