<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\ModuloEmpresa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ModuloEmpresaController extends Controller
{
    /**
     * Obtener módulos de una empresa específica
     */
    public function getModulosByEmpresa($idEmpresa)
    {
        try {
            $empresa = Empresa::findOrFail($idEmpresa);

            // Módulos asignados directamente
            $modulosDirectos = $empresa->modulosActivos()->get();

            // Todos los módulos accesibles (incluyendo heredados)
            $modulosAccesibles = $empresa->obtenerModulosAccesibles(true);

            return response()->json([
                'success' => true,
                'data' => [
                    'empresa' => $empresa,
                    'modulos_directos' => $modulosDirectos,
                    'modulos_accesibles' => $modulosAccesibles
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener módulos de la empresa',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener empresas que tienen acceso a un módulo
     */
    public function getEmpresasByModulo($idModulo)
    {
        try {
            $modulo = Modulo::with(['empresas' => function ($query) {
                $query->wherePivot('activo', 1);
            }])->findOrFail($idModulo);

            return response()->json([
                'success' => true,
                'data' => [
                    'modulo' => $modulo,
                    'empresas' => $modulo->empresas
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener empresas del módulo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asignar módulo a empresa
     */
    public function asignarModulo(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id_modulo' => 'required|exists:seg_modulos,id',
                'id_empresa' => 'required|exists:ent_empresas,id',
                'hereda_hijos' => 'nullable|boolean',
                'activo' => 'nullable|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $data = $validator->validated();
            $heredaHijos = $data['hereda_hijos'] ?? true;
            $activo = $data['activo'] ?? true;

            $moduloEmpresa = ModuloEmpresa::updateOrCreate(
                [
                    'id_modulo' => $data['id_modulo'],
                    'id_empresa' => $data['id_empresa']
                ],
                [
                    'activo' => $activo,
                    'hereda_hijos' => $heredaHijos
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Módulo asignado exitosamente',
                'data' => $moduloEmpresa
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al asignar módulo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remover módulo de empresa
     */
    public function removerModulo(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id_modulo' => 'required|exists:seg_modulos,id',
                'id_empresa' => 'required|exists:ent_empresas,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            ModuloEmpresa::where('id_modulo', $request->id_modulo)
                ->where('id_empresa', $request->id_empresa)
                ->delete();

            return response()->json([
                'success' => true,
                'message' => 'Módulo removido exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al remover módulo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar configuración de módulo-empresa
     */
    public function actualizarConfiguracion(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'id_modulo' => 'required|exists:seg_modulos,id',
                'id_empresa' => 'required|exists:ent_empresas,id',
                'hereda_hijos' => 'nullable|boolean',
                'activo' => 'nullable|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Errores de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $moduloEmpresa = ModuloEmpresa::where('id_modulo', $request->id_modulo)
                ->where('id_empresa', $request->id_empresa)
                ->firstOrFail();

            $moduloEmpresa->update($request->only(['hereda_hijos', 'activo']));

            return response()->json([
                'success' => true,
                'message' => 'Configuración actualizada exitosamente',
                'data' => $moduloEmpresa
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar configuración',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener matriz de permisos (solo empresas con módulos asignados)
     */
    public function matrizPermisos()
    {
        try {
            // Obtener solo empresas que tienen módulos asignados
            $empresas = Empresa::activas()
                ->whereHas('modulos')
                ->get();

            $matriz = [];

            foreach ($empresas as $empresa) {
                // Obtener solo los módulos asignados a esta empresa desde seg_modulo_empresa
                $modulosAsignados = ModuloEmpresa::where('id_empresa', $empresa->id)
                    ->with('modulo.hijos')
                    ->get();

                if ($modulosAsignados->isEmpty()) {
                    continue; // Saltar empresas sin módulos
                }

                $empresaData = [
                    'id' => $empresa->id,
                    'nombre' => $empresa->nombre ?? $empresa->nombre_comercial ?? 'Sin nombre',
                    'modulos' => []
                ];

                foreach ($modulosAsignados as $asignacion) {
                    $modulo = $asignacion->modulo;
                    
                    if (!$modulo) {
                        continue; // Saltar si el módulo no existe
                    }

                    $empresaData['modulos'][] = [
                        'id_modulo' => $modulo->id,
                        'codigo' => $modulo->codigo,
                        'nombre' => $modulo->nombre,
                        'tiene_acceso' => true,
                        'activo' => $asignacion->activo,
                        'hereda_hijos' => $asignacion->hereda_hijos,
                        'hijos' => $asignacion->hereda_hijos 
                            ? $this->obtenerHijosRecursivos($modulo, $empresa) 
                            : []
                    ];
                }

                $matriz[] = $empresaData;
            }

            return response()->json([
                'success' => true,
                'data' => $matriz
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener matriz de permisos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener hijos recursivamente con su estado de acceso
     */
    private function obtenerHijosRecursivos($modulo, $empresa)
    {
        if (!$modulo->hijos || $modulo->hijos->count() === 0) {
            return [];
        }

        return $modulo->hijos->map(function ($hijo) use ($empresa) {
            $tieneAcceso = $hijo->empresaTieneAcceso($empresa->id);
            
            return [
                'id' => $hijo->id,
                'codigo' => $hijo->codigo,
                'nombre' => $hijo->nombre,
                'tiene_acceso' => $tieneAcceso,
                'hijos' => $this->obtenerHijosRecursivos($hijo, $empresa)
            ];
        })->toArray();
    }
}
