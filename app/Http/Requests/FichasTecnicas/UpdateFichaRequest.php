<?php

declare(strict_types=1);

namespace App\Http\Requests\FichasTecnicas;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la edición de una ficha en estado editable.
 */
class UpdateFichaRequest extends FormRequest
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
        return [
            'id_agremiacion'     => ['sometimes', 'integer', 'exists:fich_agremiaciones,id'],
            'id_objeto_contrato' => ['sometimes', 'integer', 'exists:fich_objetos_contrato,id'],
            'id_especialidad'    => ['sometimes', 'integer', 'exists:fich_especialidades,id'],
            'vlr_contrato'       => ['sometimes', 'numeric', 'min:0'],
            'fecha_ini'          => ['sometimes', 'date'],
            'fecha_fin'          => ['sometimes', 'date', 'after_or_equal:fecha_ini'],
            'id_sucursal'        => ['nullable', 'integer', 'exists:config_ubi_sucursales,id'],
            'sucursal_legacy'    => ['nullable', 'string', 'max:100'],
            'profesionales'      => ['sometimes', 'array', 'min:1'],
            'profesionales.*'    => ['integer', 'exists:fich_profesionales,id'],
            'obs_os'             => ['nullable', 'string', 'max:500'],
            'novedad'            => ['nullable', 'string', 'max:500'],
        ];
    }
}
