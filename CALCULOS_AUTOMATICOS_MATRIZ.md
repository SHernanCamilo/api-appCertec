# Sistema de Cálculos Automáticos - Matriz de Obsolescencia

## Descripción

Este sistema permite calcular automáticamente valores para la matriz de obsolescencia después de sincronizar activos desde GLPI. Los cálculos incluyen edad, vida útil, valoraciones de componentes y puntaje general.

## Componentes del Sistema

### 1. Servicio de Cálculo (`MatrizObsolescenciaCalculatorService`)

**Ubicación:** `app/Services/MatrizObsolescenciaCalculatorService.php`

**Funciones principales:**
- Calcular edad del equipo basada en fecha de compra
- Determinar vida útil según tipo de equipo
- Calcular valoraciones de RAM, procesador y disco
- Generar puntaje general ponderado

### 2. Comando Artisan (`CalcularValoresMatrizObsolescencia`)

**Ubicación:** `app/Console/Commands/CalcularValoresMatrizObsolescencia.php`

**Uso:**
```bash
# Calcular valores para todos los activos
php artisan matriz:calcular-valores

# Calcular solo activos sin valores previos
php artisan matriz:calcular-valores --solo-nuevos

# Calcular activo específico
php artisan matriz:calcular-valores --activo=123

# Forzar recálculo de todos los valores
php artisan matriz:calcular-valores --force

# Procesar en lotes más pequeños
php artisan matriz:calcular-valores --batch=25
```

### 3. Integración con Sincronización GLPI

El comando de sincronización (`SincronizarActivosGlpi`) ahora incluye cálculos automáticos:

```bash
# Sincronizar y calcular automáticamente
php artisan glpi:sync-activos

# Sincronizar sin calcular
php artisan glpi:sync-activos --skip-calculations

# Solo calcular (sin sincronizar)
php artisan glpi:sync-activos --calculate-only
```

### 4. API Endpoint

**Endpoint:** `POST /api/matriz-obsolescencia/calcular-valores`

**Parámetros:**
```json
{
  "activo_id": 123,        // Opcional: ID específico
  "batch_size": 50,        // Opcional: Tamaño de lote
  "force": false,          // Opcional: Forzar recálculo
  "solo_nuevos": true      // Opcional: Solo nuevos
}
```

### 5. Interfaz Frontend

En el componente `parametrosMaObsolescencia` hay botones para:
- **Solo Nuevos:** Calcular activos sin valores previos
- **Recalcular Todo:** Recalcular todos los activos

## Fórmulas de Cálculo

### 1. Edad del Equipo
```
Si fecha_compra existe:
  edad = fecha_actual - fecha_compra (en años)
Si fecha_compra es NULL:
  edad = NULL
```

### 2. Vida Útil
Basada en parámetros específicos de la tabla `matzobs_parametros` (id_grupo = 4):
- **All in One/Desktop/Mini PC**: Parámetro ID 11
- **Convertible/Notebook/Laptop**: Parámetro ID 12  
- **Tower**: Parámetro ID 13
- **Otros tipos**: NULL

**Fórmula:**
```
Si edad es NULL o tipo no encontrado:
  edad_v_util = NULL
Si edad existe y tipo encontrado:
  edad_v_util = edad / vida_util_años_del_parametro
```

### 3. Valoración de Edad
```
Si edad es NULL o edad_v_util es NULL:
  valoracion_edad = NULL
Si ambos valores existen:
  porcentaje_vida = edad_v_util * 100
  valoracion_edad = max(0, 100 - porcentaje_vida)
```

### 4. Valoración de RAM
Basada en múltiplos de RAM mínima requerida:
- >= 4x RAM mínima: 100 puntos
- >= 2x RAM mínima: 80 puntos
- >= 1.5x RAM mínima: 60 puntos
- >= RAM mínima: 40 puntos
- < RAM mínima: 20 puntos

### 5. Valoración de Procesador
Basada en análisis del modelo:
- Intel i7/i9 recientes (10ª gen+): 95 puntos
- Intel i5 recientes: 85 puntos
- AMD Ryzen 7/9: 90 puntos
- AMD Ryzen 5: 80 puntos
- Procesadores antiguos: 40-60 puntos
- Pentium/Celeron: 25 puntos

Ajustes por núcleos:
- 8+ núcleos: +10 puntos
- 4+ núcleos: +5 puntos
- ≤2 núcleos: -10 puntos

### 6. Valoración de Disco
Por tipo:
- SSD NVMe: 95 puntos
- SSD SATA: 85 puntos
- HDD: 40 puntos

Ajustes por capacidad:
- ≥1TB: +10 puntos
- 500GB-1TB: +5 puntos
- <250GB: -15 puntos

### 7. Puntaje General
Promedio ponderado:
- Edad: 30%
- RAM: 25%
- Procesador: 25%
- Disco: 20%

## Ejemplos de Uso

### Ejemplo 1: Sincronización Completa con Cálculos
```bash
# Sincronizar todos los activos y calcular valores automáticamente
php artisan glpi:sync-activos --full-sync
```

### Ejemplo 2: Cálculo Manual Específico
```bash
# Calcular valores para un activo específico
php artisan matriz:calcular-valores --activo=456
```

### Ejemplo 3: Recálculo Masivo
```bash
# Recalcular todos los valores forzadamente
php artisan matriz:calcular-valores --force --batch=100
```

### Ejemplo 4: API desde Frontend
```typescript
// Ejecutar cálculos solo para nuevos activos
this.matrizService.ejecutarCalculos({
  solo_nuevos: true,
  batch_size: 50
}).subscribe(response => {
  console.log('Cálculos completados:', response.data);
});
```

## Configuración de Parámetros

Los cálculos utilizan parámetros configurables desde la interfaz:

1. **Tipos de Equipos:** Define vida útil por tipo
2. **Características Mínimas:** Define RAM mínima requerida
3. **Rangos de Edad:** Define puntajes por rangos de edad
4. **Conceptos de Puntaje:** Define rangos de clasificación

## Logs y Monitoreo

Los cálculos se registran en:
- **Canal:** `glpi_sync`
- **Archivo:** `storage/logs/ActivosGLPI.log`

Información registrada:
- Inicio y fin de cálculos
- Estadísticas de procesamiento
- Errores y advertencias
- Valores calculados por activo

## Consideraciones de Rendimiento

- **Procesamiento por lotes:** Evita sobrecarga de memoria
- **Cache de parámetros:** Reduce consultas repetitivas
- **Transacciones:** Garantiza consistencia de datos
- **Throttling:** Controla carga del sistema

## Troubleshooting

### Error: "Activo no encontrado"
- Verificar que el activo existe en `matzobs_activos_c`
- Verificar que tiene registro en `matzobs_activos_d`

### Error: "Parámetros no configurados"
- Configurar tipos de equipos en parámetros
- Configurar características mínimas
- Verificar que los grupos de parámetros existen

### Cálculos incorrectos
- Verificar fecha de compra del activo
- Revisar configuración de vida útil por tipo
- Comprobar valores de RAM/procesador/disco

### Rendimiento lento
- Reducir tamaño de lote (`--batch`)
- Ejecutar en horarios de menor carga
- Verificar índices de base de datos