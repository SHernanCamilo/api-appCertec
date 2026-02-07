# Configuración Multi-Tenant para Azure AD

## Estado Actual
✅ La aplicación ya está configurada para multi-tenant con `MICROSOFT_TENANT_ID=common`

## Pasos para permitir login desde múltiples tenants

### 1. Verificar configuración en Azure Portal

Ve a tu App Registration en Azure Portal:
- **Nombre**: Tu aplicación
- **Application (client) ID**: `1192e412-ab51-480c-b534-a47fe9823765`

Verifica:
1. **Authentication** → **Supported account types**:
   - Debe estar en: "Accounts in any organizational directory (Any Azure AD directory - Multitenant)"
   - Si está en "Single tenant", cámbialo a "Multitenant"

2. **Authentication** → **Redirect URIs**:
   - Debe incluir: `http://localhost:4200/auth/microsoft/callback`

### 2. Registrar dominios permitidos en la base de datos

Cada tenant que quieras permitir debe estar registrado en la tabla `allowed_domains`.

#### Opción A: Usando la API (Recomendado)

```bash
# Registrar Tenant 1
POST http://127.0.0.1:8000/api/allowed-domains
Content-Type: application/json
Authorization: Bearer {tu_token}

{
  "domain": "@miocardio.onmicrosoft.com",
  "tenant_id": "c51cdb8c-7df6-40f1-889c-abece1950a33",
  "tenant_name": "Miocardio",
  "id_empresa": 1,
  "activo": 1,
  "descripcion": "Tenant principal"
}

# Registrar Tenant 2
POST http://127.0.0.1:8000/api/allowed-domains
Content-Type: application/json
Authorization: Bearer {tu_token}

{
  "domain": "@segundotenant.onmicrosoft.com",
  "tenant_id": "TENANT_2_ID_AQUI",
  "tenant_name": "Segundo Tenant",
  "id_empresa": 2,
  "activo": 1,
  "descripcion": "Segundo tenant"
}
```

#### Opción B: Directamente en la base de datos

```sql
-- Ver dominios actuales
SELECT * FROM allowed_domains;

-- Registrar nuevo tenant
INSERT INTO allowed_domains (domain, tenant_id, tenant_name, id_empresa, activo, descripcion, created_at, updated_at)
VALUES 
('@segundotenant.onmicrosoft.com', 'TENANT_2_ID_AQUI', 'Segundo Tenant', 2, 1, 'Segundo tenant', NOW(), NOW());
```

### 3. Obtener el Tenant ID del segundo tenant

Para obtener el Tenant ID de tu segundo tenant:

1. **Opción 1 - Azure Portal**:
   - Ve a Azure Active Directory
   - En "Overview", copia el "Tenant ID"

2. **Opción 2 - URL de login**:
   - El Tenant ID está en la URL cuando inicias sesión en Azure Portal
   - `https://portal.azure.com/{TENANT_ID}`

3. **Opción 3 - PowerShell**:
   ```powershell
   Connect-AzureAD
   Get-AzureADTenantDetail | Select-Object ObjectId
   ```

### 4. Verificar configuración

Usa el endpoint de verificación:

```bash
GET http://127.0.0.1:8000/api/auth/microsoft/check-config
```

Debería mostrar:
```json
{
  "configured": true,
  "config": {
    "tenant": "common",
    "multi_tenant": true
  },
  "allowed_domains": [
    {
      "domain": "@miocardio.onmicrosoft.com",
      "tenant_name": "Miocardio"
    },
    {
      "domain": "@segundotenant.onmicrosoft.com",
      "tenant_name": "Segundo Tenant"
    }
  ],
  "total_domains": 2
}
```

### 5. Probar login desde ambos tenants

1. **Usuario del Tenant 1**:
   - Email: `usuario@miocardio.onmicrosoft.com`
   - Debe poder iniciar sesión

2. **Usuario del Tenant 2**:
   - Email: `usuario@segundotenant.onmicrosoft.com`
   - Debe poder iniciar sesión

## Notas Importantes

### Dominios personalizados
Si tus tenants usan dominios personalizados (ej: `@empresa1.com`, `@empresa2.com`), registra esos dominios en lugar de los `.onmicrosoft.com`:

```sql
INSERT INTO allowed_domains (domain, tenant_id, tenant_name, id_empresa, activo, descripcion, created_at, updated_at)
VALUES 
('@empresa1.com', 'TENANT_1_ID', 'Empresa 1', 1, 1, 'Dominio personalizado empresa 1', NOW(), NOW()),
('@empresa2.com', 'TENANT_2_ID', 'Empresa 2', 2, 1, 'Dominio personalizado empresa 2', NOW(), NOW());
```

### Usuarios deben estar pre-registrados
La aplicación NO crea usuarios automáticamente. Los usuarios deben ser creados por un administrador antes de poder iniciar sesión con Microsoft.

### Flujo de autenticación
1. Usuario hace clic en "Iniciar sesión con Microsoft"
2. Es redirigido a Microsoft (puede elegir su cuenta)
3. Microsoft valida las credenciales
4. La aplicación verifica:
   - ✅ ¿El dominio está en `allowed_domains`?
   - ✅ ¿El usuario existe en la base de datos?
   - ✅ ¿El usuario está activo?
5. Si todo es correcto, genera el token JWT

## Troubleshooting

### Error: "Dominio no autorizado"
- Verifica que el dominio esté registrado en `allowed_domains`
- Verifica que `activo = 1`

### Error: "Usuario no autorizado"
- El usuario debe ser creado primero por un administrador
- Ve a "Usuarios" → "Crear Usuario" y registra al usuario con su email de Microsoft

### Error: "Cuenta inactiva"
- El usuario existe pero está desactivado
- Ve a "Usuarios" → Editar usuario → Activar cuenta

## Comandos útiles

```bash
# Ver todos los dominios permitidos
SELECT * FROM allowed_domains WHERE activo = 1;

# Ver usuarios con autenticación Microsoft
SELECT id, name, email, tenant_id, auth_type, estado FROM users WHERE auth_type = 'microsoft';

# Activar un dominio
UPDATE allowed_domains SET activo = 1 WHERE domain = '@ejemplo.com';

# Desactivar un dominio
UPDATE allowed_domains SET activo = 0 WHERE domain = '@ejemplo.com';
```
