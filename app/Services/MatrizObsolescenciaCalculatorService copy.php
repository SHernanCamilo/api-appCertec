<?php

namespace App\Services;

use App\Models\MatrizObsolescencia\MatzobsActivosC;
use App\Models\MatrizObsolescencia\MatzobsActivosD;
use App\Models\MatrizObsParametro;
use App\Models\MatrizObsGrupoParametro;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MatrizObsolescenciaCalculatorService
{
    protected $parametrosCache = [];
    
    /**
     * Calcular todos los valores automáticos para un activo específico
     */
    public function calcularValoresActivo($activoId)
    {
        try {
            $activo = MatzobsActivosC::with('detalles')->find($activoId);
            
            if (!$activo || !$activo->detalles) {
                Log::warning("Activo no encontrado o sin detalles para cálculo", ['activo_id' => $activoId]);
                return false;
            }
            
            // Calcular edad y obtener el valor calculado
            $edadCalculada = $this->calcularEdad($activo->detalles);
            
            // Refrescar el modelo para obtener los valores actualizados
            $activo->detalles->refresh();
            
            $this->calcularVidaUtil($activo->detalles);
            
            // Refrescar nuevamente después de calcular vida útil
            $activo->detalles->refresh();
            
            $this->calcularValoracionEdad($activo->detalles);
            $this->calcularValoracionRam($activo->detalles);
            $this->calcularValoracionProcesador($activo->detalles);
            $this->calcularValoracionDisco($activo->detalles);
            $this->calcularPuntajeGeneral($activo);
            
            Log::info("Valores calculados para activo", [
                'activo_id' => $activoId,
                'nombre_equipo' => $activo->nombre_equipo
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error("Error calculando valores para activo", [
                'activo_id' => $activoId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
    
    /**
     * Calcular valores para múltiples activos (por lotes)
     */
    public function calcularValoresLote($activoIds = null, $batchSize = 50)
    {
        $query = MatzobsActivosC::with('detalles');
        
        if ($activoIds) {
            $query->whereIn('id', $activoIds);
        }
        
        $totalActivos = $query->count();
        $procesados = 0;
        $exitosos = 0;
        $errores = 0;
        
        Log::info("Iniciando cálculo de valores en lotes", [
            'total_activos' => $totalActivos,
            'batch_size' => $batchSize
        ]);
        
        $query->chunk($batchSize, function ($activos) use (&$procesados, &$exitosos, &$errores) {
            foreach ($activos as $activo) {
                if ($this->calcularValoresActivo($activo->id)) {
                    $exitosos++;
                } else {
                    $errores++;
                }
                $procesados++;
            }
            
            // Log de progreso cada lote
            Log::info("Progreso cálculo de valores", [
                'procesados' => $procesados,
                'exitosos' => $exitosos,
                'errores' => $errores
            ]);
        });
        
        return [
            'total' => $totalActivos,
            'procesados' => $procesados,
            'exitosos' => $exitosos,
            'errores' => $errores
        ];
    }
    
    /**
     * Calcular la edad del equipo basada en fecha_compra
     * La edad se calcula en años con un decimal (considerando meses)
     * @return float|null La edad calculada o null si no hay fecha de compra
     */
    protected function calcularEdad($detalle)
    {
        if (!$detalle->fecha_compra) {
            // Si no hay fecha de compra, establecer edad como NULL
            $detalle->update(['edad' => null]);
            
            Log::debug("Edad establecida como NULL - sin fecha de compra", [
                'activo_id' => $detalle->activo_c_id,
                'fecha_compra' => $detalle->fecha_compra
            ]);
            return null;
        }
        
        // Calcular edad basada en fecha de compra
        $fechaCompra = Carbon::parse($detalle->fecha_compra);
        $ahora = now();
        
        // Calcular años completos
        $anios = $fechaCompra->diffInYears($ahora);
        
        // Calcular meses adicionales después de los años completos
        $fechaDespuesAnios = $fechaCompra->copy()->addYears($anios);
        $mesesAdicionales = $fechaDespuesAnios->diffInMonths($ahora);
        
        // Convertir meses a decimal (1 mes = 0.1 aprox)
        $edadDecimal = $anios + round($mesesAdicionales / 12, 1);
        
        $detalle->update(['edad' => $edadDecimal]);
        
        Log::debug("Edad calculada en decimal", [
            'activo_id' => $detalle->activo_c_id,
            'fecha_compra' => $detalle->fecha_compra,
            'anios' => $anios,
            'meses_adicionales' => $mesesAdicionales,
            'edad_decimal' => $edadDecimal
        ]);
        
        return $edadDecimal;
    }
    
    /** 
     * Calcular vida útil basada en el tipo de equipo y parámetros específicos
     */
    protected function calcularVidaUtil($detalle)
    {
        if ($detalle->edad === null || !$detalle->tipo) {
            // Si no hay edad o tipo, establecer edad_v_util como NULL
            $detalle->update(['edad_v_util' => null]);
            
            Log::debug("Vida útil establecida como NULL ✅✅✅", [
                'activo_id' => $detalle->activo_c_id,
                'edad' => $detalle->edad,
                'tipo' => $detalle->tipo,
                'razon' => $detalle->edad === null ? 'edad es NULL' : 'tipo no disponible'
            ]);
            return;
        }
        
        // Obtener vida útil según el tipo específico
        $vidaUtilAnios = $this->obtenerVidaUtilPorTipoEspecifico($detalle->tipo);
        
        if ($vidaUtilAnios > 0) {
            // Calcular: edad / vida_util_años
            $valorVidaUtil = ($detalle->edad / $vidaUtilAnios)*100;
            
            $detalle->update(['edad_v_util' => $valorVidaUtil]);
            
            Log::debug("✅ Vida útil calculada", [
                'activo_id' => $detalle->activo_c_id,
                'tipo' => $detalle->tipo,
                'edad' => $detalle->edad,
                'vida_util_anios' => $vidaUtilAnios,
                'valor_calculado' => $valorVidaUtil 
            ]);
        } else {
            // Si no se encuentra el tipo, asignar NULL
            $detalle->update(['edad_v_util' => null]);
            
            Log::debug("Vida útil establecida como NULL - tipo no encontrado", [
                'activo_id' => $detalle->activo_c_id,
                'tipo' => $detalle->tipo
            ]);
        }
    }
    
    /**
     * Calcular valoración de edad basada en rangos de parámetros
     * Busca en matzobs_parametros con id_grupo = 1 (RANGOS EDAD)
     * Compara la EDAD (en años) con los rangos
     */
    protected function calcularValoracionEdad($detalle)
    {
        if ($detalle->edad === null) {
            // Si edad es NULL, establecer valoración como NULL
            $detalle->update(['valoracion_edad' => null]);
            
            Log::debug("Valoración edad establecida como NULL", [
                'activo_id' => $detalle->activo_c_id,
                'edad' => $detalle->edad,
                'razon' => 'edad es NULL'
            ]);
            return;
        }
        
        // Obtener rangos de edad desde parámetros (id_grupo = 1)
        $rangos = MatrizObsParametro::where('id_grupo', 1)
            ->orderBy('rango_i', 'asc')
            ->get();
        
        $valoracion = null;
        
        // Buscar en qué rango cae la edad
        foreach ($rangos as $rango) {
            if ($detalle->edad >= $rango->rango_i && $detalle->edad <= $rango->rango_f) {
                $valoracion = $rango->valor;
                
                Log::debug("Valoración edad encontrada en rango", [
                    'activo_id' => $detalle->activo_c_id,
                    'edad' => $detalle->edad,
                    'rango_nombre' => $rango->nombre,
                    'rango_i' => $rango->rango_i,
                    'rango_f' => $rango->rango_f,
                    'valoracion' => $valoracion
                ]);
                
                break;
            }
        }
        
        // Si no se encuentra en ningún rango, usar el último rango (peor valoración)
        if ($valoracion === null && $rangos->isNotEmpty()) {
            $ultimoRango = $rangos->last();
            $valoracion = $ultimoRango->valor;
            
            Log::debug("Valoración edad usando último rango (fuera de rangos)", [
                'activo_id' => $detalle->activo_c_id,
                'edad' => $detalle->edad,
                'valoracion' => $valoracion
            ]);
        }
        
        $detalle->update(['valoracion_edad' => $valoracion]);
    }
    
    /**
     * Calcular valoración de RAM basada en características mínimas
     */
    protected function calcularValoracionRam($detalle)
    {
        if (!$detalle->tamano_ram) {
            return;
        }
        
        // Obtener RAM mínima requerida de los parámetros
        $ramMinima = $this->obtenerCaracteristicaMinima('Capacidad Mínima Memoria RAM (GB)');
        
        if (!$ramMinima) {
            $ramMinima = 8; // Valor por defecto
        }
        
        // Calcular valoración basada en múltiplos de la RAM mínima
        if ($detalle->tamano_ram >= $ramMinima * 4) {
            $valoracion = 100; // Excelente
        } elseif ($detalle->tamano_ram >= $ramMinima * 2) {
            $valoracion = 80; // Muy bueno
        } elseif ($detalle->tamano_ram >= $ramMinima * 1.5) {
            $valoracion = 60; // Bueno
        } elseif ($detalle->tamano_ram >= $ramMinima) {
            $valoracion = 40; // Mínimo aceptable
        } else {
            $valoracion = 20; // Insuficiente
        }
        
        $detalle->update(['valoracion_ram' => $valoracion]);
        
        Log::debug("Valoración RAM calculada", [
            'activo_id' => $detalle->activo_c_id,
            'ram_actual' => $detalle->tamano_ram,
            'ram_minima' => $ramMinima,
            'valoracion' => $valoracion
        ]);
    }
    
    /**
     * Calcular valoración del procesador
     */
    protected function calcularValoracionProcesador($detalle)
    {
        if (!$detalle->procesador) {
            return;
        }
        
        $valoracion = 50; // Valor base
        
        // Analizar el procesador por generación y características
        $procesador = strtolower($detalle->procesador);
        
        // Intel Core i7/i9 recientes
        if (preg_match('/i[79]-1[0-9]/', $procesador)) {
            $valoracion = 95;
        }
        // Intel Core i5 recientes
        elseif (preg_match('/i5-1[0-9]/', $procesador)) {
            $valoracion = 85;
        }
        // Intel Core i3 recientes
        elseif (preg_match('/i3-1[0-9]/', $procesador)) {
            $valoracion = 70;
        }
        // AMD Ryzen 7/9
        elseif (preg_match('/ryzen [79]/', $procesador)) {
            $valoracion = 90;
        }
        // AMD Ryzen 5
        elseif (preg_match('/ryzen 5/', $procesador)) {
            $valoracion = 80;
        }
        // AMD Ryzen 3
        elseif (preg_match('/ryzen 3/', $procesador)) {
            $valoracion = 65;
        }
        // Procesadores más antiguos
        elseif (preg_match('/i[579]-[6-9]/', $procesador)) {
            $valoracion = 60;
        }
        elseif (preg_match('/i[579]-[3-5]/', $procesador)) {
            $valoracion = 40;
        }
        // Procesadores muy antiguos
        elseif (preg_match('/pentium|celeron|atom/', $procesador)) {
            $valoracion = 25;
        }
        
        // Ajustar por número de núcleos
        if ($detalle->numero_procesador) {
            if ($detalle->numero_procesador >= 8) {
                $valoracion = min(100, $valoracion + 10);
            } elseif ($detalle->numero_procesador >= 4) {
                $valoracion = min(100, $valoracion + 5);
            } elseif ($detalle->numero_procesador <= 2) {
                $valoracion = max(20, $valoracion - 10);
            }
        }
        
        $detalle->update(['valoracion_procesador' => $valoracion]);
        
        Log::debug("Valoración procesador calculada", [
            'activo_id' => $detalle->activo_c_id,
            'procesador' => $detalle->procesador,
            'numero_procesador' => $detalle->numero_procesador,
            'valoracion' => $valoracion
        ]);
    }
    
    /**
     * Calcular valoración del disco
     */
    protected function calcularValoracionDisco($detalle)
    {
        if (!$detalle->tipo_disco && !$detalle->tamano_disco) {
            return;
        }
        
        $valoracion = 50; // Valor base
        
        // Valoración por tipo de disco
        $tipoDisco = strtolower($detalle->tipo_disco ?? '');
        
        if (strpos($tipoDisco, 'nvme') !== false) {
            $valoracion = 95; // SSD NVMe es lo mejor
        } elseif (strpos($tipoDisco, 'ssd') !== false) {
            $valoracion = 85; // SSD SATA es muy bueno
        } elseif (strpos($tipoDisco, 'hdd') !== false || strpos($tipoDisco, 'sata') !== false) {
            $valoracion = 40; // HDD es básico
        }
        
        // Ajustar por capacidad
        if ($detalle->tamano_disco) {
            $capacidadGB = $detalle->tamano_disco;
            
            if ($capacidadGB >= 1000) { // 1TB o más
                $valoracion = min(100, $valoracion + 10);
            } elseif ($capacidadGB >= 500) { // 500GB - 1TB
                $valoracion = min(100, $valoracion + 5);
            } elseif ($capacidadGB < 250) { // Menos de 250GB
                $valoracion = max(20, $valoracion - 15);
            }
        }
        
        $detalle->update(['valoracion_disco' => $valoracion]);
        
        Log::debug("Valoración disco calculada", [
            'activo_id' => $detalle->activo_c_id,
            'tipo_disco' => $detalle->tipo_disco,
            'tamano_disco' => $detalle->tamano_disco,
            'valoracion' => $valoracion
        ]);
    }
    
    /**
     * Calcular puntaje general del activo
     */
    protected function calcularPuntajeGeneral($activo)
    {
        $detalle = $activo->detalles;
        
        if (!$detalle) {
            return;
        }
        
        // Pesos para cada componente
        $pesos = [
            'edad' => 0.30,        // 30% - La edad es muy importante
            'ram' => 0.25,         // 25% - RAM es crítica
            'procesador' => 0.25,  // 25% - Procesador es crítico
            'disco' => 0.20        // 20% - Disco es importante pero menos crítico
        ];
        
        $puntajeTotal = 0;
        $pesoTotal = 0;
        
        // Sumar valoraciones ponderadas solo de componentes válidos
        if ($detalle->valoracion_edad !== null) {
            $puntajeTotal += $detalle->valoracion_edad * $pesos['edad'];
            $pesoTotal += $pesos['edad'];
        }
        
        if ($detalle->valoracion_ram !== null) {
            $puntajeTotal += $detalle->valoracion_ram * $pesos['ram'];
            $pesoTotal += $pesos['ram'];
        }
        
        if ($detalle->valoracion_procesador !== null) {
            $puntajeTotal += $detalle->valoracion_procesador * $pesos['procesador'];
            $pesoTotal += $pesos['procesador'];
        }
        
        if ($detalle->valoracion_disco !== null) {
            $puntajeTotal += $detalle->valoracion_disco * $pesos['disco'];
            $pesoTotal += $pesos['disco'];
        }
        
        // Solo calcular si tenemos al menos 2 componentes válidos y peso total > 0
        if ($pesoTotal > 0 && ($detalle->valoracion_edad !== null || $detalle->valoracion_ram !== null)) {
            // Normalizar el puntaje según los pesos disponibles
            $puntajeFinal = $puntajeTotal / $pesoTotal;
            
            $activo->update(['puntaje' => round($puntajeFinal, 2)]);
            
            Log::debug("Puntaje general calculado", [
                'activo_id' => $activo->id,
                'peso_total' => $pesoTotal,
                'puntaje_total' => $puntajeTotal,
                'puntaje_final' => $puntajeFinal
            ]);
        } else {
            Log::debug("Puntaje general no calculado - componentes insuficientes", [
                'activo_id' => $activo->id,
                'peso_total' => $pesoTotal
            ]);
        }
    }
    
    /**
     * Obtener vida útil por tipo específico desde parámetros
     * Busca en matzobs_parametros con id_grupo = 4 usando LIKE en el nombre
     */
    protected function obtenerVidaUtilPorTipoEspecifico($tipo)
    {
        if (!$tipo) {
            return 0;
        }
        
        // Buscar en cache primero
        $cacheKey = "vida_util_especifica_{$tipo}";
        if (isset($this->parametrosCache[$cacheKey])) {
            return $this->parametrosCache[$cacheKey];
        }
        
        try {
            $tipoUpper = strtoupper($tipo);
            
            // Buscar el parámetro usando LIKE en el nombre
            $parametro = MatrizObsParametro::where('id_grupo', 4)
                ->where('nombre', 'LIKE', "%{$tipoUpper}%")
                ->first();
            
            if ($parametro && $parametro->valor) {
                $vidaUtilAnios = (float) $parametro->valor;
                $this->parametrosCache[$cacheKey] = $vidaUtilAnios;
                
                Log::debug("Vida útil encontrada para tipo específico", [
                    'tipo' => $tipo,
                    'parametro_id' => $parametro->id,
                    'parametro_nombre' => $parametro->nombre,
                    'vida_util_anios' => $vidaUtilAnios
                ]);
                
                return $vidaUtilAnios;
            }
            
            // Si no se encuentra el tipo específico, retornar 0 (no cachear)
            Log::warning("Tipo de equipo no encontrado en parámetros específicos", [
                'tipo' => $tipo,
                'tipo_upper' => $tipoUpper,
                'busqueda' => "%{$tipoUpper}%"
            ]);
            
            return 0;
            
        } catch (\Exception $e) {
            Log::warning("Error obteniendo vida útil por tipo específico", [
                'tipo' => $tipo,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
    
    /**
     * Obtener característica mínima desde parámetros
     */
    protected function obtenerCaracteristicaMinima($nombreCaracteristica)
    {
        // Buscar en cache primero
        $cacheKey = "caracteristica_{$nombreCaracteristica}";
        if (isset($this->parametrosCache[$cacheKey])) {
            return $this->parametrosCache[$cacheKey];
        }
        
        try {
            $parametro = MatrizObsParametro::whereHas('grupo', function($query) {
                $query->where('nombre', 'Características Mínimas Computador');
            })
            ->where('nombre', 'LIKE', "%{$nombreCaracteristica}%")
            ->first();
            
            if ($parametro && $parametro->valor) {
                $valor = (float) $parametro->valor;
                $this->parametrosCache[$cacheKey] = $valor;
                return $valor;
            }
            
            return null;
            
        } catch (\Exception $e) {
            Log::warning("Error obteniendo característica mínima", [
                'caracteristica' => $nombreCaracteristica,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
    
    /**
     * Limpiar cache de parámetros
     */
    public function limpiarCache()
    {
        $this->parametrosCache = [];
    }
}