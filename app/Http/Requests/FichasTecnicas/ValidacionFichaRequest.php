<?php

declare(strict_types=1);

namespace App\Http\Requests\FichasTecnicas;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de las acciones del flujo: autorizar, aprobar y rechazar.
 *
 * Diferencia clave frente al legacy: el `id_estado` destino NO se acepta desde
 * el cliente. Lo determina el servidor a partir del estado actual de la ficha,
 * cerrando el hueco por el que `insert_aprob.php` permitía enviar cualquier
 * estado por POST.
 */
class ValidacionFichaRequest extends FormRequest
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
        $accion = (string) $this->route('accion', '');

        return [
            // Obligatoria al autorizar y al rechazar; opcional al resto.
            'observacion' => [
                in_array($accion, ['autorizar', 'rechazar'], true) ? 'required' : 'nullable',
                'string',
                'max:2000',
            ],
            // Solo se tiene en cuenta al aprobar. Si viene vacío se calcula.
            'consecutivo' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $accion = (string) $this->route('accion', '');

        return [
            'observacion.required' => $accion === 'rechazar'
                ? 'El motivo de la devolución es obligatorio: el generador lo necesita para corregir.'
                : 'El comentario de autorización es obligatorio.',
        ];
    }
}
