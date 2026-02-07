# Integración con GLPI API

Esta documentación describe la integración completa con la API REST de GLPI para el sistema de gestión de inventario y matriz de obsolescencia.

## Configuración

### Variables de Entorno

Agregar las siguientes variables al archivo `.env`:

```env
# GLPI API Configuration
GLPI_BASE_URL=http://localhost/glpi/apirest.php
GLPI_USER_TOKEN=tu_user_token_aqui
GLPI_APP_TOKEN=tu_app_token_aqui
GLPI_TIMEOUT=30
GLPI_SESSION_DURATION=480
```

### Obtener Tokens de GLPI

1. **User Token**: 
   - Ir a GLPI → Mi Perfil → Configuración Remota
   - Generar un nuevo token de usuario

2. **App Token**:
   - Ir a GLPI → Configuración → General → API
   - Habilitar la API REST
   - Generar un token de aplicación

## Estructura de Archivos

```
api-app_crm/
├── app/
│   ├── Http/Controllers/GLPI/
│   │   ├── GLPIController.php          # Controlador principal de GLPI
│   │   └── ComputerController.php      # Controlador de computadoras
│   ├── Services/GLPI/
│   │   ├── GLPIService.php             # Servicio base para GLPI API
│   │   └── GLPIComputerService.php     # Servicio específico para computadoras
│   └── Models/GLPI/
│       └── GLPIComputer.php            # Modelo para estructurar datos de computadoras
├── config/
│   └── glpi.php                        # Configuración de GLPI
└── routes/
    └── api.php                         # Rutas de la API (actualizadas)
```

## Endpoints Disponibles

### Gestión de Sesión

```http
POST   /api/glpi/session/init              # Inicializar sesión
DELETE /api/glpi/session/kill              # Cerrar sesión
GET    /api/glpi/session/profiles          # Obtener perfiles
GET    /api/glpi/session/active-profile    # Obtener perfil activo
POST   /api/glpi/session/change-profile    # Cambiar perfil
GET    /api/glpi/session/entities          # Obtener entidades
POST   /api/glpi/session/change-entities   # Cambiar entidad
GET    /api/glpi/session/full              # Información completa de sesión
```

### Gestión de Computadoras

```http
GET    /api/glpi/computers                 # Listar computadoras
POST   /api/glpi/computers                 # Crear computadora
GET    /api/glpi/computers/search          # Buscar computadoras
GET    /api/glpi/computers/{id}            # Obtener computadora específica
PUT    /api/glpi/computers/{id}            # Actualizar computadora
DELETE /api/glpi/computers/{id}            # Eliminar computadora
GET    /api/glpi/computers/{id}/devices    # Obtener dispositivos
GET    /api/glpi/computers/{id}/software   # Obtener software instalado
```

## Ejemplos de Uso

### 1. Inicializar Sesión

```bash
curl -X POST "http://localhost:8000/api/glpi/session/init" \
  -H "Authorization: Bearer tu_jwt_token" \
  -H "Content-Type: application/json"
```

### 2. Obtener Computadoras

```bash
curl -X GET "http://localhost:8000/api/glpi/computers?range=0-50&sort=name&order=ASC" \
  -H "Authorization: Bearer tu_jwt_token" \
  -H "Content-Type: application/json"
```

### 3. Crear Computadora

```bash
curl -X POST "http://localhost:8000/api/glpi/computers" \
  -H "Authorization: Bearer tu_jwt_token" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "PC-001",
    "serial": "ABC123456",
    "locations_id": 1,
    "users_id": 2,
    "manufacturers_id": 3,
    "computertypes_id": 1
  }'
```

### 4. Buscar Computadoras para Matriz de Obsolescencia

```bash
curl -X GET "http://localhost:8000/api/glpi/computers/search" \
  -H "Authorization: Bearer tu_jwt_token" \
  -H "Content-Type: application/json" \
  -d '{
    "criteria": [
      {
        "field": 31,
        "searchtype": "equals",
        "value": 1
      }
    ],
    "range": "0-1000"
  }'
```

## Servicios Disponibles

### GLPIService

Servicio base que maneja:
- Autenticación automática
- Renovación de tokens
- Peticiones HTTP (GET, POST, PUT, DELETE)
- Manejo de errores
- Cache de sesiones

### GLPIComputerService

Servicio específico para computadoras que incluye:
- CRUD completo de computadoras
- Obtención de dispositivos asociados
- Software instalado
- Información financiera
- Contratos y tickets asociados
- Búsquedas especializadas para matriz de obsolescencia

## Modelo GLPIComputer

El modelo `GLPIComputer` proporciona:
- Estructuración de datos de GLPI
- Cálculo de antigüedad
- Estado de obsolescencia automático
- Validaciones
- Formateo para matriz de obsolescencia

### Métodos Principales

```php
// Crear desde datos de GLPI
$computer = GLPIComputer::fromGLPIData($glpiData);

// Obtener resumen
$summary = $computer->getSummary();

// Calcular antigüedad
$age = $computer->getAgeInYears();

// Estado de obsolescencia
$status = $computer->getObsolescenceStatus();

// Formatear para matriz
$matrixData = $computer->toObsolescenceMatrix();
```

## Configuración Avanzada

### Cache de Sesiones

Las sesiones de GLPI se almacenan en cache por 8 horas por defecto. Configurar en `config/glpi.php`:

```php
'cache' => [
    'session_duration' => env('GLPI_SESSION_DURATION', 480), // minutos
    'prefix' => 'glpi_',
],
```

### Parámetros por Defecto

Configurar parámetros por defecto para consultas:

```php
'defaults' => [
    'expand_dropdowns' => true,
    'get_hateoas' => true,
    'range' => '0-50',
    'with_devices' => true,
    'with_softwares' => true,
],
```

## Manejo de Errores

El sistema incluye manejo automático de errores:
- Renovación automática de tokens expirados
- Logging de errores
- Respuestas JSON estructuradas
- Timeouts configurables

## Seguridad

- Todas las rutas requieren autenticación JWT
- Tokens de GLPI se almacenan de forma segura
- Validación de datos de entrada
- Sanitización de respuestas

## Integración con Matriz de Obsolescencia

La integración está diseñada específicamente para alimentar la matriz de obsolescencia:

1. **Obtención de Computadoras**: Filtra solo equipos activos
2. **Cálculo de Antigüedad**: Basado en fecha de creación
3. **Clasificación Automática**: Óptimo, Funcional, Potencialmente Obsoleto, Obsoleto
4. **Datos Estructurados**: Formato específico para la matriz

## Próximos Pasos

1. Configurar las variables de entorno
2. Probar la conexión con GLPI
3. Implementar la interfaz frontend
4. Integrar con la matriz de obsolescencia existente
5. Agregar más tipos de equipos (monitores, impresoras, etc.)

## Soporte

Para problemas o dudas sobre la integración:
1. Verificar logs en `storage/logs/laravel.log`
2. Comprobar configuración de GLPI
3. Validar tokens y permisos
4. Revisar conectividad de red