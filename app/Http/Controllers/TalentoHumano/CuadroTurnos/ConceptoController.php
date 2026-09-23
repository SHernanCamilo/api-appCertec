<?php

namespace App\Http\Controllers\TalentoHumano\CuadroTurnos;

use App\Http\Controllers\Controller;
use App\Models\TalentoHumano\CuadroTurnos\CtConcepto;
use App\Services\TalentoHumano\CuadroTurnos\FormulaEvaluator;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ConceptoController extends Controller
{
    private FormulaEvaluator $evaluator;

    public function __construct(FormulaEvaluator $evaluator)
    {
        $this->evaluator = $evaluator;
    }

    /**
     * GET /api/turnos/conceptos
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = CtConcepto::query();

            // Conceptos POR EMPRESA
            if ($request->filled('id_empresa')) {
                $query->where('id_empresa', (int) $request->id_empresa);
            }

            if ($request->filled('activo')) {
                $query->where('activo', filter_var($request->activo, FILTER_VALIDATE_BOOLEAN));
            }

            if ($request->filled('tipo_concepto')) {
                $query->where('tipo_concepto', $request->tipo_concepto);
            }

            $conceptos = $query->orderBy('codigo')->get();

            return response()->json([
                'success' => true,
                'data' => $conceptos,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener conceptos: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/turnos/conceptos/{id}
     */
    public function show(int $id): JsonResponse
    {
        try {
            $concepto = CtConcepto::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $concepto,
                'variables_usadas' => $concepto->getVariables(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Concepto no encontrado.',
            ], 404);
        }
    }

    /**
     * POST /api/turnos/conceptos
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'id_empresa' => 'required|integer|exists:ent_empresas,id',
            // El codigo es unico POR EMPRESA (misma empresa no repite codigo)
            'codigo' => [
                'required', 'string', 'max:10',
                \Illuminate\Validation\Rule::unique('humtal_ct_conceptos', 'codigo')
                    ->where(fn($q) => $q->where('id_empresa', $request->id_empresa)),
            ],
            'nombre' => 'required|string|max:100',
            'tipo_concepto' => 'required|in:devengado,deducido',
            'formula' => 'required|string',
            'activo' => 'required|boolean',
        ]);

        try {
            // Validar que la fórmula sea parseable
            $validacion = $this->validarFormula($request->formula);
            if (!$validacion['valida']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fórmula inválida: ' . $validacion['error'],
                ], 422);
            }

            $concepto = CtConcepto::create($request->only([
                'id_empresa', 'codigo', 'nombre', 'tipo_concepto', 'formula', 'activo',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Concepto creado exitosamente.',
                'data' => $concepto,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear concepto: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/turnos/conceptos/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $concepto = CtConcepto::findOrFail($id);
        $idEmpresa = $request->input('id_empresa', $concepto->id_empresa);

        $request->validate([
            'id_empresa' => 'sometimes|integer|exists:ent_empresas,id',
            // Codigo unico por empresa, ignorando el propio registro
            'codigo' => [
                'required', 'string', 'max:10',
                \Illuminate\Validation\Rule::unique('humtal_ct_conceptos', 'codigo')
                    ->where(fn($q) => $q->where('id_empresa', $idEmpresa))
                    ->ignore($id),
            ],
            'nombre' => 'required|string|max:100',
            'tipo_concepto' => 'required|in:devengado,deducido',
            'formula' => 'required|string',
            'activo' => 'required|boolean',
        ]);

        try {
            // Validar que la fórmula sea parseable
            $validacion = $this->validarFormula($request->formula);
            if (!$validacion['valida']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Fórmula inválida: ' . $validacion['error'],
                ], 422);
            }

            $concepto->update($request->only([
                'codigo', 'nombre', 'tipo_concepto', 'formula', 'activo',
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Concepto actualizado exitosamente.',
                'data' => $concepto->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar concepto: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * DELETE /api/turnos/conceptos/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $concepto = CtConcepto::findOrFail($id);
            $concepto->delete();

            return response()->json([
                'success' => true,
                'message' => 'Concepto eliminado exitosamente.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar concepto: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/turnos/conceptos/probar-formula
     *
     * Recibe una fórmula y valores de prueba para las variables,
     * retorna el resultado de la evaluación.
     */
    public function probarFormula(Request $request): JsonResponse
    {
        $request->validate([
            'formula' => 'required|string',
            'variables' => 'required|array',
            'variables.*' => 'numeric',
        ]);

        $resultado = $this->evaluator->evaluar($request->formula, $request->variables);

        return response()->json($resultado);
    }

    /**
     * GET /api/turnos/conceptos/variables
     *
     * Retorna la lista de variables disponibles para usar en fórmulas.
     */
    public function variables(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => CtConcepto::variablesDisponibles(),
        ]);
    }

    /**
     * Valida que una fórmula sea sintácticamente correcta.
     */
    private function validarFormula(string $formula): array
    {
        // Generar valores dummy para todas las variables encontradas
        $variables = $this->evaluator->extraerVariables($formula);
        $valoresDummy = [];
        foreach ($variables as $variable) {
            $valoresDummy[$variable] = 1; // valor de prueba
        }

        $resultado = $this->evaluator->evaluar($formula, $valoresDummy);

        return [
            'valida' => $resultado['success'],
            'error' => $resultado['error'],
        ];
    }
}
