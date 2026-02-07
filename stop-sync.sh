#!/bin/bash

echo "========================================"
echo "Deteniendo procesos de sincronización"
echo "========================================"
echo ""

# Buscar y matar procesos de PHP que ejecutan glpi:sync-activos
echo "Buscando procesos de sincronización..."
pids=$(ps aux | grep "glpi:sync-activos" | grep -v grep | awk '{print $2}')

if [ -z "$pids" ]; then
    echo "No se encontraron procesos de sincronización activos"
else
    echo "Procesos encontrados: $pids"
    for pid in $pids; do
        echo "Matando proceso con PID $pid"
        kill -9 $pid
    done
    echo "Procesos detenidos exitosamente"
fi

echo ""
echo "========================================"
echo "Proceso completado"
echo "========================================"
echo ""
echo "Procesos PHP activos restantes:"
ps aux | grep php | grep -v grep
echo ""
