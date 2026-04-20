<?php

namespace App\Http\Requests;

use App\Models\ScheduledTask;
use Illuminate\Foundation\Http\FormRequest;

class UpdateScheduledTaskRequest extends FormRequest
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
        return [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:1000',
            'scheduled_at' => 'nullable|date',
            'parameters' => 'nullable|array',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'name.string' => 'El nombre debe ser una cadena de texto',
            'scheduled_at.date' => 'La fecha de programación no es válida',
            'parameters.array' => 'Los parámetros deben ser un objeto JSON',
        ];
    }
}
