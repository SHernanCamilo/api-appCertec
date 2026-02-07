#!/bin/bash

echo "========================================"
echo "DETENER Y LIMPIAR SINCRONIZACIONES"
echo "========================================"
echo ""

echo "[1/3] Deteniendo procesos activos..."
php artisan glpi:stop-sync --all
echo ""

echo "[2/3] Limpiando cache huérfano..."
php limpiar-cache-sync.php
echo ""

echo "[3/3] Verificando estado final..."
php artisan glpi:stop-sync
echo ""

echo "========================================"
echo "PROCESO COMPLETADO"
echo "========================================"
echo ""
echo "Verifica en la interfaz web que ya no aparezca"
echo "la barra de progreso. Si aún aparece, recarga"
echo "la página (Ctrl+F5)."
echo ""
