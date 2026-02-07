<?php

namespace App\Http\Controllers\GLPI;

use App\Http\Controllers\Controller;
use App\Services\GLPI\GLPIComputerService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Exception;

class ComputerController extends Controller
{
    protected $computerService;

    public function __construct(GLPIComputerService $computerService)
    {
        $this->computerService = $computerService;
    }

    /**
     * Obtener todas las computadoras
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $params = [
                'range' => $request->get('range', '0-50'),
                'sort' => $request->get('sort', 'id'),
                'order' => $request->get('order', 'ASC'),
                'searchText' => $request->get('searchText'),
                'is_deleted' => $request->get('is_deleted', 0),
                'expand_dropdowns' => $request->get('expand_dropdowns', true),
                'get_hateoas' => $request->get('get_hateoas', true)
            ];

            $computers = $this->computerService->getAllComputers($params);
            
            return response()->json([
                'success' => true,
                'data' => $computers,
                'total' => count($computers)
            ]);
        } catch (Exception $e) {
            Log::error('Error al obtener computadoras GLPI: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener computadoras',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener una computadora específica
     */
    public function show(Request $request, $id): JsonResponse
    {
        try {
            $params = [
                'expand_dropdowns' => $request->get('expand_dropdowns', true),
                'get_hateoas' => $request->get('get_hateoas', true),
                'get_sha1' => $request->get('get_sha1', false),
                'with_devices' => $request->get('with_devices', true),
                'with_disks' => $request->get('with_disks', true),
                'with_softwares' => $request->get('with_softwares', true),
                'with_connections' => $request->get('with_connections', true),
                'with_networkports' => $request->get('with_networkports', true),
                'with_infocoms' => $request->get('with_infocoms', true),
                'with_contracts' => $request->get('with_contracts', true),
                'with_documents' => $request->get('with_documents', true),
                'with_tickets' => $request->get('with_tickets', true),
                'with_problems' => $request->get('with_problems', true),
                'with_changes' => $request->get('with_changes', true),
                'with_notes' => $request->get('with_notes', true),
                'with_logs' => $request->get('with_logs', false)
            ];

            $computer = $this->computerService->getComputer($id, $params);
            
            return response()->json([
                'success' => true,
                'data' => $computer
            ]);
        } catch (Exception $e) {
            Log::error('Error al obtener computadora GLPI: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener computadora',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar una computadora
     */
    public function update(Request $request, $id): JsonResponse
    {
        $request->validate([
            'name' => 'string|max:255',
            'serial' => 'string|max:255',
            'otherserial' => 'string|max:255',
            'locations_id' => 'integer',
            'users_id' => 'integer',
            'groups_id' => 'integer',
            'states_id' => 'integer',
            'manufacturers_id' => 'integer',
            'computertypes_id' => 'integer',
            'computermodels_id' => 'integer',
            'operatingsystems_id' => 'integer',
            'operatingsystemversions_id' => 'integer',
            'operatingsystemservicepacks_id' => 'integer',
            'comment' => 'string'
        ]);

        try {
            $computerData = $request->only([
                'name', 'serial', 'otherserial', 'locations_id', 'users_id',
                'groups_id', 'states_id', 'manufacturers_id', 'computertypes_id',
                'computermodels_id', 'operatingsystems_id', 'operatingsystemversions_id',
                'operatingsystemservicepacks_id', 'comment'
            ]);

            $computer = $this->computerService->updateComputer($id, $computerData);
            
            return response()->json([
                'success' => true,
                'message' => 'Computadora actualizada correctamente',
                'data' => $computer
            ]);
        } catch (Exception $e) {
            Log::error('Error al actualizar computadora GLPI: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar computadora',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar una computadora
     */
    public function destroy($id): JsonResponse
    {
        try {
            $result = $this->computerService->deleteComputer($id);
            
            return response()->json([
                'success' => true,
                'message' => 'Computadora eliminada correctamente',
                'data' => $result
            ]);
        } catch (Exception $e) {
            Log::error('Error al eliminar computadora GLPI: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar computadora',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Buscar computadoras por criterios específicos
     */
    public function search(Request $request): JsonResponse
    {
        try {
            $criteria = $request->get('criteria', []);
            $metacriteria = $request->get('metacriteria', []);
            $sort = $request->get('sort', 1);
            $order = $request->get('order', 'ASC');
            $range = $request->get('range', '0-50');

            $results = $this->computerService->searchComputers([
                'criteria' => $criteria,
                'metacriteria' => $metacriteria,
                'sort' => $sort,
                'order' => $order,
                'range' => $range
            ]);
            
            return response()->json([
                'success' => true,
                'data' => $results
            ]);
        } catch (Exception $e) {
            Log::error('Error al buscar computadoras GLPI: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al buscar computadoras',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener dispositivos de una computadora
     */
    public function getDevices($id): JsonResponse
    {
        try {
            $devices = $this->computerService->getComputerDevices($id);
            
            return response()->json([
                'success' => true,
                'data' => $devices
            ]);
        } catch (Exception $e) {
            Log::error('Error al obtener dispositivos de computadora GLPI: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener dispositivos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}