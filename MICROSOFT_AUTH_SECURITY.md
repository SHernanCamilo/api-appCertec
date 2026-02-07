# Seguridad de Autenticación con Microsoft

## 🔒 Control de Acceso Implementado

### Comportamiento Actual:
- **Solo usuarios pre-existentes** en la BD local pueden acceder
- **NO se crean usuarios automáticamente** desde Microsoft
- Los usuarios deben ser creados manualmente por un administrador

### Flujo de Autenticación:

1. **Usuario inicia sesión con Microsoft** ✅
2. **Sistema verifica dominio permitido** (tabla `allowed_domains`) ✅
3. **Sistema busca usuario por `microsoft_id` o `email`**
4. **Si NO existe en BD local**: 
   - ❌ **ACCESO DENEGADO**
   - Error: "Tu cuenta debe ser creada por un administrador"
   - Se registra el intento en logs
5. **Si existe**: 
   - ✅ Actualiza información de Microsoft
   - ✅ Verifica que esté activo
   - ✅ Carga permisos y roles
   - ✅ Permite acceso

### Logs de Seguridad:

Los intentos de acceso no autorizados se registran con:
- Email del usuario
- Microsoft ID
- Nombre completo
- IP del cliente
- User Agent

### Para Permitir Acceso a un Usuario de Microsoft:

1. **Crear usuario manualmente** en el sistema:
   - Ir a "Gestión de Usuarios" → "Nuevo Usuario"
   - Usar el **mismo email** que tiene en Microsoft
   - Asignar roles y permisos necesarios
   - Activar el usuario

2. **El usuario podrá acceder** con Microsoft la próxima vez

### Ventajas de este Enfoque:

✅ **Control total** sobre quién puede acceder
✅ **Seguridad mejorada** - no hay usuarios "fantasma"
✅ **Gestión de permisos** centralizada
✅ **Auditoría completa** de intentos de acceso
✅ **Previene accesos no autorizados** desde el tenant

### Mensajes de Error para Usuarios:

- **Dominio no permitido**: "Tu dominio de correo no tiene acceso a esta aplicación"
- **Usuario no registrado**: "Tu cuenta debe ser creada por un administrador antes de poder acceder"
- **Usuario inactivo**: "Tu cuenta ha sido desactivada. Contacta al administrador"