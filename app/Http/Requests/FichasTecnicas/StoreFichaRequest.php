<?php

declare(strict_types=1);

namespace App\Http\Requests\FichasTecnicas;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la cabecera de una ficha técnica (paso 1 del generador).
 *
 * El legacy validaba en JavaScript (`form1.php`) y con un `foreach` sobre un
 * arreglo de nombres de campo en `insertar.php`, sin comprobar existencia de
 * llaves foráneas ni coherencia de fechas en el servidor.
 */
class StoreFichaRequest extends FormRequest
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
            'id_agremiacion'     => ['required', 'integer', 'exists:fich_agremiaciones,id'],
            'id_objeto_contrato' => ['required', 'integer', 'exists:fich_objetos_contrato,id'],
            'id_especialidad'    => ['required', 'integer', 'exists:fich_especialidades,id'],
            'vlr_contrato'       => ['required'],
            'fecha_ini'          => ['required', 'date'],
            'fecha_fin'          => ['required', 'date', 'after_or_equal:fecha_ini'],
            'profesionales'      => ['required', 'array', 'min:1'],
            'profesionales.*'    => ['integer', 'exists:fich_profesionales,id'],
            'id_empresa'         => ['nullable', 'integer', 'exists:ent_empresas,id'],
            'id_sucursal'        => ['nullable', 'integer', 'exists:config_ubi_sucursales,id'],
            'sucursal_legacy'    => ['nullable', 'string', 'max:100'],
            'id_padre'           => ['nullable', 'integer', 'exists:fich_fichas,id'],
            'obs_os'             => ['nullable', 'string', 'max:500', 'required_with:id_padre'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'profesionales.required' => 'Debe seleccionar al menos un profesional para la especialidad.',
            'fecha_fin.after_or_equal' => 'La fecha final no puede ser anterior a la fecha inicial.',
            'obs_os.required_with'   => 'Debe describir el motivo del cambio cuando la ficha es una actualización.',
        ];
    }
}
