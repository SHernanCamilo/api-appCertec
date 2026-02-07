# 🔧 Comandos para Validar y Arreglar Sidebar Dinámico

Este documento describe los comandos de Laravel creados para diagnosticar y solucionar problemas con el sidebar dinámico.

## 📋 Lista de Comandos

### 1. `sidebar:validate-users` - Validar Usuarios
Valida qué usuarios tendrían módulos cargados dinámicamente en el sidebar.

### 2. `sidebar:fix-users` - Arreglar Usuarios  
Arregla problemas comunes que impiden que los usuarios tengan sidebar dinámico.

### 3. `sidebar:report` - Generar Reportes
Genera reportes completos del estado del sidebar dinámico.

---

## 🔍 Comando: `sidebar:validate-users`

### Uso Básico
```bash
# Validar todos los usuarios activos
php artisan sidebar:validate-users

# Validar usuario específico por ID
php artisan sidebar:validate-users --user-id=1

# Validar usuario específico por email
php artisan sidebar:validate-users --email=admin@example.com

# Mostrar información detallada
php artisan sidebar:validate-users --detailed

# Solo mostrar usuarios CON módulos
php artisan sidebar:validate-users --only-with-modules

# Solo mostrar usuarios SIN módulos
php artisan sidebar:validate-users --only-without-modules

# Exportar resultados a archivo
php artisan sidebar:validate-users --export=reporte_sidebar.txt
```

### Ejemplo de Salida
```
🔍 VALIDADOR DE SIDEBAR DINÁMICO
================================================================================

📊 ESTADÍSTICAS GENERALES:
   ✅ Usuarios activos: 5
   ✅ Módulos activos: 3
   ✅ Módulos raíz: 1
   ✅ Roles activos: 2
   ✅ Perfiles activos: 3
   ✅ Empresas activas: 1
   ✅ Asignaciones módulo-empresa: 1

👥 VALIDANDO 5 USUARIOS:
--------------------------------------------------------------------------------

✅ Juan Pérez (juan@example.com) - CON SIDEBAR
   Roles: 1, Módulos: 1

❌ María García (maria@example.com) - SIN SIDEBAR
   Roles: 0, Módulos: 0 - Problemas: 1

📈 RESUMEN FINAL:
   ✅ Usuarios CON módulos en sidebar: 1 (20.0%)
   ❌ Usuarios SIN módulos en sidebar: 4 (80.0%)
   📊 Total usuarios validados: 5
```

---

## 🔧 Comando: `sidebar:fix-users`

### Uso Básico
```bash
# Ver qué se arreglaría sin hacer cambios
php artisan sidebar:fix-users --dry-run

# Arreglar todos los usuarios con problemas
php artisan sidebar:fix-users

# Arreglar usuario específico
php artisan sidebar:fix-users --user-id=1

# Crear datos faltantes automáticamente
php artisan sidebar:fix-users --create-missing

# Arreglar usuario específico con creación de datos
php artisan sidebar:fix-users --email=admin@example.com --create-missing
```

### Ejemplo de Salida
```
🔧 REPARADOR DE SIDEBAR DINÁMICO
================================================================================

🔨 ARREGLANDO 3 USUARIOS:
--------------------------------------------------------------------------------

🔍 Analizando: María García (maria@example.com)
   ✅ Cambios aplicados:
      • Asignar rol básico
      • Asignar empresa principal

🔍 Analizando: Carlos López (carlos@example.com)
   ✅ Cambios aplicados:
      • Habilitar permisos de lectura en perfiles

📈 RESUMEN:
   ✅ Usuarios arreglados: 2
   ❌ Errores: 0
   📊 Total procesados: 3
```

---

## 📊 Comando: `sidebar:report`

### Uso Básico
```bash
# Generar reporte en formato tabla (por defecto)
php artisan sidebar:report

# Generar reporte en formato JSON
php artisan sidebar:report --format=json

# Generar reporte en formato CSV
php artisan sidebar:report --format=csv

# Guardar reporte en archivo
php artisan sidebar:report --format=json --output=reporte_sidebar.json

# Incluir usuarios inactivos
php artisan sidebar:report --include-inactive

# Generar CSV completo con usuarios inactivos
php artisan sidebar:report --format=csv --output=reporte_completo.csv --include-inactive
```

### Ejemplo de Salida (Formato Tabla)
```
📊 GENERADOR DE REPORTES DE SIDEBAR
================================================================================

📊 ESTADÍSTICAS GENERALES:
+---------------------------+-------+
| Métrica                   | Valor |
+---------------------------+-------+
| Usuarios activos          | 5     |
| Usuarios inactivos        | 2     |
| Módulos activos           | 3     |
| Roles activos             | 2     |
+---------------------------+-------+

👥 RESUMEN DE USUARIOS:
   Total usuarios: 5
   Con sidebar: 2 (40.0%)
   Sin sidebar: 3 (60.0%)

👤 DETALLE DE USUARIOS:
+-------------+-------------------+--------+-------+---------+---------+
| Nombre      | Email             | Estado | Roles | Módulos | Sidebar |
+-------------+-------------------+--------+-------+---------+---------+
| Juan Pérez  | juan@example.com  | Activo | 1     | 1       | ✅      |
| María García| maria@example.com | Activo | 0     | 0       | ❌      |
+-------------+-------------------+--------+-------+---------+---------+
```

---

## 🚀 Flujo de Trabajo Recomendado

### 1. Diagnóstico Inicial
```bash
# Ver estado general del sistema
php artisan sidebar:validate-users --detailed
```

### 2. Identificar Problemas
```bash
# Ver solo usuarios sin sidebar
php artisan sidebar:validate-users --only-without-modules --detailed
```

### 3. Arreglar Problemas
```bash
# Primero ver qué se haría
php artisan sidebar:fix-users --dry-run

# Aplicar arreglos
php artisan sidebar:fix-users --create-missing
```

### 4. Verificar Resultados
```bash
# Validar que se arreglaron los problemas
php artisan sidebar:validate-users
```

### 5. Generar Reporte Final
```bash
# Crear reporte para documentar el estado
php artisan sidebar:report --format=csv --output=sidebar_final.csv
```

---

## 🔍 Problemas Comunes y Soluciones

### Problema: "Usuario sin roles asignados"
**Solución:**
```bash
php artisan sidebar:fix-users --user-id=X
```

### Problema: "Sin permisos de lectura en perfiles"
**Solución:**
```bash
php artisan sidebar:fix-users --user-id=X
```

### Problema: "Las empresas del usuario no tienen módulos asignados"
**Solución:**
1. Ejecutar el setup inicial:
```bash
php setup_sidebar_data.php
```
2. O arreglar manualmente:
```bash
php artisan sidebar:fix-users --create-missing
```

### Problema: "No hay módulos raíz configurados"
**Solución:**
```bash
php setup_sidebar_data.php
```

---

## 📝 Logs y Debugging

### Ver logs del SidebarService
```bash
tail -f storage/logs/laravel.log | grep -i sidebar
```

### Verificar estructura de base de datos
```bash
php check_sidebar.php
```

### Verificar datos completos
```bash
php revisar_permisos.php
```

---

## 🎯 Casos de Uso Específicos

### Nuevo Usuario sin Acceso
```bash
# Crear usuario con acceso básico
php artisan sidebar:fix-users --email=nuevo@example.com --create-missing
```

### Migración de Usuarios Existentes
```bash
# Arreglar todos los usuarios de una vez
php artisan sidebar:fix-users --create-missing

# Generar reporte de migración
php artisan sidebar:report --format=csv --output=migracion_usuarios.csv
```

### Auditoría de Permisos
```bash
# Reporte completo incluyendo inactivos
php artisan sidebar:report --include-inactive --format=json --output=auditoria_completa.json
```

### Testing de Nuevos Módulos
```bash
# Validar después de agregar nuevos módulos
php artisan sidebar:validate-users --detailed --export=test_nuevos_modulos.txt
```

---

## ⚡ Comandos Rápidos

```bash
# Diagnóstico rápido
php artisan sidebar:validate-users --only-without-modules

# Arreglo rápido
php artisan sidebar:fix-users

# Reporte rápido
php artisan sidebar:report --format=table
```