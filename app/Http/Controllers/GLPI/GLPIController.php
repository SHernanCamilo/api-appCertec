<?php

namespace App\Http\Controllers\GLPI;

use App\Http\Controllers\Controller;
use App\Services\GLPI\GLPIService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class GLPIController extends Controller
{
    protected $glpiService;

    public function __construct(GLPIService $glpiService)
    {
        $this->glpiService = $glpiService;
    }

    /**
     * Inicializar sesión con GLPI
     */
    public function initSession(): JsonResponse
    {
        try {
            $session = $this->glpiService->initSession();
            
            return response()->json([
                'success' => true,
                'message' => 'Sesión GLPI iniciada correctamente',
                'data' => $session
            ]);
        } catch (Exception $e) {
            Log::error('Error al inicializar sesión GLPI: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al conectar con GLPI',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cerrar sesión con GLPI
     */
    public function killSession(): JsonResponse
    {
        try {
            $this->glpiService->killSession();
            
            return response()->json([
                'success' => true,
                'message' => 'Sesión GLPI cerrada correctamente'
            ]);
        } catch (Exception $e) {
            Log::error('Error al cerrar sesión GLPI: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al cerrar sesión GLPI',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener información del perfil actual
     */
    // public function getMyProfiles(): JsonResponse
    // {
    //     try {
    //         $profiles = $this->glpiService->getMyProfiles();
            
    //         return response()->json([
    //             'success' => true,
    //             'data' => $profiles
    //         ]);
    //     } catch (Exception $e) {
    //         Log::error('Error al obtener perfiles GLPI: ' . $e->getMessage());
            
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Error al obtener perfiles',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    // /**
    //  * Obtener configuración activa de GLPI
    //  */
    // public function getActiveProfile(): JsonResponse
    // {
    //     try {
    //         $profile = $this->glpiService->getActiveProfile();
            
    //         return response()->json([
    //             'success' => true,
    //             'data' => $profile
    //         ]);
    //     } catch (Exception $e) {
    //         Log::error('Error al obtener perfil activo GLPI: ' . $e->getMessage());
            
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Error al obtener perfil activo',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    // /**
    //  * Cambiar perfil activo
    //  */
    // public function changeActiveProfile(Request $request): JsonResponse
    // {
    //     $request->validate([
    //         'profiles_id' => 'required|integer'
    //     ]);

    //     try {
    //         $result = $this->glpiService->changeActiveProfile($request->profiles_id);
            
    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Perfil cambiado correctamente',
    //             'data' => $result
    //         ]);
    //     } catch (Exception $e) {
    //         Log::error('Error al cambiar perfil GLPI: ' . $e->getMessage());
            
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Error al cambiar perfil',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    // /**
    //  * Obtener entidades disponibles
    //  */
    // public function getMyEntities(): JsonResponse
    // {
    //     try {
    //         $entities = $this->glpiService->getMyEntities();
            
    //         return response()->json([
    //             'success' => true,
    //             'data' => $entities
    //         ]);
    //     } catch (Exception $e) {
    //         Log::error('Error al obtener entidades GLPI: ' . $e->getMessage());
            
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Error al obtener entidades',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    // /**
    //  * Cambiar entidad activa
    //  */
    // public function changeActiveEntities(Request $request): JsonResponse
    // {
    //     $request->validate([
    //         'entities_id' => 'required|integer',
    //         'is_recursive' => 'boolean'
    //     ]);

    //     try {
    //         $result = $this->glpiService->changeActiveEntities(
    //             $request->entities_id,
    //             $request->get('is_recursive', false)
    //         );
            
    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Entidad cambiada correctamente',
    //             'data' => $result
    //         ]);
    //     } catch (Exception $e) {
    //         Log::error('Error al cambiar entidad GLPI: ' . $e->getMessage());
            
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Error al cambiar entidad',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    // /**
    //  * Obtener información completa de la sesión
    //  */
    // public function getFullSession(): JsonResponse
    // {
    //     try {
    //         $session = $this->glpiService->getFullSession();
            
    //         return response()->json([
    //             'success' => true,
    //             'data' => $session
    //         ]);
    //     } catch (Exception $e) {
    //         Log::error('Error al obtener sesión completa GLPI: ' . $e->getMessage());
            
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Error al obtener información de sesión',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }
}