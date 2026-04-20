<?php

namespace App\Http\Requests;

use App\Models\ScheduledTask;
use Illuminate\Foundation\Http\FormRequest;

class StoreScheduledTaskRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Autorización manejada por middleware
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $availableTypes = array_keys(config('scheduled-tasks.types', []));

        return [
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:' . implode(',', $availableTypes),
            'description' => 'nullable|string|max:1000',
            'scheduled_at' => [
                'nullable',
                'date',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $scheduledTime = \Carbon\Carbon::parse($value);
                        $now = \Carbon\Carbon::now();
                        
                        // Permitir hasta 2 minutos en el pasado para compensar latencia
                        if ($scheduledTime->lt($now->subMinutes(2))) {
                            $fail('La fecha de programación debe ser igual o posterior a la fecha actual');
                        }
                    }
                },
            ],
            'parameters' => 'nullable|array',
            'max_attempts' => 'nullable|integer|min:1|max:10',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'El nombre de la tarea es requerido',
            'type.required' => 'El tipo de tarea es requerido',
            'type.in' => 'El tipo de tarea no es válido',
            'scheduled_at.after_or_equal' => 'La fecha de programación debe ser igual o posterior a la fecha actual',
            'parameters.array' => 'Los parámetros deben ser un objeto JSON',
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Si scheduled_at viene como string, asegurarse que sea válido
        if ($this->has('scheduled_at') && is_string($this->scheduled_at)) {
            $this->merge([
                'scheduled_at' => $this->scheduled_at ?: null,
            ]);
        }
    }
}
