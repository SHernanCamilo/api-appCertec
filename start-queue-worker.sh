#!/bin/bash
echo "Iniciando Queue Worker para Tareas Programadas..."
echo ""
echo "Presiona Ctrl+C para detener el worker"
echo ""
php artisan queue:work --queue=scheduled-tasks --tries=3 --timeout=3600
