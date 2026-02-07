# Endpoints para Datos Detallados de Computadoras GLPI

## Endpoints Disponibles

Todos los endpoints requieren autenticación JWT y están bajo el prefijo `/api/glpi/computers/{id}/`

### 1. Validar Computadora
```http
GET /api/glpi/computers/{id}/validate
```
**Descripción**: Valida que existe la computadora con el ID especificado.

**Ejemplo**: `GET /api/glpi/computers/2173/validate`

**Respuesta exitosa**:
```json
{
  "success": true,
  "message": "Computadora encontrada",
  "exists": true,
  "data": {
    "id": 2173,
    "name": "PC-OFICINA-001",
    "is_deleted": false,
    "is_template": false
  }
}
```

### 2. Datos Básicos
```http
GET /api/glpi/computers/{id}/basic-info
```
**Descripción**: Obtiene información básica de la computadora (nombre, fabricante, modelo, ubicación, usuario, etc.).

**Datos obtenidos**:
- Nombre del equipo
- Fabricante y modelo
- Ubicación física
- Usuario asignado
- Grupo
- Número de serie
- Estado
- Fechas de creación y modificación

### 3. Información de Memoria RAM
```http
GET /api/glpi/computers/{id}/memory
```
**Descripción**: Obtiene detalles de la memoria RAM instalada.

**Datos obtenidos**:
- Capacidad total en GB
- Número de módulos
- Tipo de memoria (DDR3, DDR4, etc.)
- Frecuencia de cada módulo
- Detalles por módulo individual

**Respuesta ejemplo**:
```json
{
  "success": true,
  "data": {
    "memories": [
      {
        "size_gb": 8,
        "type": "DDR4",
        "frequency": 2400
      }
    ],
    "total_capacity_gb": 16,
    "memory_count": 2
  }
}
```

### 4. Información del Procesador
```http
GET /api/glpi/computers/{id}/processor
```
**Descripción**: Obtiene detalles del procesador.

**Datos obtenidos**:
- Modelo del procesador
- Fabricante
- Frecuencia en MHz
- Número de núcleos
- Número de hilos
- Número de serie

### 5. Información de Discos Duros
```http
GET /api/glpi/computers/{id}/disks
```
**Descripción**: Obtiene información de almacenamiento.

**Datos obtenidos**:
- Capacidad total en GB
- Número de discos
- Tipo de interfaz (SATA, NVMe, etc.)
- Detalles por disco individual
- Fabricante de cada disco

### 6. Sistema Operativo
```http
GET /api/glpi/computers/{id}/operating-system
```
**Descripción**: Obtiene información del sistema operativo.

**Datos obtenidos**:
- Nombre del SO
- Versión
- Service Pack
- Arquitectura (32/64 bits)
- Versión del kernel
- Edición
- Número de licencia

### 7. Información Financiera
```http
GET /api/glpi/computers/{id}/financial
```
**Descripción**: Obtiene datos financieros y de garantía.

**Datos obtenidos**:
- Fecha de compra
- Fecha de puesta en servicio
- Fecha de vencimiento de garantía
- Proveedor
- Número de orden
- Valor de compra
- Antigüedad calculada en años

### 8. Análisis Completo con Obsolescencia
```http
GET /api/glpi/computers/{id}/complete
```
**Descripción**: Obtiene todos los datos anteriores más un análisis de obsolescencia.

**Datos adicionales**:
- Puntuación de obsolescencia (0-100)
- Estado general (Óptimo, Funcional, Potencialmente Obsoleto, Obsoleto)
- Análisis por factores (edad, memoria, procesador)
- Recomendaciones
- Color para visualización

**Respuesta ejemplo**:
```json
{
  "success": true,
  "data": {
    "computer_id": 2173,
    "basic_info": { ... },
    "memory_info": { ... },
    "processor_info": { ... },
    "disk_info": { ... },
    "operating_system": { ... },
    "financial_info": { ... },
    "obsolescence_analysis": {
      "overall_score": 65.5,
      "overall_status": "Funcional",
      "color": "#0dcaf0",
      "factors": {
        "age": {
          "score": 75,
          "status": "Funcional",
          "years": 3
        },
        "memory": {
          "score": 75,
          "status": "Bueno",
          "gb": 16
        },
        "processor": {
          "score": 50,
          "status": "Suficiente",
          "cores": 4
        }
      },
      "recommendation": "El equipo funciona correctamente pero considere planificar una actualización en el mediano plazo."
    }
  }
}
```

## Cómo Probar los Endpoints

### Método 1: Comando Artisan (Recomendado)
```bash
# Probar con ID 2173 (por defecto)
php artisan glpi:test-computer

# Probar con otro ID
php artisan glpi:test-computer 1234
```

### Método 2: cURL
```bash
# 1. Obtener token JWT
curl -X POST "http://localhost:8000/api/auth/login" \
  -H "Content-Type: application/json" \
  -d '{"email": "tu@email.com", "password": "tu_password"}'

# 2. Probar validación
curl -X GET "http://localhost:8000/api/glpi/computers/2173/validate" \
  -H "Authorization: Bearer TU_JWT_TOKEN" \
  -H "Content-Type: application/json"

# 3. Obtener datos básicos
curl -X GET "http://localhost:8000/api/glpi/computers/2173/basic-info" \
  -H "Authorization: Bearer TU_JWT_TOKEN" \
  -H "Content-Type: application/json"

# 4. Obtener análisis completo
curl -X GET "http://localhost:8000/api/glpi/computers/2173/complete" \
  -H "Authorization: Bearer TU_JWT_TOKEN" \
  -H "Content-Type: application/json"
```

### Método 3: Postman/Insomnia
1. Crear colección con base URL: `http://localhost:8000/api`
2. Configurar autenticación Bearer Token
3. Crear requests para cada endpoint

## Algoritmo de Puntuación de Obsolescencia

### Factores Evaluados:
1. **Edad del Equipo (40% del peso)**:
   - 0-2 años: 100 puntos (Óptimo)
   - 3-4 años: 75 puntos (Funcional)
   - 5-6 años: 50 puntos (Potencialmente Obsoleto)
   - 7+ años: 25 puntos (Obsoleto)

2. **Memoria RAM (30% del peso)**:
   - 16+ GB: 100 puntos (Excelente)
   - 8-15 GB: 75 puntos (Bueno)
   - 4-7 GB: 50 puntos (Suficiente)
   - <4 GB: 25 puntos (Insuficiente)

3. **Procesador (30% del peso)**:
   - 8+ núcleos: 100 puntos (Excelente)
   - 4-7 núcleos: 75 puntos (Bueno)
   - 2-3 núcleos: 50 puntos (Suficiente)
   - 1 núcleo: 25 puntos (Insuficiente)

### Clasificación Final:
- **80-100 puntos**: Óptimo (Verde #198754)
- **60-79 puntos**: Funcional (Azul #0dcaf0)
- **40-59 puntos**: Potencialmente Obsoleto (Amarillo #ffc107)
- **0-39 puntos**: Obsoleto (Rojo #dc3545)

## Manejo de Errores

### Errores Comunes:
- **404**: Computadora no encontrada
- **500**: Error de conexión con GLPI
- **401**: Token JWT inválido o expirado
- **403**: Sin permisos para acceder a los datos

### Respuesta de Error Ejemplo:
```json
{
  "success": false,
  "message": "No se encontró la computadora con ID 2173",
  "error": "Item not found"
}
```

## Integración con Matriz de Obsolescencia

Estos endpoints están diseñados para alimentar directamente la matriz de obsolescencia:

1. **Obtener lista de computadoras**: `/api/glpi/computers`
2. **Analizar cada computadora**: `/api/glpi/computers/{id}/complete`
3. **Mostrar en matriz con colores**: Usar `color` del análisis de obsolescencia
4. **Filtrar por estado**: Usar `overall_status`
5. **Ordenar por puntuación**: Usar `overall_score`

## Próximos Pasos

1. ✅ Configurar GLPI y probar conexión
2. ✅ Probar endpoints con ID 2173
3. 🔄 Implementar interfaz frontend
4. 🔄 Integrar con matriz de obsolescencia existente
5. 🔄 Agregar más tipos de equipos (monitores, impresoras)
6. 🔄 Implementar sincronización automática