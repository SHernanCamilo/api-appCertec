<?php

declare(strict_types=1);

namespace App\Http\Requests\FichasTecnicas;

use App\Models\Accounting\FichasTecnicas\FichDetalle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de los ítems de servicio (paso 2 del generador).
 *
 * Acepta un único ítem o un lote (`items[]`), lo que permite guardar la tabla
 * completa en una sola petición en lugar de un POST por fila como hacía
 * `generador/acciones/insertar2.php`.
 */
class StoreDetalleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reglasItem = [
            'tipo_liquidacion' => ['nullable', 'string', 'max:100'],
            'tipo_servicio'    => ['nullable', 'string', 'max:150'],
            'id_tipo_servicio' => ['nullable', 'integer', 'exists:fich_tipos_servicio,id'],
            'cups'             => ['nullable', 'string', 'max:10'],
            'grupo'            => ['nullable', 'string', 'max:3'],
            'subgrupo'         => ['nullable', 'string', 'max:4'],
            'forma_pago'       => ['nullable', 'string', Rule::in(FichDetalle::FORMAS_PAGO)],
            'homologo'         => ['nullable', 'string', 'max:60'],
            'variacion'        => ['nullable', 'string', 'max:10'],
            'valor'            => ['required', 'numeric', 'min:0'],
            'id_obs_item'      => ['nullable', 'integer', 'exists:fich_obs_items,id'],
            'novedad'          => ['nullable', 'string', 'max:100'],
        ];

        if ($this->esLote()) {
            $reglas = ['items' => ['required', 'array', 'min:1']];

            foreach ($reglasItem as $campo => $regla) {
                $reglas["items.*.{$campo}"] = $regla;
            }

            return $reglas;
        }

        return $reglasItem;
    }

    public function esLote(): bool
    {
        return $this->has('items');
    }

    /**
     * Ítems normalizados como lista de arreglos.
     *
     * @return list<array<string, mixed>>
     */
    public function items(): array
    {
        if ($this->esLote()) {
            /** @var list<array<string, mixed>> $items */
            $items = $this->validated()['items'];

            return $items;
        }

        return [$this->validated()];
    }
}
