@echo off
echo ========================================
echo Deteniendo procesos de sincronizacion
echo ========================================
echo.

REM Buscar y matar procesos de PHP que ejecutan glpi:sync-activos
echo Buscando procesos de sincronizacion...
for /f "tokens=2" %%a in ('tasklist /FI "IMAGENAME eq php.exe" /FO LIST ^| findstr /C:"PID:"') do (
    wmic process where "ProcessId=%%a" get CommandLine 2>nul | findstr /C:"glpi:sync-activos" >nul
    if not errorlevel 1 (
        echo Matando proceso PHP con PID %%a
        taskkill /F /PID %%a
    )
)

echo.
echo ========================================
echo Proceso completado
echo ========================================
echo.
echo Procesos PHP activos restantes:
tasklist /FI "IMAGENAME eq php.exe"
echo.
pause
