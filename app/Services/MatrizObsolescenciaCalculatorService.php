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
            
            // Refrescar después de calcular valoración de edad (crítico para validación de obsolescencia)
            $activo->detalles->refresh();
            
            $this->calcularMaxRam($activo->detalles);
            $this->calcularValoracionRam($activo->detalles);
            $this->calcularValoracionProcesador($activo->detalles);
            $this->calcularValoracionDisco($activo->detalles);
            
            // Refrescar antes de calcular puntaje general
            $activo->refresh();
            $this->calcularPuntajeGeneral($activo);
            
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
            return;
        }
        
        // Obtener vida útil según el tipo específico
        $vidaUtilAnios = $this->obtenerVidaUtilPorTipoEspecifico($detalle->tipo);
        
        if ($vidaUtilAnios > 0) {
            // Calcular: edad / vida_util_años
            $valorVidaUtil = ($detalle->edad / $vidaUtilAnios)*100;
            $detalle->update(['edad_v_util' => $valorVidaUtil]);
        } else {
            // Si no se encuentra el tipo, asignar NULL
            $detalle->update(['edad_v_util' => null]);
        }
    }
    
    /**
     * Calcular valoración de edad basada en rangos definidos
     * - Edad < 5 años: valor = 100
     * - Edad entre 5 y 8 años: valor = 50
     * - Edad > 8 años: valor = 0 (obsoleto)
     */
    protected function calcularValoracionEdad($detalle)
    {
        if ($detalle->edad === null) {
            // Si edad es NULL, establecer valoración como NULL
            $detalle->update(['valoracion_edad' => null]);
            return;
        }
        
        $valoracion = 0;
        
        // Aplicar lógica de valoración por edad
        if ($detalle->edad < 5) {
            $valoracion = 100;
        } elseif ($detalle->edad >= 5 && $detalle->edad <= 8) {
            $valoracion = 50;
        } else {
            $valoracion = 0;
        }
        
        $detalle->update(['valoracion_edad' => $valoracion]);
    }
    
    /**
     * Calcular MaxRAM del equipo
     * Si no está definido manualmente, se calcula como RAM actual × 2
     */
    protected function calcularMaxRam($detalle)
    {
        // Si max_ram ya tiene un valor (fue ingresado manualmente), no lo sobrescribimos
        if ($detalle->max_ram !== null && $detalle->max_ram > 0) {
            return;
        }
        
        // Si no hay RAM actual, establecer max_ram como NULL
        if (!$detalle->tamano_ram || $detalle->tamano_ram <= 0) {
            $detalle->update(['max_ram' => null]);
            return;
        }
        
        // Calcular MaxRAM como el doble de la RAM actual
        $maxRam = $detalle->tamano_ram * 2;
        $detalle->update(['max_ram' => $maxRam]);
    }
    
    /**
     * Calcular valoración de RAM basada en características mínimas y capacidad de expansión
     * - Si tamano_ram == max_ram (sin capacidad de expansión) → Puntaje = 0
     * - Si tamano_ram < ram_minima → Puntaje = 50
     * - Si tamano_ram >= ram_minima Y max_ram > tamano_ram → Puntaje = 100
     */
    protected function calcularValoracionRam($detalle)
    {
        if (!$detalle->tamano_ram) {
            $detalle->update(['valoracion_ram' => null]);
            
            Log::debug("Valoración RAM establecida como NULL - sin RAM", [
                'activo_id' => $detalle->activo_c_id
            ]);
            return;
        }
        
        // Obtener RAM mínima requerida de los parámetros
        $ramMinima = $this->obtenerCaracteristicaMinima('Capacidad Mínima Memoria RAM (GB)');
        
        if (!$ramMinima) {
            $ramMinima = 8; // Valor por defecto
        }
        
        $tamanoRam = $detalle->tamano_ram;
        $maxRam = $detalle->max_ram;
        
        // Determinar valoración según la lógica especificada
        // PRIORIDAD 1: Si RAM actual == MaxRAM (sin capacidad de expansión) → 0
        if ($maxRam !== null && $tamanoRam == $maxRam) {
            $valoracion = 0;
            $razon = "Sin capacidad de expansión (RAM actual = MaxRAM)";
        }
        // PRIORIDAD 2: Si RAM < mínima requerida → 50
        elseif ($tamanoRam < $ramMinima) {
            $valoracion = 50;
            $razon = "RAM insuficiente (menor a mínima requerida)";
        }
        // PRIORIDAD 3: Si RAM >= mínima Y tiene capacidad de expansión → 100
        elseif ($tamanoRam >= $ramMinima && ($maxRam === null || $maxRam > $tamanoRam)) {
            $valoracion = 100;
            $razon = "RAM cumple con mínimo y tiene capacidad de expansión";
        }
        // Caso por defecto
        else {
            $valoracion = 50;
            $razon = "Caso por defecto";
        }
        
        $detalle->update(['valoracion_ram' => $valoracion]);
        
        Log::debug("Valoración RAM calculada", [
            'activo_id' => $detalle->activo_c_id,
            'tamano_ram' => $tamanoRam,
            'max_ram' => $maxRam,
            'ram_minima' => $ramMinima,
            'valoracion' => $valoracion,
            'razon' => $razon
        ]);
    }
    
    /**
     * Calcular valoración del procesador basado en año de lanzamiento
     * - Si año < (año actual - 7): valoración = 0
     * - Si año >= (año actual - 7): valoración = 100
     */
    protected function calcularValoracionProcesador($detalle)
    {
        if (!$detalle->procesador) {
            $detalle->update(['valoracion_procesador' => null]);
            
            Log::debug("Valoración procesador establecida como NULL - sin procesador", [
                'activo_id' => $detalle->activo_c_id
            ]);
            return;
        }
        
        try {
            // Buscar el procesador en la tabla matzobs_procesadores
            $procesadorDB = \DB::table('matzobs_procesadores')
                ->where('nombre', $detalle->procesador)
                ->first();
            
            if (!$procesadorDB || !$procesadorDB->anio_lanzamiento) {
                // Si no se encuentra o no tiene año, establecer valoración como NULL
                $detalle->update(['valoracion_procesador' => null]);
                
                Log::debug("Valoración procesador establecida como NULL - no encontrado en BD o sin año", [
                    'activo_id' => $detalle->activo_c_id,
                    'procesador' => $detalle->procesador,
                    'encontrado' => $procesadorDB ? 'sí' : 'no',
                    'tiene_anio' => $procesadorDB ? ($procesadorDB->anio_lanzamiento ? 'sí' : 'no') : 'N/A'
                ]);
                return;
            }
            
            // Calcular el año mínimo aceptable (año actual - 7)
            $anioActual = now()->year;
            $anioMinimo = $anioActual - 7;
            $anioLanzamiento = (int) $procesadorDB->anio_lanzamiento;
            
            // Determinar valoración según el año
            if ($anioLanzamiento < $anioMinimo) {
                $valoracion = 0; // Procesador obsoleto
                $estado = 'obsoleto';
            } else {
                $valoracion = 100; // Procesador actual
                $estado = 'actual';
            }
            
            $detalle->update(['valoracion_procesador' => $valoracion]);
            
            Log::debug("Valoración procesador calculada por año", [
                'activo_id' => $detalle->activo_c_id,
                'procesador' => $detalle->procesador,
                'anio_lanzamiento' => $anioLanzamiento,
                'anio_actual' => $anioActual,
                'anio_minimo' => $anioMinimo,
                'estado' => $estado,
                'valoracion' => $valoracion
            ]);
            
        } catch (\Exception $e) {
            Log::error("Error calculando valoración de procesador", [
                'activo_id' => $detalle->activo_c_id,
                'procesador' => $detalle->procesador,
                'error' => $e->getMessage()
            ]);
            
            // En caso de error, establecer como NULL
            $detalle->update(['valoracion_procesador' => null]);
        }
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
        
        // Nueva condición: Ajuste adicional por tamaño específico
        if ($detalle->tamano_disco) {
            $capacidadGB = $detalle->tamano_disco;
            
            if ($capacidadGB < 480) {
                // Si es menor a 480GB, forzar a 50
                $valoracion = 50;
            } elseif ($capacidadGB >= 480 && $valoracion == 85) {
                // Si es >= 480GB y el puntaje es exactamente 85, subir a 100
                $valoracion = 100;
            } elseif ($capacidadGB >= 480 && $valoracion > 85) {
                // Si es >= 480GB y el puntaje es mayor a 85, mantener en 100
                $valoracion = 100;
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
     * Lógica:
     * - Si no hay 4 valores numéricos → puntaje = NULL
     * - Si valoracion_edad = 0 → puntaje = 0
     * - Si valoracion_edad = 100 → puntaje = 100
     * - Si valoracion_edad = 50 Y valoracion_ram = 0 → puntaje = 0
     * - Si valoracion_ram = 50 → puntaje = PROMEDIO - 1
     * - En cualquier otro caso → puntaje = PROMEDIO
     */
    protected function calcularPuntajeGeneral($activo)
    {
        $detalle = $activo->detalles;
        
        if (!$detalle) {
            return;
        }
        
        // Obtener las 4 valoraciones
        $valoracionEdad = $detalle->valoracion_edad;
        $valoracionRam = $detalle->valoracion_ram;
        $valoracionProcesador = $detalle->valoracion_procesador;
        $valoracionDisco = $detalle->valoracion_disco;
        
        // Verificar si hay 4 valores numéricos
        $valoresNumericos = 0;
        if ($valoracionEdad !== null) $valoresNumericos++;
        if ($valoracionRam !== null) $valoresNumericos++;
        if ($valoracionProcesador !== null) $valoresNumericos++;
        if ($valoracionDisco !== null) $valoresNumericos++;
        
        // Si no hay 4 valores numéricos, devolver NULL
        if ($valoresNumericos < 4) {
            $activo->update(['puntaje' => null]);
            
            Log::debug("Puntaje general = NULL - No hay 4 valores numéricos", [
                'activo_id' => $activo->id,
                'valores_numericos' => $valoresNumericos,
                'valoracion_edad' => $valoracionEdad,
                'valoracion_ram' => $valoracionRam,
                'valoracion_procesador' => $valoracionProcesador,
                'valoracion_disco' => $valoracionDisco
            ]);
            return;
        }
        
        // Convertir a números para cálculos
        $n15 = (float) $valoracionEdad;
        $r15 = (float) $valoracionRam;
        $u15 = (float) $valoracionProcesador;
        $y15 = (float) $valoracionDisco;
        
        $puntaje = null;
        $razon = '';
        
        // Aplicar lógica según las reglas especificadas
        if ($n15 == 0) {
            // Si valoracion_edad = 0 → puntaje = 0
            $puntaje = 0;
            $razon = 'Valoración edad = 0 (obsoleto)';
        } elseif ($n15 == 100) {
            // Si valoracion_edad = 100 → puntaje = 100
            $puntaje = 100;
            $razon = 'Valoración edad = 100 (nuevo)';
        } elseif ($n15 == 50 && $r15 == 0) {
            // Si valoracion_edad = 50 Y valoracion_ram = 0 → puntaje = 0
            $puntaje = 0;
            $razon = 'Valoración edad = 50 y RAM = 0';
        } elseif ($r15 == 50) {
            // Si valoracion_ram = 50 → puntaje = PROMEDIO - 1
            $promedio = ($n15 + $r15 + $u15 + $y15) / 4;
            $puntaje = $promedio - 1;
            $razon = 'Valoración RAM = 50, promedio - 1';
        } else {
            // En cualquier otro caso → puntaje = PROMEDIO
            $promedio = ($n15 + $r15 + $u15 + $y15) / 4;
            $puntaje = $promedio;
            $razon = 'Promedio de las 4 valoraciones';
        }
        
        $activo->update(['puntaje' => round($puntaje, 2)]);
        
        Log::debug("Puntaje general calculado", [
            'activo_id' => $activo->id,
            'valoracion_edad' => $n15,
            'valoracion_ram' => $r15,
            'valoracion_procesador' => $u15,
            'valoracion_disco' => $y15,
            'puntaje' => round($puntaje, 2),
            'razon' => $razon
        ]);
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