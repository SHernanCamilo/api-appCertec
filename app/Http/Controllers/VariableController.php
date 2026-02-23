<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VariableController extends Controller
{
    /**
     * Obtener todas las variables disponibles en el catálogo
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $variables = [
                [
                    'name' => 'nombre_usuario',
                    'displayName' => 'Nombre del Usuario',
                    'description' => 'Nombre completo del usuario',
                    'type' => 'string',
                    'required' => true
                ],
                [
                    'name' => 'numero_ticket',
                    'displayName' => 'Número de Ticket',
                    'description' => 'Número identificador del ticket',
                    'type' => 'string',
                    'required' => false
                ],
                [
                    'name' => 'departamento',
                    'displayName' => 'Departamento',
                    'description' => 'Departamento del usuario o ticket',
                    'type' => 'string',
                    'required' => false
                ],
                [
                    'name' => 'fecha',
                    'displayName' => 'Fecha',
                    'description' => 'Fecha del documento',
                    'type' => 'date',
                    'required' => true
                ],
                [
                    'name' => 'descripcion',
                    'displayName' => 'Descripción',
                    'description' => 'Descripción detallada',
                    'type' => 'string',
                    'required' => false
                ],
                [
                    'name' => 'nombre_empresa',
                    'displayName' => 'Nombre de la Empresa',
                    'description' => 'Nombre de la empresa',
                    'type' => 'string',
                    'required' => false
                ],
                [
                    'name' => 'email_usuario',
                    'displayName' => 'Email del Usuario',
                    'description' => 'Correo electrónico del usuario',
                    'type' => 'email',
                    'required' => false
                ],
                [
                    'name' => 'telefono_usuario',
                    'displayName' => 'Teléfono del Usuario',
                    'description' => 'Número de teléfono del usuario',
                    'type' => 'phone',
                    'required' => false
                ],
                // NUEVAS VARIABLES - Puedes agregar más aquí
                [
                    'name' => 'cargo_usuario',
                    'displayName' => 'Cargo del Usuario',
                    'description' => 'Cargo o posición del usuario en la empresa',
                    'type' => 'string',
                    'required' => false
                ],
                [
                    'name' => 'direccion_empresa',
                    'displayName' => 'Dirección de la Empresa',
                    'description' => 'Dirección física de la empresa',
                    'type' => 'string',
                    'required' => false
                ],
                [
                    'name' => 'ciudad',
                    'displayName' => 'Ciudad',
                    'description' => 'Ciudad donde se genera el documento',
                    'type' => 'string',
                    'required' => false
                ],
                [
                    'name' => 'nit_empresa',
                    'displayName' => 'NIT de la Empresa',
                    'description' => 'Número de identificación tributaria',
                    'type' => 'string',
                    'required' => false
                ],
                [
                    'name' => 'responsable',
                    'displayName' => 'Responsable',
                    'description' => 'Nombre del responsable del documento',
                    'type' => 'string',
                    'required' => false
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => $variables
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'code' => 'VARIABLES_FETCH_ERROR',
                    'message' => 'Error al obtener el catálogo de variables',
                    'details' => $e->getMessage(),
                    'timestamp' => now()->toIso8601String(),
                    'path' => $request->path()
                ]
            ], 500);
        }
    }
}
