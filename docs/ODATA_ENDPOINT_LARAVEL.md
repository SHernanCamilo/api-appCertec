# OData Endpoint — Guia de Implementacion en Laravel

## Objetivo

Crear un endpoint OData en Laravel que permita a Excel (Power Query) conectarse
directamente a las vistas de Fabric usando autenticacion corporativa (Microsoft Entra ID).
El usuario abre Excel, se autentica con su cuenta `@medilaser.com.co` y ve los datos
filtrados por sus permisos.

---

## Arquitectura

```
Excel (Power Query)
  |
  |-- GET /odata/{schema}/{view}?$top=1000&$skip=0
  |   Authorization: Bearer {token_azure_ad}
  v
Laravel (jade-api.medilaser.com.co)
  |
  |-- 1. Middleware: valida Bearer token Azure AD
  |-- 2. Extrae: email, name, department, groups del JWT
  |-- 3. Parsea parametros OData ($top, $skip, $filter, $select, $orderby)
  |-- 4. Registra acceso en tabla odata_access_logs
  |-- 5. Llama Graph-Fabric: POST /api/data/dynamic
  |-- 6. Formatea respuesta como OData JSON
  |-- 7. Incluye @odata.nextLink si hay mas paginas
  v
Graph-Fabric (127.0.0.1:8001)
  |
  |-- Valida permisos (esquema + sede)
  |-- Ejecuta query paginada contra Fabric
  |-- Devuelve datos
  v
Microsoft Fabric F16
```

---

## Flujo del usuario en Excel

1. Abrir Excel → Datos → Desde otras fuentes → Desde una fuente OData
2. URL: `https://jade-api.medilaser.com.co/odata/ca/VW_Portfolio_ExtractoCartera`
3. Autenticacion: "Cuenta de organizacion"
4. Se abre ventana de login Microsoft → usuario ingresa su `@medilaser.com.co`
5. Power Query carga pagina 1 (1000 filas)
6. Detecta `@odata.nextLink` → carga pagina 2, 3, ... automaticamente
7. Datos completos en Excel, el usuario puede "Actualizar" cuando quiera

---

## 1. Migration — Tabla de auditoria

```php
<?php
// database/migrations/2026_07_09_create_odata_access_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odata_access_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_email');
            $table->string('user_name')->nullable();
            $table->string('department', 100)->nullable();
            $table->json('groups')->nullable();
            $table->string('schema_name', 50);
            $table->string('view_name', 200);
            $table->text('filter_applied')->nullable();
            $table->string('select_columns')->nullable();
            $table->integer('top')->default(1000);
            $table->integer('skip')->default(0);
            $table->integer('rows_returned')->default(0);
            $table->integer('elapsed_ms')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('source', 20)->default('odata'); // odata, excel, api
            $table->timestamp('accessed_at')->useCurrent();

            $table->index('user_email');
            $table->index(['schema_name', 'view_name']);
            $table->index('accessed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odata_access_logs');
    }
};
```

---

## 2. Middleware — Validar Bearer Token Azure AD

```php
<?php
// app/Http/Middleware/ValidateAzureAdToken.php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ValidateAzureAdToken
{
    /**
     * Valida el Bearer token de Azure AD que Excel envia automaticamente.
     * Extrae claims y los pone disponibles en el request.
     */
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json([
                'error' => 'Token requerido. Use autenticacion "Cuenta de organizacion" en Excel.',
            ], 401);
        }

        try {
            $claims = $this->validateToken($token);
            
            // Poner datos del usuario en el request
            $request->merge([
                'azure_user' => [
                    'email'      => $claims['email'],
                    'name'       => $claims['name'],
                    'department' => $claims['department'],
                    'groups'     => $claims['groups'],
                    'user_id'    => $claims['oid'],
                ],
            ]);

        } catch (\Exception $e) {
            Log::warning('OData: Token Azure AD invalido', [
                'error' => $e->getMessage(),
                'ip'    => $request->ip(),
            ]);
            return response()->json([
                'error' => 'Token invalido o expirado. Vuelva a autenticarse.',
            ], 401);
        }

        return $next($request);
    }

    private function validateToken(string $token): array
    {
        $tenantId = config('services.azure.tenant_id');
        $clientId = config('services.azure.client_id');

        // Obtener claves publicas de Azure AD (cacheadas 1 hora)
        $jwksUri = "https://login.microsoftonline.com/{$tenantId}/discovery/v2.0/keys";
        $jwks = Cache::remember('azure_ad_jwks', 3600, function () use ($jwksUri) {
            $response = file_get_contents($jwksUri);
            return json_decode($response, true);
        });

        $keys = JWK::parseKeySet($jwks);

        // Decodificar y validar el JWT
        $decoded = JWT::decode($token, $keys);
        $payload = (array) $decoded;

        // Validar audience (debe ser nuestra app)
        $aud = $payload['aud'] ?? '';
        if ($aud !== $clientId && $aud !== "api://{$clientId}") {
            throw new \RuntimeException("Audience invalido: {$aud}");
        }

        // Extraer claims
        $email = $payload['preferred_username']
              ?? $payload['upn']
              ?? $payload['email']
              ?? $payload['unique_name']
              ?? '';

        // Grupos: pueden ser display names o GUIDs
        $groups = $payload['groups'] ?? [];

        return [
            'oid'        => $payload['oid'] ?? 'unknown',
            'email'      => $email,
            'name'       => $payload['name'] ?? $email,
            'department' => $payload['department'] ?? null,
            'groups'     => $groups,
        ];
    }
}
```

### Registrar el middleware (bootstrap/app.php o Kernel.php):

```php
// En route middleware aliases
'auth.azure' => \App\Http\Middleware\ValidateAzureAdToken::class,
```

---

## 3. Ruta

```php
// routes/api.php
Route::get('/odata/{schema}/{view}', [ODataController::class, 'query'])
    ->middleware(['auth.azure'])
    ->where('schema', '[a-z]{2,4}')
    ->where('view', 'VW_[A-Za-z0-9_]+');
```

---

## 4. Controlador OData

```php
<?php
// app/Http/Controllers/ODataController.php

namespace App\Http\Controllers;

use App\Models\OdataAccessLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ODataController extends Controller
{
    private string $graphFabricUrl;
    private string $graphFabricToken;

    public function __construct()
    {
        $this->graphFabricUrl = config('services.graph_fabric.url');
        $this->graphFabricToken = config('services.graph_fabric.token');
    }

    /**
     * GET /odata/{schema}/{view}
     * 
     * Parametros OData soportados:
     *   $top     → limit (default 1000, max 5000)
     *   $skip    → offset
     *   $filter  → filtros WHERE
     *   $select  → columnas especificas
     *   $orderby → sort_col + sort_dir
     */
    public function query(Request $request, string $schema, string $view): JsonResponse
    {
        $azureUser = $request->input('azure_user');
        $startTime = microtime(true);

        // 1. Parsear parametros OData
        $top     = min((int) $request->query('$top', 1000), 5000);
        $skip    = max((int) $request->query('$skip', 0), 0);
        $filter  = $request->query('$filter', '');
        $select  = $request->query('$select', '');
        $orderby = $request->query('$orderby', '');

        // 2. Traducir $filter a formato Graph-Fabric
        $filters = $this->parseODataFilter($filter);

        // 3. Traducir $select a columnas
        $columns = $select ? array_map('trim', explode(',', $select)) : [];

        // 4. Traducir $orderby
        [$sortCol, $sortDir] = $this->parseOrderBy($orderby);

        // 5. Llamar a Graph-Fabric
        $response = Http::timeout(125)->post("{$this->graphFabricUrl}/api/data/dynamic", [
            'token'       => $this->graphFabricToken,
            'user_email'  => $azureUser['email'],
            'user_name'   => $azureUser['name'],
            'department'  => $azureUser['department'],
            'groups'      => $azureUser['groups'],
            'schema_name' => $schema,
            'view'        => $view,
            'filters'     => $filters,
            'columns'     => $columns,
            'limit'       => $top,
            'offset'      => $skip,
            'sort_col'    => $sortCol,
            'sort_dir'    => $sortDir,
            'skip_count'  => true,
        ]);

        // 6. Manejar errores
        if ($response->status() === 422) {
            $error = $response->json();
            return response()->json([
                'error' => [
                    'code'    => 'FiltersRequired',
                    'message' => $error['message'] ?? 'Se requieren filtros para esta vista.',
                    'details' => [
                        'suggestions' => $error['suggestions'] ?? [],
                        'columns'     => $error['columns'] ?? [],
                    ],
                ],
            ], 422);
        }

        if ($response->failed()) {
            Log::error('OData: Graph-Fabric error', [
                'status' => $response->status(),
                'schema' => $schema,
                'view'   => $view,
                'user'   => $azureUser['email'],
            ]);
            return response()->json([
                'error' => [
                    'code'    => 'DataSourceError',
                    'message' => 'Error consultando datos. Intente de nuevo.',
                ],
            ], 502);
        }

        $data = $response->json();
        $items = $data['items'] ?? [];
        $pageInfo = $data['page_info'] ?? [];
        $elapsedMs = round((microtime(true) - $startTime) * 1000);

        // 7. Registrar acceso
        OdataAccessLog::create([
            'user_email'    => $azureUser['email'],
            'user_name'     => $azureUser['name'],
            'department'    => $azureUser['department'],
            'groups'        => $azureUser['groups'],
            'schema_name'   => $schema,
            'view_name'     => $view,
            'filter_applied' => $filter ?: null,
            'select_columns' => $select ?: null,
            'top'           => $top,
            'skip'          => $skip,
            'rows_returned' => count($items),
            'elapsed_ms'    => $elapsedMs,
            'ip_address'    => $request->ip(),
            'user_agent'    => $request->userAgent(),
            'source'        => 'odata',
        ]);

        // 8. Construir respuesta OData
        $odataResponse = [
            '@odata.context' => url("/odata/{$schema}/{$view}/\$metadata"),
            'value'          => $items,
        ];

        // nextLink: si hay mas paginas
        $hasNext = $pageInfo['has_next'] ?? (count($items) === $top);
        if ($hasNext) {
            $nextSkip = $skip + $top;
            $nextParams = array_filter([
                '$top'     => $top,
                '$skip'    => $nextSkip,
                '$filter'  => $filter ?: null,
                '$select'  => $select ?: null,
                '$orderby' => $orderby ?: null,
            ]);
            $odataResponse['@odata.nextLink'] = url("/odata/{$schema}/{$view}") 
                . '?' . http_build_query($nextParams);
        }

        return response()->json($odataResponse);
    }

    /**
     * Parsear $filter OData a formato Graph-Fabric.
     * 
     * Formatos soportados:
     *   Nit eq '900156264'           → {"Nit": "900156264"}
     *   Fecha ge '2026-01-01'        → {"Fecha": ">=2026-01-01"} (pendiente)
     *   contains(Nombre, 'CLINICA')  → {"Nombre": "%CLINICA%"}
     *   Nit eq '900' and Sede eq 'N' → {"Nit": "900", "Sede": "N"}
     */
    private function parseODataFilter(string $filter): array
    {
        if (empty($filter)) {
            return [];
        }

        $filters = [];

        // Separar por ' and '
        $parts = preg_split('/\s+and\s+/i', $filter);

        foreach ($parts as $part) {
            $part = trim($part);

            // Formato: campo eq 'valor'
            if (preg_match("/^(\w+)\s+eq\s+'([^']+)'$/i", $part, $m)) {
                $filters[$m[1]] = $m[2];
                continue;
            }

            // Formato: campo eq valor (numerico)
            if (preg_match("/^(\w+)\s+eq\s+(\d+)$/i", $part, $m)) {
                $filters[$m[1]] = (int) $m[2];
                continue;
            }

            // Formato: contains(campo, 'valor') → LIKE
            if (preg_match("/^contains\((\w+),\s*'([^']+)'\)$/i", $part, $m)) {
                $filters[$m[1]] = "%{$m[2]}%";
                continue;
            }

            // Formato: startswith(campo, 'valor')
            if (preg_match("/^startswith\((\w+),\s*'([^']+)'\)$/i", $part, $m)) {
                $filters[$m[1]] = "{$m[2]}%";
                continue;
            }
        }

        return $filters;
    }

    /**
     * Parsear $orderby OData.
     * Formato: "FechaFactura desc" o "Nit asc"
     */
    private function parseOrderBy(string $orderby): array
    {
        if (empty($orderby)) {
            return ['', 'asc'];
        }

        $parts = explode(' ', trim($orderby));
        $col = $parts[0] ?? '';
        $dir = strtolower($parts[1] ?? 'asc');

        if (!in_array($dir, ['asc', 'desc'])) {
            $dir = 'asc';
        }

        return [$col, $dir];
    }
}
```

---

## 5. Modelo de auditoria

```php
<?php
// app/Models/OdataAccessLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OdataAccessLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'user_email', 'user_name', 'department',
        'groups', 'schema_name', 'view_name', 'filter_applied',
        'select_columns', 'top', 'skip', 'rows_returned',
        'elapsed_ms', 'ip_address', 'user_agent', 'source',
    ];

    protected $casts = [
        'groups'      => 'array',
        'accessed_at' => 'datetime',
    ];
}
```

---

## 6. Configuracion (.env Laravel)

```env
# Azure AD - para validar tokens de usuarios
AZURE_TENANT_ID=0ca8ab9f-b553-4b4b-a787-f03c2ccd756d
AZURE_CLIENT_ID=76dd0900-4042-49b9-9e03-79fa1bf70d68

# Graph-Fabric
GRAPH_FABRIC_URL=http://127.0.0.1:8001
GRAPH_FABRIC_TOKEN=UqR2ugPODAVt4cZgiMGMFDx-Z8EJaAIKM2keqowHX2a3ijaIALQCh4dQ-CPfYG4P
```

```php
// config/services.php
'azure' => [
    'tenant_id' => env('AZURE_TENANT_ID'),
    'client_id' => env('AZURE_CLIENT_ID'),
],
'graph_fabric' => [
    'url'   => env('GRAPH_FABRIC_URL', 'http://127.0.0.1:8001'),
    'token' => env('GRAPH_FABRIC_TOKEN'),
],
```

---

## 7. Dependencias Laravel

```bash
composer require firebase/php-jwt
# Ya tienes: guzzlehttp/guzzle (para Http::)
```

---

## 8. Registro de App en Azure AD

Para que Excel pueda autenticarse, la app en Azure AD debe tener:

### En Azure Portal → App Registrations → tu app:

1. **Authentication:**
   - Platform: Web
   - Redirect URI: `https://jade-api.medilaser.com.co/auth/callback`
   - Tokens: Access tokens + ID tokens habilitados

2. **Expose an API:**
   - Application ID URI: `api://76dd0900-4042-49b9-9e03-79fa1bf70d68`
   - Scope: `api://76dd0900-4042-49b9-9e03-79fa1bf70d68/Data.Read`
     - Admin consent: Yes
     - Display name: "Leer datos de vistas"

3. **API Permissions:**
   - Microsoft Graph: `User.Read`, `GroupMember.Read.All`
   - Tu app: `Data.Read`

4. **Token Configuration (claims opcionales):**
   - Agregar claims al Access Token:
     - `email`
     - `groups`
     - `department` (custom claim o directory extension)

---

## 9. Configuracion de Excel

### Manual (una vez por usuario):

1. Abrir Excel → Datos → Desde otras fuentes → **Desde una fuente OData**
2. URL: `https://jade-api.medilaser.com.co/odata/dc/VW_Censo`
3. Autenticacion: **Cuenta de organizacion**
4. Login con correo `@medilaser.com.co`
5. Aceptar permisos → Power Query carga los datos

### Con archivo .odc (distribuir a usuarios):

```xml
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:odc="urn:schemas-microsoft-com:office:odc">
<head>
<meta http-equiv="Content-Type" content="text/x-ms-odc; charset=utf-8">
<meta name="ProgId" content="ODC.Database">
<meta name="SourceType" content="OLEDB">
<title>Medilaser - Censo Hospitalario</title>
<odc:OfficeDataConnection>
 <odc:Connection odc:Type="OLEDB">
  <odc:ConnectionString>
    Provider=Microsoft.Mashup.OleDb.1;
    Data Source=$Workbook$;
    Location="https://jade-api.medilaser.com.co/odata/dc/VW_Censo";
    Extended Properties="UEL=https://jade-api.medilaser.com.co/odata/dc/VW_Censo"
  </odc:ConnectionString>
  <odc:CommandType>SQL</odc:CommandType>
  <odc:CommandText>SELECT * FROM [Table]</odc:CommandText>
 </odc:Connection>
</odc:OfficeDataConnection>
</head>
</html>
```

Guardar como `Censo_Hospitalario.odc` y distribuir por email o SharePoint.

---

## 10. Ejemplos de uso desde Excel Power Query

### Consulta basica (todas las columnas, paginado):
```
URL: https://jade-api.medilaser.com.co/odata/dc/VW_Censo
```

### Con filtro por NIT:
```
URL: https://jade-api.medilaser.com.co/odata/ca/VW_Portfolio_ExtractoCartera?$filter=Nit eq '900156264'
```

### Con filtro + ordenamiento + columnas especificas:
```
URL: https://jade-api.medilaser.com.co/odata/df/VW_Billing_IngresosAbiertos?$select=Nit,RazonSocial,Fecha,Valor&$orderby=Fecha desc&$filter=Sucursal eq 'Neiva'
```

### Con filtro LIKE (contains):
```
URL: https://jade-api.medilaser.com.co/odata/dc/VW_AD_Paciente?$filter=contains(Nombre, 'MARIA')
```

### Respuesta OData que recibe Excel:

```json
{
  "@odata.context": "https://jade-api.medilaser.com.co/odata/dc/VW_Censo/$metadata",
  "value": [
    {"Paciente": "Juan Perez", "Cama": "301A", "Servicio": "UCI", "Dias": 5},
    {"Paciente": "Maria Lopez", "Cama": "204B", "Servicio": "Hosp", "Dias": 3},
    ...
  ],
  "@odata.nextLink": "https://jade-api.medilaser.com.co/odata/dc/VW_Censo?$top=1000&$skip=1000"
}
```

Power Query detecta `@odata.nextLink` y automaticamente pide la siguiente pagina.

---

## 11. Respuesta cuando se requieren filtros (vistas pesadas)

Si la vista tiene >1M filas y no se envian filtros, Graph-Fabric devuelve 422.
Laravel lo traduce al formato de error OData:

```json
{
  "error": {
    "code": "FiltersRequired",
    "message": "La vista 'ca.VW_Portfolio_ExtractoCartera' contiene mas de 1,000,000 registros. Aplica al menos un filtro.",
    "details": {
      "suggestions": ["Nit", "FechaFactura", "Fecha", "TipoDocumento"],
      "columns": [
        {"name": "Nit", "type": "varchar"},
        {"name": "FechaFactura", "type": "datetime2"}
      ]
    }
  }
}
```

En Excel, Power Query mostrara este error al usuario indicando que debe agregar filtros a la URL.

---

## 12. Seguridad y control

### Que controla Graph-Fabric (ya implementado):
- Esquemas permitidos por grupo Azure AD (GG-BD-CO → co)
- Filtro por sede (department → sufijo _Nva, _Fla, etc.)
- Vistas pesadas requieren filtros
- skip_count automatico

### Que controla Laravel (nuevo):
- Validacion del token Azure AD (firma, audience, expiracion)
- Registro de cada acceso en `odata_access_logs`
- Rate limit por usuario (opcional)
- Bloqueo de usuarios/IPs si es necesario

### Que ve el administrador:
```sql
-- Ver quien consulta que
SELECT user_email, schema_name, view_name, COUNT(*) as accesos, 
       MAX(accessed_at) as ultimo_acceso
FROM odata_access_logs
WHERE accessed_at > NOW() - INTERVAL 7 DAY
GROUP BY user_email, schema_name, view_name
ORDER BY accesos DESC;

-- Ver vistas mas consultadas
SELECT schema_name, view_name, COUNT(*) as total,
       AVG(elapsed_ms) as avg_ms, SUM(rows_returned) as total_rows
FROM odata_access_logs
WHERE accessed_at > NOW() - INTERVAL 1 DAY
GROUP BY schema_name, view_name
ORDER BY total DESC;
```

---

## 13. Checklist de implementacion

```
[ ] Migration: crear tabla odata_access_logs
[ ] Middleware: ValidateAzureAdToken
[ ] Controlador: ODataController con parseo de $top, $skip, $filter
[ ] Modelo: OdataAccessLog
[ ] Ruta: GET /odata/{schema}/{view}
[ ] Config: AZURE_TENANT_ID, AZURE_CLIENT_ID en .env
[ ] Dependencia: firebase/php-jwt
[ ] Azure AD: exponer scope Data.Read, habilitar tokens
[ ] Test: llamar desde Postman con Bearer token real
[ ] Test: conectar desde Excel → Desde fuente OData
[ ] Crear archivos .odc para vistas principales
[ ] Documentar para usuarios finales (guia con capturas)
```

---

## 14. Diferencia con el flujo actual (via Angular)

| Aspecto | Via Angular (actual) | Via Excel OData (nuevo) |
|---------|---------------------|------------------------|
| Autenticacion | Laravel session + Sanctum | Bearer token Azure AD directo |
| Quien pide datos | Angular → Laravel → Graph | Excel → Laravel → Graph |
| Paginacion | Manual (botones next/prev) | Automatica (Power Query) |
| Filtros | UI del frontend | $filter en la URL |
| Exportar | Boton → job background | Excel YA tiene los datos |
| Actualizar | Boton "Recargar" | "Actualizar" en Excel |
| Offline | No | Si (datos quedan en el archivo) |

---

*Generado: 2026-07-09 | Graph-Fabric no requiere cambios para esto.*
