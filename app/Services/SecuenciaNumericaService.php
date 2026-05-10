<?php

namespace App\Services;

use App\Models\Config\SecSecuencia;
use App\Models\Config\SecDetalle;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SecuenciaNumericaService
{
    /**
     * Genera el siguiente consecutivo para un módulo/proceso y unidad operativa.
     *
     * @param  int       $empresaId
     * @param  int       $moduloId
     * @param  int|null  $procesoId
     * @param  int|null  $unidadId   ID de sucursal o sede según el ámbito
     * @return string    Consecutivo generado. Ej: "BTA0010"
     *
     * @throws \RuntimeException  Si no existe configuración o detalle para la unidad
     */
    public function generar(int $empresaId, int $moduloId, ?int $procesoId = null, ?int $unidadId = null): string
    {
        return DB::transaction(function () use ($empresaId, $moduloId, $procesoId, $unidadId) {

            // 1. Obtener cabecera de secuencia
            $secuencia = SecSecuencia::where('empresa_id', $empresaId)
                ->where('modulo_id', $moduloId)
                ->where('proceso_id', $procesoId)
                ->where('estado', true)
                ->first();

            if (!$secuencia) {
                throw new \RuntimeException(
                    "No existe configuración de secuencia para el módulo/proceso indicado."
                );
            }

            // 2. Si es manual, no se genera automáticamente
            if ($secuencia->es_manual) {
                throw new \RuntimeException(
                    "La secuencia está configurada como manual. El número debe ser ingresado por el usuario."
                );
            }

            // 3. Obtener detalle con bloqueo de fila (evita duplicados en concurrencia)
            $detalle = $this->obtenerDetalle($secuencia, $unidadId);

            // 4. Generar el consecutivo aplicando el patrón
            $consecutivo = $this->aplicarPatron(
                $detalle->patron->patron,
                $detalle->siguiente_numero,
                $secuencia->rango
            );

            // 5. Incrementar el contador
            $detalle->increment('siguiente_numero');

            return $consecutivo;
        });
    }

    /**
     * Obtiene el detalle correspondiente según el ámbito de la secuencia.
     * Usa lockForUpdate() para evitar condiciones de carrera.
     */
    private function obtenerDetalle(SecSecuencia $secuencia, ?int $unidadId): SecDetalle
    {
        $query = SecDetalle::with('patron')
            ->where('secuencia_id', $secuencia->id)
            ->where('estado', true)
            ->lockForUpdate();

        switch ($secuencia->ambito) {
            case SecSecuencia::AMBITO_SUCURSAL:
                $query->where('sucursal_id', $unidadId);
                break;

            case SecSecuencia::AMBITO_SEDE:
                $query->where('sede_id', $unidadId);
                break;

            case SecSecuencia::AMBITO_EMPRESA:
            default:
                // Ámbito empresa: solo debe existir un detalle por secuencia
                break;
        }

        $detalle = $query->first();

        if (!$detalle) {
            throw new \RuntimeException(
                "No existe detalle de secuencia para la unidad operativa indicada (ID: {$unidadId})."
            );
        }

        if (!$detalle->patron) {
            throw new \RuntimeException(
                "El detalle de secuencia no tiene un patrón asignado."
            );
        }

        return $detalle;
    }

    /**
     * Aplica el patrón al número consecutivo.
     *
     * Soporta:
     *   - '#'    → dígito del consecutivo con padding de ceros
     *   - '%Y'   → año de 4 dígitos (2026)
     *   - '%y'   → año de 2 dígitos (26)
     *   - '%M'   → mes con cero (05)
     *   - '%D'   → día con cero (08)
     *
     * Ejemplos:
     *   aplicarPatron("BTA####", 10, 4)       → "BTA0010"
     *   aplicarPatron("CN######", 5, 6)        → "CN000005"
     *   aplicarPatron("%Y%M-####", 1, 4)       → "202605-0001"
     *   aplicarPatron("##", 99, 2)             → "99"
     */
    public function aplicarPatron(string $patron, int $siguiente, int $rango): string
    {
        $now = Carbon::now();

        // Reemplazar variables de fecha
        $resultado = str_replace([
            '%Y', '%y', '%M', '%D',
        ], [
            $now->format('Y'),
            $now->format('y'),
            $now->format('m'),
            $now->format('d'),
        ], $patron);

        // Contar los '#' para determinar el padding
        $cantidadHash = substr_count($resultado, '#');
        $padding = max($cantidadHash, $rango);
        $numero  = str_pad($siguiente, $padding, '0', STR_PAD_LEFT);

        // Reemplazar bloque de '#' por el número formateado
        $resultado = preg_replace('/#+/', $numero, $resultado);

        return $resultado;
    }

    /**
     * Previsualiza cómo quedaría el próximo consecutivo sin incrementar el contador.
     */
    public function previsualizar(int $empresaId, int $moduloId, ?int $procesoId = null, ?int $unidadId = null): string
    {
        $secuencia = SecSecuencia::where('empresa_id', $empresaId)
            ->where('modulo_id', $moduloId)
            ->where('proceso_id', $procesoId)
            ->where('estado', true)
            ->firstOrFail();

        $detalle = $this->obtenerDetalle($secuencia, $unidadId);

        return $this->aplicarPatron(
            $detalle->patron->patron,
            $detalle->siguiente_numero,
            $secuencia->rango
        );
    }
}
