# Comando de Validación de Tiempo de Respuesta de APIs

## Descripción
Este comando permite validar el tiempo de respuesta de todas las APIs críticas del sistema CRM, proporcionando métricas detalladas de rendimiento.

## Instalación
El comando ya está creado en: `app/Console/Commands/ValidarTiempoRespuestaApi.php`

## Uso

### 1. Probar todos los endpoints
```bash
php artisan api:test-response-time --all
```

### 2. Probar un endpoint específico
```bash
php artisan api:test-response-time --endpoint=users
php artisan api:test-response-time --endpoint=empresas
php artisan api:test-response-time --endpoint=roles
```

### 3. Configurar timeout personalizado
```bash
php artisan api:test-response-time --all --timeout=60
```

## Endpoints Monitoreados

| Endpoint | Descripción | Requiere Auth |
|----------|-------------|---------------|
| `POST /api/auth/login` | Login de usuario | No |
| `GET /api/users` | Lista de usuarios | Sí |
| `GET /api/users/tenant/obtener` | Usuarios del tenant Microsoft | Sí |
| `GET /api/empresas` | Lista de empresas | Sí |
| `GET /api/roles` | Lista de roles | Sí |
| `GET /api/permisos` | Lista de permisos | Sí |
| `GET /api/auth/sidebar-modules` | Menú sidebar | Sí |
| `GET /api/contexto` | Contexto del usuario | Sí |
| `GET /api/sucursales` | Lista de sucursales | Sí |
| `GET /api/sedes` | Lista de sedes | Sí |

## Interpretación de Resultados

### Códigos de Estado
- ✅ **OK**: Respuesta exitosa (HTTP 200-299)
- ❌ **ERROR**: Respuesta fallida (HTTP 400+) o excepción

### Métricas de Tiempo
- **< 500ms**: Excelente rendimiento
- **500ms - 1s**: Buen rendimiento
- **1s - 2s**: Rendimiento aceptable
- **> 2s**: Rendimiento lento (se marca como advertencia)

### Información Mostrada
- **Tiempo de respuesta** en milisegundos
- **Código HTTP** de la respuesta
- **Tamaño de la respuesta** (B, KB, MB)
- **Errores** si los hay

## Ejemplo de Salida

```
🚀 Iniciando validación de tiempo de respuesta de APIs
================================================
🔑 Obteniendo token de autenticación...
✅ Token JWT obtenido para usuario: admin@example.com
🔍 Probando: Lista de usuarios (GET /api/users)
   ✅ OK | 219.02ms | HTTP 200 | 2.72KB

📊 RESUMEN DE PRUEBAS
====================
Total de endpoints probados: 10
Exitosos: 9
Fallidos: 1
Tiempo promedio de respuesta: 245.39ms
Tiempo máximo de respuesta: 349.84ms
Tiempo mínimo de respuesta: 170.47ms

⚠️  ENDPOINTS LENTOS (>2s):
   - Usuarios del tenant Microsoft: 8057.81ms

❌ ENDPOINTS FALLIDOS:
   - Login de usuario: Error de autenticación
```

## Casos de Uso

### 1. Monitoreo de Rendimiento
Ejecutar regularmente para detectar degradación del rendimiento:
```bash
php artisan api:test-response-time --all
```

### 2. Debugging de Endpoints Específicos
Cuando un endpoint específico tiene problemas:
```bash
php artisan api:test-response-time --endpoint=tenant
```

### 3. Validación Post-Deployment
Después de desplegar cambios, verificar que todo funciona:
```bash
php artisan api:test-response-time --all --timeout=30
```

### 4. Monitoreo Automatizado
Integrar en scripts de CI/CD o cron jobs:
```bash
# En crontab para ejecutar cada hora
0 * * * * cd /path/to/project && php artisan api:test-response-time --all >> /var/log/api-performance.log
```

## Configuración

### Agregar Nuevos Endpoints
Editar el array `$criticalEndpoints` en el archivo del comando:

```php
'GET /api/nuevo-endpoint' => [
    'method' => 'GET',
    'data' => null,
    'requires_auth' => true,
    'description' => 'Descripción del endpoint'
],
```

### Modificar Timeout por Defecto
Cambiar el valor por defecto en la signature del comando:
```php
protected $signature = 'api:test-response-time {--endpoint=} {--all} {--timeout=60}';
```

## Requisitos

1. **Servidor corriendo**: El comando necesita que el servidor Laravel esté ejecutándose
2. **Base de datos**: Debe haber al menos un usuario en la base de datos para generar el token JWT
3. **JWT configurado**: El sistema debe tener JWT Auth configurado correctamente

## Troubleshooting

### Error: "No hay usuarios en la base de datos"
```bash
php artisan db:seed --class=UserSeeder
```

### Error: "cURL error 6: Could not resolve host"
Verificar que `APP_URL` en `.env` esté configurado correctamente:
```
APP_URL=http://127.0.0.1:8000
```

### Error: "HTTP 401"
Verificar que JWT Auth esté configurado y el usuario tenga permisos.

### Servidor no responde
Asegurarse de que el servidor esté corriendo:
```bash
php artisan serve --port=8000
```