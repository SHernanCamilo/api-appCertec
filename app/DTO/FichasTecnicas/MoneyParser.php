<?php

declare(strict_types=1);

namespace App\DTO\FichasTecnicas;

/**
 * Conversión de los valores monetarios del sistema JADE legacy a float.
 *
 * En el legacy `ficha.vlr_contrato` y `detalles_ficha.valor` eran `varchar`
 * y almacenaban el valor ya formateado por el JavaScript del formulario
 * (`MASK(this,this.value,'-$###,###,###,##0.00',1)`), por lo que en la base
 * conviven:
 *
 *   "$1,250,000.00"   formato US (coma = miles, punto = decimal)
 *   "$1.234.567,89"   formato CO (punto = miles, coma = decimal)
 *   "980.500"         formato CO sin decimales
 *   "NULL" / ""       campos vacíos guardados como texto
 *   1250000           numérico ya limpio
 *
 * Heurística aplicada, en orden:
 *  1. Si ya es numérico se devuelve tal cual.
 *  2. Si hay coma seguida de 1–2 dígitos al final → la coma es el decimal (CO).
 *  3. Si hay más de un punto → los puntos son separadores de miles.
 *  4. Si hay un único punto seguido de exactamente 3 dígitos y no hay coma →
 *     se interpreta como separador de miles (CO). Los importes en pesos
 *     colombianos no usan 3 decimales, así que "980.500" son 980.500 pesos.
 *  5. En cualquier otro caso el punto es el separador decimal.
 */
final class MoneyParser
{
    public static function aFloat(mixed $valor): float
    {
        return self::aFloatONull($valor) ?? 0.0;
    }

    public static function aFloatONull(mixed $valor): ?float
    {
        if ($valor === null) {
            return null;
        }

        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }

        $texto = trim((string) $valor);

        if ($texto === '' || strcasecmp($texto, 'NULL') === 0) {
            return null;
        }

        // Atajo solo cuando no hay separadores: sin ambigüedad posible.
        // Con separadores se aplica siempre la heurística, porque cadenas como
        // "980.500" son numéricas para PHP (980.5) pero en los datos legacy
        // significan 980.500 pesos.
        if (is_numeric($texto) && ! str_contains($texto, '.') && ! str_contains($texto, ',')) {
            return (float) $texto;
        }

        $negativo = str_contains($texto, '-');
        $limpio   = preg_replace('/[^0-9,.]/', '', $texto) ?? '';

        if ($limpio === '') {
            return null;
        }

        $limpio = self::normalizarSeparadores($limpio);

        if (! is_numeric($limpio)) {
            return null;
        }

        $numero = (float) $limpio;

        return $negativo && $numero > 0 ? -$numero : $numero;
    }

    private static function normalizarSeparadores(string $limpio): string
    {
        // Coma decimal explícita: "1.234.567,89"
        if (preg_match('/,\d{1,2}$/', $limpio) === 1) {
            return str_replace(['.', ','], ['', '.'], $limpio);
        }

        // Coma como separador de miles: "1,234,567.89"
        if (str_contains($limpio, ',')) {
            return str_replace(',', '', $limpio);
        }

        $puntos = substr_count($limpio, '.');

        // Varios puntos: todos son separadores de miles.
        if ($puntos > 1) {
            return str_replace('.', '', $limpio);
        }

        // Un punto con exactamente 3 dígitos detrás: separador de miles (CO).
        if ($puntos === 1 && preg_match('/\.\d{3}$/', $limpio) === 1) {
            return str_replace('.', '', $limpio);
        }

        return $limpio;
    }
}
