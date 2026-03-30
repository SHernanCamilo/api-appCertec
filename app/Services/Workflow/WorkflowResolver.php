<?php

namespace App\Services\Workflow;

use App\Models\Workflow\WfModulo;
use App\Models\Workflow\WfDefinicion;
use Illuminate\Support\Facades\Log;

/**
 * Resuelve qué flujo aplicar según el contexto.
 *
 * Estrategia:
 *   1. Buscar flujos específicos de la empresa
 *   2. Si no hay, buscar flujos genéricos (id_empresa = null)
 *   3. Evaluar reglas por prioridad (menor número = mayor prioridad)
 *   4. Retornar el primer flujo cuyas reglas coincidan
 */
class WorkflowResolver
{
    /**
     * Resuelve el flujo aplicable para un módulo y contexto dado.
     *
     * @param string $codigoModulo Código del módulo (anticipos, horas_extras, etc.)
     * @param array $contexto Datos para evaluar reglas:
     *   - nivel_jerarquico: int (1, 2, 3)
     *   - prefijo_sucursal: string (MA, NVA, EAL, etc.)
     *   - monto: decimal
     *   - cobertura: string (nacional, internacional)
     *   - id_empresa: int
     *   - id_sede: int (opcional)
     *
     * @return WfDefinicion
     * @throws \Exception Si no se encuentra flujo aplicable
     */
    public function resolverFlujo(string $codigoModulo, array $contexto): WfDefinicion
    {
        // 1. Obtener el módulo
        $modulo = WfModulo::porCodigo($codigoModulo)->activos()->first();
        
        if (!$modulo) {
            throw new \Exception("Módulo '{$codigoModulo}' no encontrado o inactivo");
        }

        // 2. Buscar flujos de la empresa específica
        $flujos = WfDefinicion::porModulo($modulo->id)
            ->where('id_empresa', $contexto['id_empresa'] ?? null)
            ->activos()
            ->with(['reglas' => fn($q) => $q->activos()->ordenadas()])
            ->get();

        // 3. Si no hay flujos específicos, buscar genéricos
        if ($flujos->isEmpty()) {
            $flujos = WfDefinicion::porModulo($modulo->id)
                ->whereNull('id_empresa')
                ->activos()
                ->with(['reglas' => fn($q) => $q->activos()->ordenadas()])
                ->get();
        }

        if ($flujos->isEmpty()) {
            throw new \Exception("No hay flujos configurados para el módulo '{$codigoModulo}'");
        }

        // 4. Evaluar reglas por prioridad
        foreach ($flujos as $flujo) {
            // Si el flujo no tiene reglas, aplica por defecto
            if ($flujo->reglas->isEmpty()) {
                Log::info("Flujo sin reglas aplicado por defecto", [
                    'flujo' => $flujo->codigo,
                    'contexto' => $contexto,
                ]);
                return $flujo;
            }

            // Evaluar cada regla
            foreach ($flujo->reglas as $regla) {
                if ($regla->evaluar($contexto)) {
                    Log::info("Flujo resuelto", [
                        'flujo' => $flujo->codigo,
                        'regla' => $regla->id,
                        'contexto' => $contexto,
                    ]);
                    return $flujo;
                }
            }
        }

        // 5. Si ninguna regla coincide, lanzar excepción
        throw new \Exception(
            "No se encontró flujo aplicable para el módulo '{$codigoModulo}' con el contexto dado. " .
            "Contexto: " . json_encode($contexto)
        );
    }

    /**
     * Obtiene todos los flujos disponibles para un módulo y empresa.
     */
    public function obtenerFlujosDisponibles(string $codigoModulo, ?int $idEmpresa = null): array
    {
        $modulo = WfModulo::porCodigo($codigoModulo)->activos()->first();
        
        if (!$modulo) {
            return [];
        }

        return WfDefinicion::porModulo($modulo->id)
            ->porEmpresa($idEmpresa)
            ->activos()
            ->with(['reglas', 'pasos'])
            ->get()
            ->toArray();
    }
}
