<?php

declare(strict_types=1);

namespace App\Http\Requests\MesaServicio;

use App\Models\MesaServicio\GlpiParamPlantilla;
use App\Models\MesaServicio\GlpiParamPlantillaCategoria;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGlpiPlantillaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return self::baseRules();
    }

    public function messages(): array
    {
        return self::baseMessages();
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('codigo')) {
            $merge['codigo'] = strtoupper(trim((string) $this->input('codigo')));
        }
        if ($this->has('prefijo_regla')) {
            $merge['prefijo_regla'] = strtoupper(trim((string) $this->input('prefijo_regla'))) ?: 'TIC';
        }
        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    public static function baseRules(?int $ignoreId = null): array
    {
        $prioridades = GlpiParamPlantilla::PRIORIDADES;
        $unidades = GlpiParamPlantilla::UNIDADES;

        $codigoRule = Rule::unique('glpi_param_plantillas', 'codigo');
        if ($ignoreId !== null) {
            $codigoRule = $codigoRule->ignore($ignoreId);
        }

        return [
            'codigo' => ['required', 'string', 'max:40', $codigoRule],
            'nombre' => ['required', 'string', 'max:150'],
            'descripcion' => ['nullable', 'string'],
            'id_empresa' => ['nullable', 'integer', 'exists:ent_empresas,id'],
            'nombre_entidad' => ['nullable', 'string', 'max:150'],
            'grupo_tecnico' => ['nullable', 'string', 'max:150'],
            'sla_asignacion' => ['nullable', 'string', 'max:150'],
            'prefijo_regla' => ['nullable', 'string', 'max:40'],
            'estado' => ['nullable', 'boolean'],

            'ans' => ['required', 'array', 'min:1', 'max:20'],
            'ans.*.prioridad' => ['required', Rule::in($prioridades)],
            'ans.*.tiempo_asignacion' => ['nullable', 'integer', 'min:1'],
            'ans.*.unidad_asignacion' => ['nullable', Rule::in($unidades)],
            'ans.*.tiempo_solucion' => ['nullable', 'integer', 'min:1'],
            'ans.*.unidad_solucion' => ['nullable', Rule::in($unidades)],
            'ans.*.nombre_sla_solucion' => ['nullable', 'string', 'max:150'],
            'ans.*.nombre_regla' => ['nullable', 'string', 'max:150'],

            'categorias' => ['nullable', 'array'],
        ];
    }

    public static function baseMessages(): array
    {
        return [
            'codigo.required' => 'El código de la plantilla es obligatorio.',
            'codigo.unique' => 'Ya existe una plantilla con ese código.',
            'nombre.required' => 'El nombre de la plantilla es obligatorio.',
            'ans.required' => 'Debe enviar al menos un ANS.',
            'ans.min' => 'La plantilla debe incluir al menos un ANS.',
            'ans.max' => 'La plantilla no puede tener más de 20 ANS.',
            'ans.*.prioridad.in' => 'Prioridad ANS no válida.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $this->validarArbolCategorias($validator, $this->input('categorias', []), 1, 'categorias');
        });
    }

    private function validarArbolCategorias($validator, mixed $nodos, int $nivel, string $path): void
    {
        if ($nodos === null || $nodos === []) {
            return;
        }

        if (! is_array($nodos)) {
            $validator->errors()->add($path, 'Las categorías deben enviarse como un árbol.');
            return;
        }

        if ($nivel > GlpiParamPlantillaCategoria::NIVEL_MAXIMO) {
            $validator->errors()->add($path, 'El árbol de categorías no puede superar 4 niveles (3 padres y 1 hija).');
            return;
        }

        $prioridades = GlpiParamPlantilla::PRIORIDADES;

        foreach ($nodos as $index => $nodo) {
            $nodoPath = "{$path}.{$index}";
            if (! is_array($nodo)) {
                $validator->errors()->add($nodoPath, 'Nodo de categoría inválido.');
                continue;
            }

            $nombre = trim((string) ($nodo['nombre'] ?? $nodo['categoria'] ?? ''));
            if ($nombre === '') {
                $validator->errors()->add("{$nodoPath}.nombre", 'El nombre de la categoría es obligatorio.');
            }

            $prioridad = $nodo['prioridad'] ?? null;
            if ($prioridad !== null && $prioridad !== '' && ! in_array($prioridad, $prioridades, true)) {
                $validator->errors()->add("{$nodoPath}.prioridad", 'Prioridad no válida.');
            }

            $ansNombre = trim((string) ($nodo['ans_nombre'] ?? ''));
            if (strlen($ansNombre) > 150) {
                $validator->errors()->add("{$nodoPath}.ans_nombre", 'El ANS asociado no puede superar 150 caracteres.');
            }

            $hijas = $nodo['hijas'] ?? [];
            $esHoja = ! is_array($hijas) || $hijas === [];
            if ($esHoja && $ansNombre === '') {
                $validator->errors()->add("{$nodoPath}.ans_nombre", 'Asocia un ANS de la plantilla a esta categoría.');
            }
            if ($hijas !== [] && $nivel >= GlpiParamPlantillaCategoria::NIVEL_MAXIMO) {
                $validator->errors()->add("{$nodoPath}.hijas", 'El nivel 4 es la subcategoría hija y no puede tener más hijas.');
                continue;
            }

            if ($hijas !== []) {
                $this->validarArbolCategorias($validator, $hijas, $nivel + 1, "{$nodoPath}.hijas");
            }
        }
    }
}
