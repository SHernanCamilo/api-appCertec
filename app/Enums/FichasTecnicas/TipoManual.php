<?php

declare(strict_types=1);

namespace App\Enums\FichasTecnicas;

/**
 * Manuales tarifarios de referencia usados en la homologación de servicios.
 *
 * En el legacy estos valores estaban escritos como cadenas literales en cada
 * consulta (`WHERE tipo_manual='ISS 2001'`, `'SOAT'`, `'INSTITUCIONAL'`) y en
 * los <option> de los formularios, con el riesgo de desalineación.
 */
enum TipoManual: string
{
    case Iss2001       = 'ISS 2001';
    case Soat          = 'SOAT';
    case Institucional = 'INSTITUCIONAL';

    public function label(): string
    {
        return match ($this) {
            self::Iss2001       => 'Manual ISS 2001',
            self::Soat          => 'Manual SOAT',
            self::Institucional => 'Tarifario Institucional',
        };
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
