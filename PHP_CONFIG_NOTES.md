# Notas de Configuración PHP

## Advertencias Comunes y Soluciones

### 1. PHP Warning: Unable to load dynamic library 'pdo_odbc.so'
Esta advertencia aparece porque la extensión `pdo_odbc` está habilitada en `php.ini` pero no está instalada en el sistema.

#### ¿Es necesario para la app?
**NO**, la aplicación usa **MySQL** como base de datos principal. El `pdo_odbc` solo sería necesario si conectaras a SQL Server directamente (lo cual no hacemos, ya que usamos el servicio `GraphFabricGatewayService` para conectarnos a Microsoft Fabric/SQL Server via API).

#### Solución:
Comenta o elimina la línea en tu archivo `php.ini` o en el archivo de configuración de extensiones (ej: `/opt/cpanel/ea-php83/root/etc/php.d/pdo_odbc.ini`):
```ini
; extension=pdo_odbc.so
```

---

### 2. PHP Warning: Module "pcov" is already loaded
Esta advertencia aparece porque la extensión `pcov` está cargada dos veces (probablemente en dos archivos de configuración diferentes).

#### Solución:
Encuentra todos los archivos donde está habilitada `pcov` y deja solo una:
```bash
grep -r "extension=pcov.so" /opt/cpanel/ea-php83/root/etc/php.d/
```
Comenta todas las líneas excepto una.

---

## Configuración Opcional para SQL Server (PDO_ODBC)
Si en el futuro necesitas conectarte directamente a SQL Server desde PHP, sigue estos pasos:

### Paso 1: Instalar las extensiones necesarias
En un sistema RHEL/CentOS/AlmaLinux con cPanel:
```bash
# Instalar unixODBC y drivers de SQL Server
yum install unixodbc unixodbc-devel
# Descargar e instalar Microsoft ODBC Driver for SQL Server
# Sigue las instrucciones oficiales: https://learn.microsoft.com/es-es/sql/connect/php/installation-tutorial-linux-mac?view=sql-server-ver16
```

### Paso 2: Habilitar las extensiones en php.ini
```ini
extension=pdo_odbc.so
extension=odbc.so
```

### Paso 3: Configurar DSN (opcional)
Puedes configurar un DSN en `/etc/odbc.ini` para conectarte más fácilmente:
```ini
[MiSQLServer]
Driver=ODBC Driver 18 for SQL Server
Server=tcp:mi-servidor.database.windows.net,1433
Database=MiBaseDeDatos
Uid=mi-usuario
Pwd=mi-contraseña
Encrypt=yes
TrustServerCertificate=no
Connection Timeout=30
```

---

## Verificar Extensiones Cargadas
Ejecuta este comando para ver las extensiones PHP cargadas:
```bash
php -m
```
