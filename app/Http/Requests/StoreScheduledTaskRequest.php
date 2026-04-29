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

        $rules = [
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:' . implode(',', $availableTypes),
            'description' => 'nullable|string|max:1000',
            'parameters' => 'nullable|array',
            'max_attempts' => 'nullable|integer|min:1|max:10',
            'is_recurring' => 'nullable|boolean',
        ];

        // Si es tarea recurrente
        if ($this->is_recurring) {
            $rules['recurrence_type'] = 'required|string|in:every_minute,every_5_minutes,every_15_minutes,every_30_minutes,hourly,daily,weekly,monthly,custom_days,cron';
            $rules['recurrence_value'] = 'nullable|array';
            
            // Validaciones específicas según tipo de recurrencia
            if ($this->recurrence_type === 'daily') {
                $rules['recurrence_value.time'] = 'required|date_format:H:i';
            }
            
            if ($this->recurrence_type === 'weekly') {
                $rules['recurrence_value.day_of_week'] = 'required|integer|min:0|max:6';
                $rules['recurrence_value.time'] = 'required|date_format:H:i';
            }
            
            if ($this->recurrence_type === 'monthly') {
                $rules['recurrence_value.day'] = 'required'; // Puede ser número o 'last'
                $rules['recurrence_value.time'] = 'required|date_format:H:i';
            }
            
            if ($this->recurrence_type === 'custom_days') {
                $rules['recurrence_value.days'] = 'required|array';
                $rules['recurrence_value.days.*'] = 'integer|min:0|max:6';
                $rules['recurrence_value.time'] = 'required|date_format:H:i';
            }
        } else {
            // Si no es recurrente, validar scheduled_at
            $rules['scheduled_at'] = [
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
            ];
        }

        return $rules;
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
            'recurrence_type.required' => 'El tipo de recurrencia es requerido para tareas recurrentes',
            'recurrence_type.in' => 'El tipo de recurrencia no es válido',
            'recurrence_value.time.required' => 'La hora es requerida',
            'recurrence_value.time.date_format' => 'La hora debe tener el formato HH:MM',
            'recurrence_value.day_of_week.required' => 'El día de la semana es requerido',
            'recurrence_value.day.required' => 'El día del mes es requerido',
            'recurrence_value.days.required' => 'Los días son requeridos',
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
