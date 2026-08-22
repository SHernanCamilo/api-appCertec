<?php

namespace App\Services\TalentoHumano\CuadroTurnos;

/**
 * Evaluador de fórmulas matemáticas seguro.
 *
 * Soporta:
 *   - Variables entre corchetes: [Horas Nocturnas], [Valor Hora]
 *   - Operadores: + - * / ( )
 *   - Funciones: Round(), Floor(), Ceil(), Min(), Max(), Abs()
 *   - Números decimales
 *
 * NO usa eval(). Parsea la expresión de forma segura.
 */
class FormulaEvaluator
{
    private string $expression;
    private int $pos;
    private int $length;

    /**
     * Evalúa una fórmula reemplazando variables con sus valores.
     *
     * @param string $formula  Ej: "[Horas Nocturnas] * [Valor Hora] * 0.35"
     * @param array  $variables  Ej: ['Horas Nocturnas' => 40, 'Valor Hora' => 12500]
     * @return array  ['success' => bool, 'resultado' => float, 'formula_resuelta' => string, 'error' => ?string]
     */
    public function evaluar(string $formula, array $variables): array
    {
        try {
            // Validar que no haya caracteres peligrosos
            $this->validarSeguridad($formula);

            // Reemplazar variables por sus valores
            $expresionResuelta = $formula;
            foreach ($variables as $nombre => $valor) {
                $expresionResuelta = str_replace("[{$nombre}]", (string) (float) $valor, $expresionResuelta);
            }

            // Verificar que no quedaron variables sin resolver
            if (preg_match('/\[([^\]]+)\]/', $expresionResuelta, $sinResolver)) {
                return [
                    'success' => false,
                    'resultado' => 0,
                    'formula_resuelta' => $expresionResuelta,
                    'error' => "Variable sin valor: [{$sinResolver[1]}]",
                ];
            }

            // Evaluar la expresión matemática
            $resultado = $this->parse($expresionResuelta);

            return [
                'success' => true,
                'resultado' => round($resultado, 2),
                'formula_resuelta' => $expresionResuelta,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'resultado' => 0,
                'formula_resuelta' => $formula,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extrae las variables de una fórmula.
     */
    public function extraerVariables(string $formula): array
    {
        preg_match_all('/\[([^\]]+)\]/', $formula, $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * Valida que la fórmula no contenga código malicioso.
     */
    private function validarSeguridad(string $formula): void
    {
        // Solo permitir: números, operadores, paréntesis, corchetes, puntos, comas, espacios y nombres de funciones
        $limpia = preg_replace('/\[[^\]]+\]/', '', $formula); // Quitar variables
        $limpia = preg_replace('/\b(Round|Floor|Ceil|Min|Max|Abs)\b/i', '', $limpia); // Quitar funciones permitidas

        if (preg_match('/[a-zA-Z_$]/', $limpia)) {
            throw new \InvalidArgumentException('La fórmula contiene caracteres no permitidos. Solo se permiten números, operadores (+, -, *, /), paréntesis, y funciones (Round, Floor, Ceil, Min, Max, Abs).');
        }
    }

    /**
     * Parser recursivo descendente para expresiones matemáticas.
     */
    private function parse(string $expression): float
    {
        $this->expression = preg_replace('/\s+/', '', $expression);
        $this->pos = 0;
        $this->length = strlen($this->expression);

        $result = $this->parseExpression();

        if ($this->pos < $this->length) {
            throw new \RuntimeException("Carácter inesperado en posición {$this->pos}: '{$this->expression[$this->pos]}'");
        }

        return $result;
    }

    private function parseExpression(): float
    {
        $result = $this->parseTerm();

        while ($this->pos < $this->length && in_array($this->expression[$this->pos], ['+', '-'])) {
            $op = $this->expression[$this->pos];
            $this->pos++;
            $term = $this->parseTerm();
            $result = $op === '+' ? $result + $term : $result - $term;
        }

        return $result;
    }

    private function parseTerm(): float
    {
        $result = $this->parseFactor();

        while ($this->pos < $this->length && in_array($this->expression[$this->pos], ['*', '/'])) {
            $op = $this->expression[$this->pos];
            $this->pos++;
            $factor = $this->parseFactor();

            if ($op === '/') {
                if ($factor == 0) {
                    throw new \RuntimeException('División por cero');
                }
                $result /= $factor;
            } else {
                $result *= $factor;
            }
        }

        return $result;
    }

    private function parseFactor(): float
    {
        // Signo negativo
        if ($this->pos < $this->length && $this->expression[$this->pos] === '-') {
            $this->pos++;
            return -$this->parseFactor();
        }

        // Signo positivo
        if ($this->pos < $this->length && $this->expression[$this->pos] === '+') {
            $this->pos++;
            return $this->parseFactor();
        }

        // Funciones: Round(...), Floor(...), etc.
        if (preg_match('/\G(Round|Floor|Ceil|Min|Max|Abs)\(/i', $this->expression, $m, 0, $this->pos)) {
            return $this->parseFunction($m[1]);
        }

        // Paréntesis
        if ($this->pos < $this->length && $this->expression[$this->pos] === '(') {
            $this->pos++; // skip (
            $result = $this->parseExpression();
            if ($this->pos >= $this->length || $this->expression[$this->pos] !== ')') {
                throw new \RuntimeException('Paréntesis sin cerrar');
            }
            $this->pos++; // skip )
            return $result;
        }

        // Número
        return $this->parseNumber();
    }

    private function parseFunction(string $name): float
    {
        $this->pos += strlen($name) + 1; // skip "FuncName("

        $args = [$this->parseExpression()];

        // Funciones con múltiples argumentos (Min, Max)
        while ($this->pos < $this->length && $this->expression[$this->pos] === ',') {
            $this->pos++; // skip comma
            $args[] = $this->parseExpression();
        }

        if ($this->pos >= $this->length || $this->expression[$this->pos] !== ')') {
            throw new \RuntimeException("Función {$name}() sin cerrar paréntesis");
        }
        $this->pos++; // skip )

        return match (strtolower($name)) {
            'round' => round($args[0], isset($args[1]) ? (int) $args[1] : 0),
            'floor' => floor($args[0]),
            'ceil'  => ceil($args[0]),
            'min'   => min(...$args),
            'max'   => max(...$args),
            'abs'   => abs($args[0]),
            default => throw new \RuntimeException("Función desconocida: {$name}"),
        };
    }

    private function parseNumber(): float
    {
        $start = $this->pos;

        while ($this->pos < $this->length && (is_numeric($this->expression[$this->pos]) || $this->expression[$this->pos] === '.')) {
            $this->pos++;
        }

        if ($start === $this->pos) {
            $char = $this->pos < $this->length ? $this->expression[$this->pos] : 'EOF';
            throw new \RuntimeException("Se esperaba un número en posición {$this->pos}, se encontró: '{$char}'");
        }

        return (float) substr($this->expression, $start, $this->pos - $start);
    }
}
