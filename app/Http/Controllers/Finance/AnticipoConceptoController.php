<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\Anticipos\AnticipoTipoService;
use App\Services\Finance\Anticipos\AnticipoConceptoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controlador de Conceptos de Anticipo.
 *
 * Gestiona catálogos (Tipos, Clases, Modalidades) y CRUD de Conceptos con Reglas.
 */
class AnticipoConceptoController extends Controller
{
    public function __construct(
        private readonly AnticipoTipoService $tipoService,
        private readonly AnticipoConceptoService $conceptoService,
    ) {}

    // -------------------------------------------------------------------------
    // Catálogos: Tipos, Clases, Modalidades
    // -------------------------------------------------------------------------

    public function getTipos(): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->tipoService->getTipos(),
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al obtener los tipos de anticipos', $e);
        }
    }

    public function getClasesPorTipo(int $tipoId): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->tipoService->getClasesPorTipo($tipoId),
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al obtener las clases', $e);
        }
    }

    public function getModalidadesPorClase(int $claseId): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->tipoService->getModalidadesPorClase($claseId),
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al obtener las modalidades', $e);
        }
    }

    // -------------------------------------------------------------------------
    // Conceptos
    // -------------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        try {
            $paginado = $this->conceptoService->listar($request->all());

            return response()->json([
                'success'      => true,
                'data'         => $paginado->items(),
                'total'        => $paginado->total(),
                'current_page' => $paginado->currentPage(),
                'per_page'     => $paginado->perPage(),
                'last_page'    => $paginado->lastPage(),
            ]);
        } catch (\Exception $e) {
            return $this->error('Error al listar los conceptos', $e);
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            return response()->json([
                'success' => true,
                'data'    => $this->conceptoService->obtener($id),
            ]);
        } catch (\Exception $e) {
            return $this->error('Concepto no encontrado', $e, 404);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'id_tipo'                  => 'required|exists:anti_tipos,id',
            'id_clase'                 => 'required|exists:anti_clases,id',
            'id_modalidad'             => 'required|exists:anti_modalidades,id',
            'estado'                   => 'boolean',
            'reglas'                   => 'required|array|min:1',
            'reglas.*.descripcion'     => 'required|string|max:255',
            'reglas.*.valor_tope'      => 'required|numeric|min:0',
        ]);

        try {
            $result = $this->conceptoService->crear($request->all());

            Log::info('Anticipo concepto store', ['id' => $result['concepto']->id, 'creado' => $result['creado']]);

            return response()->json([
                'success' => true,
                'message' => $result['creado'] ? 'Concepto creado correctamente' : 'Reglas agregadas al concepto existente',
                'data'    => $result['concepto'],
            ], $result['creado'] ? 201 : 200);
        } catch (\Exception $e) {
            return $this->error('Error al crear el concepto', $e);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'id_tipo'              => 'required|exists:anti_tipos,id',
            'id_clase'             => 'required|exists:anti_clases,id',
            'id_modalidad'         => 'required|exists:anti_modalidades,id',
            'estado'               => 'boolean',
            'reglas'               => 'required|array|min:1',
            'reglas.*.descripcion' => 'required|string|max:255',
            'reglas.*.valor_tope'  => 'required|numeric|min:0',
        ]);

        try {
            $concepto = $this->conceptoService->actualizar($id, $request->all());

            Log::info('Anticipo concepto actualizado', ['id' => $id]);

            return response()->json(['success' => true, 'message' => 'Concepto actualizado correctamente', 'data' => $concepto]);
        } catch (\DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return $this->error('Error al actualizar el concepto', $e);
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            $this->conceptoService->eliminar($id);

            Log::info('Anticipo concepto eliminado', ['id' => $id]);

            return response()->json(['success' => true, 'message' => 'Concepto eliminado correctamente']);
        } catch (\Exception $e) {
            return $this->error('Error al eliminar el concepto', $e);
        }
    }

    public function toggleEstado(int $id): JsonResponse
    {
        try {
            $concepto = $this->conceptoService->toggleEstado($id);

            Log::info('Anticipo concepto estado cambiado', ['id' => $id, 'estado' => $concepto->estado]);

            return response()->json(['success' => true, 'message' => 'Estado actualizado correctamente', 'data' => $concepto]);
        } catch (\Exception $e) {
            return $this->error('Error al cambiar el estado', $e);
        }
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function error(string $message, \Exception $e, int $status = 500): JsonResponse
    {
        Log::error($message . ': ' . $e->getMessage());
        return response()->json(['success' => false, 'message' => $message, 'error' => $e->getMessage()], $status);
    }
}
