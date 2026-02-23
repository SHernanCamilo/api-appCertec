<?php

namespace App\Services;

class TemplateValidator
{
    /**
     * Variables permitidas en el catálogo del sistema
     *
     * @var array
     */
    protected array $allowedVariables = [
        'nombre_usuario',
        'numero_ticket',
        'departamento',
        'fecha',
        'descripcion',
        'nombre_empresa',
        'email_usuario',
        'telefono_usuario',
        // Nuevas variables agregadas
        'cargo_usuario',
        'direccion_empresa',
        'ciudad',
        'nit_empresa',
        'responsable',
    ];

    /**
     * Validar el contenido HTML de una plantilla
     *
     * @param string $content
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateContent(string $content): array
    {
        $errors = [];

        // Validar que el contenido no esté vacío
        if (empty(trim($content))) {
            $errors[] = [
                'field' => 'content',
                'message' => 'El contenido de la plantilla no puede estar vacío',
                'code' => 'CONTENT_EMPTY'
            ];
        }

        // Validar sintaxis de variables
        $variableSyntaxValidation = $this->validateVariableSyntax($content);
        if (!$variableSyntaxValidation['valid']) {
            $errors = array_merge($errors, $variableSyntaxValidation['errors']);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Validar la sintaxis de las variables en el contenido
     * Verifica que sigan el patrón {{nombre_variable}}
     *
     * @param string $content
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validateVariableSyntax(string $content): array
    {
        $errors = [];

        // Buscar patrones que parezcan variables pero con sintaxis incorrecta
        // Ejemplo: {variable}, {{variable, variable}}, etc.
        
        // Buscar llaves simples que podrían ser variables mal formadas
        if (preg_match('/\{(?!\{)[^}]*\}(?!\})/', $content)) {
            $errors[] = [
                'field' => 'content',
                'message' => 'Se encontraron variables con sintaxis incorrecta. Use el formato {{nombre_variable}}',
                'code' => 'INVALID_VARIABLE_SYNTAX'
            ];
        }

        // Buscar variables con doble llave de apertura pero sin cierre correcto
        if (preg_match('/\{\{[^}]*\}(?!\})/', $content)) {
            $errors[] = [
                'field' => 'content',
                'message' => 'Se encontraron variables incompletas. Asegúrese de cerrar con }}',
                'code' => 'INCOMPLETE_VARIABLE'
            ];
        }

        // Extraer variables válidas y verificar contra el catálogo
        $variables = $this->extractVariables($content);
        foreach ($variables as $variable) {
            if (!in_array($variable, $this->allowedVariables)) {
                $errors[] = [
                    'field' => 'content',
                    'message' => "La variable '{$variable}' no existe en el catálogo de variables disponibles",
                    'code' => 'VARIABLE_NOT_IN_CATALOG',
                    'variable' => $variable
                ];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Extraer todas las variables del contenido
     * Busca patrones {{nombre_variable}}
     *
     * @param string $content
     * @return array
     */
    public function extractVariables(string $content): array
    {
        $pattern = '/\{\{([a-zA-Z0-9_]+)\}\}/';
        preg_match_all($pattern, $content, $matches);
        
        // Retornar variables únicas
        return array_unique($matches[1]);
    }

    /**
     * Obtener el catálogo de variables permitidas
     *
     * @return array
     */
    public function getAllowedVariables(): array
    {
        return $this->allowedVariables;
    }

    /**
     * Agregar una nueva variable al catálogo
     *
     * @param string $variable
     * @return void
     */
    public function addAllowedVariable(string $variable): void
    {
        if (!in_array($variable, $this->allowedVariables)) {
            $this->allowedVariables[] = $variable;
        }
    }

    /**
     * Validar que una variable específica existe en el catálogo
     *
     * @param string $variable
     * @return bool
     */
    public function isVariableAllowed(string $variable): bool
    {
        return in_array($variable, $this->allowedVariables);
    }
}
