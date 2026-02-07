# Consentimiento de Administrador para Multi-Tenant Azure AD

## ¿Por qué aparece este mensaje?

Cuando tu aplicación está configurada como **multi-tenant** (`MICROSOFT_TENANT_ID=common`), Azure AD requiere que un **administrador del tenant** otorgue consentimiento para que la aplicación pueda acceder a los datos básicos del usuario (nombre, email, foto de perfil).

Este es un mecanismo de seguridad de Microsoft para proteger los datos de la organización.

## Solución 1: Otorgar Consentimiento de Administrador (Recomendado)

### Paso 1: Generar URL de consentimiento

La URL de consentimiento de administrador tiene este formato:

```
https://login.microsoftonline.com/common/adminconsent?client_id=TU_CLIENT_ID&redirect_uri=TU_REDIRECT_URI
```

Para tu aplicación:

```
https://login.microsoftonline.com/common/adminconsent?client_id=1192e412-ab51-480c-b534-a47fe9823765&redirect_uri=http://localhost:4200/auth/microsoft/callback
```

### Paso 2: Otorgar consentimiento para cada tenant

**Para el Tenant 1 (Miocardio):**

1. Abre la URL de consentimiento en un navegador
2. Inicia sesión con una cuenta de **Administrador Global** del tenant Miocardio
3. Revisa los permisos solicitados:
   - Leer perfil básico del usuario
   - Leer email del usuario
4. Haz clic en **"Aceptar"** o **"Accept"**

**Para el Tenant 2:**

1. Repite el mismo proceso
2. Inicia sesión con una cuenta de **Administrador Global** del segundo tenant
3. Acepta los permisos

### Paso 3: Verificar el consentimiento

Después de otorgar el consentimiento, verifica en Azure Portal:

1. Ve a **Azure Active Directory** → **Enterprise Applications**
2. Busca tu aplicación "Synnexia Soft" o por el Client ID
3. Ve a **Permissions**
4. Deberías ver los permisos con estado "Granted for [Tenant Name]"

## Solución 2: Configurar permisos que no requieren administrador

Si prefieres que los usuarios puedan dar consentimiento por sí mismos (sin administrador), puedes:

### Opción A: Cambiar configuración en Azure Portal

1. Ve a **Azure Active Directory** → **Enterprise Applications** → Tu App
2. Ve a **Properties**
3. Cambia **"User consent"** a **"Allow user consent for apps"**

### Opción B: Solicitar solo permisos delegados básicos

Modifica el controlador para solicitar solo permisos básicos que no requieren administrador.

## Solución 3: Usar tenant específico temporalmente

Si solo necesitas probar con un tenant mientras configuras el otro:

1. Cambia temporalmente en `.env`:
   ```
   MICROSOFT_TENANT_ID=c51cdb8c-7df6-40f1-889c-abece1950a33
   ```

2. Prueba el login con el Tenant 1

3. Cuando estés listo para el Tenant 2:
   ```
   MICROSOFT_TENANT_ID=TENANT_2_ID_AQUI
   ```

4. Finalmente, cuando ambos tengan consentimiento, vuelve a:
   ```
   MICROSOFT_TENANT_ID=common
   ```

## Permisos que solicita la aplicación

Por defecto, Laravel Socialite con Microsoft solicita:

- **User.Read** (Delegado): Leer perfil básico del usuario
  - Nombre
  - Email
  - Foto de perfil
  - ID de usuario

Estos son permisos básicos y seguros que solo permiten leer información pública del perfil.

## Verificar qué permisos tiene tu aplicación

### En Azure Portal:

1. Ve a **App Registrations** → Tu App
2. Ve a **API Permissions**
3. Deberías ver algo como:
   ```
   Microsoft Graph
   ├── User.Read (Delegated) ✓
   └── openid (Delegated) ✓
   ```

### Agregar permisos si es necesario:

Si necesitas agregar permisos explícitamente:

1. En **API Permissions**, haz clic en **"Add a permission"**
2. Selecciona **Microsoft Graph**
3. Selecciona **Delegated permissions**
4. Busca y agrega:
   - `User.Read`
   - `openid`
   - `profile`
   - `email`
5. Haz clic en **"Grant admin consent for [Tenant]"**

## Comandos útiles para administradores

### PowerShell - Otorgar consentimiento programáticamente:

```powershell
# Conectar a Azure AD
Connect-AzureAD

# Otorgar consentimiento para la aplicación
$appId = "1192e412-ab51-480c-b534-a47fe9823765"
$sp = Get-AzureADServicePrincipal -Filter "appId eq '$appId'"

# Ver permisos actuales
Get-AzureADServicePrincipalOAuth2PermissionGrant -ObjectId $sp.ObjectId
```

### Azure CLI:

```bash
# Login
az login

# Otorgar consentimiento
az ad app permission admin-consent --id 1192e412-ab51-480c-b534-a47fe9823765
```

## Troubleshooting

### Error: "AADSTS65001: The user or administrator has not consented"

**Solución**: Otorga el consentimiento de administrador usando la URL de consentimiento.

### Error: "AADSTS50020: User account from identity provider does not exist in tenant"

**Solución**: 
- Verifica que el usuario esté usando la cuenta correcta del tenant
- Asegúrate de que `MICROSOFT_TENANT_ID=common`

### Error: "Need admin approval"

**Solución**: 
1. Usa la URL de consentimiento de administrador
2. O configura la aplicación para permitir consentimiento de usuario

### Los usuarios siguen viendo el mensaje después del consentimiento

**Solución**:
1. Limpia la caché del navegador
2. Cierra todas las sesiones de Microsoft
3. Intenta de nuevo

## Mejores prácticas

1. **Otorga consentimiento de administrador una vez por tenant**
   - Es más seguro y conveniente
   - Los usuarios no verán el mensaje de consentimiento

2. **Documenta qué permisos solicitas y por qué**
   - Transparencia con los administradores
   - Facilita la aprobación

3. **Solicita solo los permisos mínimos necesarios**
   - User.Read es suficiente para login básico
   - No solicites permisos adicionales sin necesidad

4. **Mantén un registro de tenants autorizados**
   - Usa la tabla `allowed_domains`
   - Documenta qué empresas/tenants tienen acceso

## Contacto con administradores

Si no eres administrador del tenant, contacta a:

**Para Tenant 1 (Miocardio):**
- Administrador Global del tenant
- Solicita que otorgue consentimiento usando la URL proporcionada

**Para Tenant 2:**
- Administrador Global del segundo tenant
- Proporciona la URL de consentimiento y esta documentación

## Resumen

✅ **Solución rápida**: Usa la URL de consentimiento de administrador
✅ **Una vez por tenant**: Solo necesitas hacerlo una vez
✅ **Seguro**: Es el método recomendado por Microsoft
✅ **Todos los usuarios**: Después del consentimiento, todos los usuarios del tenant pueden iniciar sesión sin problemas
