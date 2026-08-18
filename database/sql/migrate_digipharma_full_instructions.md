# Migración Completa: Digipharma (192.168.12.20) → VPS (72.167.224.130)

## Datos de conexión

### Origen (192.168.12.20)
```
DB_HOST=192.168.12.20
DB_PORT=3306
DB_NAME=digipharma
DB_USER=digipharma_app
DB_PASS=kD21c2P7wQW9
```

### Destino (VPS)
```
DB_HOST=72.167.224.130
DB_PORT=3306
DB_DATABASE=medadminvps_Jade-plataform
DB_USERNAME=medadminvps_Apps_TI
DB_PASSWORD="NiSV.7mnxKm+inLm9q!Zr_60bz^s4+"
```

## Mapeo de Tablas

| # | Tabla Digipharma | Tabla VPS | Columnas renombradas |
|---|---|---|---|
| 1 | `pedidos` | `inv_pedidos` | `creado_en` → `created_at`, `actualizado_en` → `updated_at` |
| 2 | `pedidos_detalle` | `inv_pedido_detalles` | idem |
| 3 | `pedidos_trazabilidad` | `inv_pedido_trazabilidads` | idem |
| 4 | `compras` | `inv_ordenes_compra` | `creado_en` → `created_at`, `actualizado_en` → `updated_at` |
| 5 | `compras_detalle` | `inv_orden_compra_detalles` | `compra_id` se mantiene |
| 6 | `compras_pedidos` | `inv_compras_pedidos` | se mantiene |
| 7 | `compras_auditoria` | `inv_compras_auditoria` | se mantiene |
| 8 | `compras_validacion_log` | `inv_compras_validacion_log` | se mantiene |
| 9 | `indigo_ordenes_items` | `inv_indigo_items` | `creado_en` → `created_at` |
| 10 | `indigo_ordenes_eventos` | `inv_indigo_eventos` | `creado_en` → `created_at` |
| 11 | `indigo_ordenes_trazabilidad` | `inv_indigo_trazabilidad` | idem |
| 12 | `recepciones_historico` | `inv_recepciones` | idem |
| 13 | `recepciones_historico_detalle` | `inv_recepcion_detalles` | idem |
| 14 | `formula_magistral_muestra` | `inv_muestreo_niveles` | mapeo manual |
| 15 | `formula_magistral_muestra_exclusion` | `inv_muestreo_exclusiones` | mapeo manual |

## Orden de Ejecución

### Paso 1: Ejecutar script base con datos estáticos
```bash
mysql -h 72.167.224.130 -P 3306 -u medadminvps_Apps_TI -p"NiSV.7mnxKm+inLm9q!Zr_60bz^s4+" medadminvps_Jade-plataform < database/sql/migrate_digipharma_to_vps.sql
```

### Paso 2: Importar pedidos_detalle
```sql
-- Desde la 12.20, exportar:
-- mysqldump -h 192.168.12.20 -u digipharma_app -p'kD21c2P7wQW9' digipharma pedidos_detalle --no-create-info --complete-insert > /tmp/pedidos_detalle.sql
-- Luego reemplazar nombres de tabla e importar en VPS:
-- sed -i 's/`pedidos_detalle`/`inv_pedido_detalles`/g' /tmp/pedidos_detalle.sql
-- sed -i 's/`creado_en`/`created_at`/g' /tmp/pedidos_detalle.sql
-- sed -i 's/`actualizado_en`/`updated_at`/g' /tmp/pedidos_detalle.sql
```

### Paso 3: Importar compras (ordenes de compra)
```sql
-- mysqldump -h 192.168.12.20 -u digipharma_app -p'kD21c2P7wQW9' digipharma compras --no-create-info --complete-insert > /tmp/compras.sql
-- sed -i 's/`compras`/`inv_ordenes_compra`/g' /tmp/compras.sql
-- sed -i 's/`creado_en`/`created_at`/g' /tmp/compras.sql
-- sed -i 's/`actualizado_en`/`updated_at`/g' /tmp/compras.sql
-- NOTA: Agregar campo `proveedor_nombre` manualmente si existe en los datos
```

### Paso 4: Importar compras_detalle
```sql
-- mysqldump -h 192.168.12.20 -u digipharma_app -p'kD21c2P7wQW9' digipharma compras_detalle --no-create-info --complete-insert > /tmp/compras_detalle.sql
-- sed -i 's/`compras_detalle`/`inv_orden_compra_detalles`/g' /tmp/compras_detalle.sql
-- sed -i 's/`creado_en`/`created_at`/g' /tmp/compras_detalle.sql
-- sed -i 's/`actualizado_en`/`updated_at`/g' /tmp/compras_detalle.sql
```

### Paso 5: Importar indigo_ordenes_items
```sql
-- mysqldump -h 192.168.12.20 -u digipharma_app -p'kD21c2P7wQW9' digipharma indigo_ordenes_items --no-create-info --complete-insert > /tmp/indigo_items.sql
-- sed -i 's/`indigo_ordenes_items`/`inv_indigo_items`/g' /tmp/indigo_items.sql
-- sed -i 's/`creado_en`/`created_at`/g' /tmp/indigo_items.sql
```

### Paso 6: Importar indigo_ordenes_eventos
```sql
-- mysqldump -h 192.168.12.20 -u digipharma_app -p'kD21c2P7wQW9' digipharma indigo_ordenes_eventos --no-create-info --complete-insert > /tmp/indigo_eventos.sql
-- sed -i 's/`indigo_ordenes_eventos`/`inv_indigo_eventos`/g' /tmp/indigo_eventos.sql
-- sed -i 's/`creado_en`/`created_at`/g' /tmp/indigo_eventos.sql
```

### Paso 7: Importar recepciones_historico_detalle
```sql
-- mysqldump -h 192.168.12.20 -u digipharma_app -p'kD21c2P7wQW9' digipharma recepciones_historico_detalle --no-create-info --complete-insert > /tmp/recepcion_det.sql
-- sed -i 's/`recepciones_historico_detalle`/`inv_recepcion_detalles`/g' /tmp/recepcion_det.sql
-- sed -i 's/`creado_en`/`created_at`/g' /tmp/recepcion_det.sql
```

## Consideraciones Importantes

1. **IDs de usuarios**: En Digipharma los usuarios son (10, 11, 12, 13). En la VPS mapear a `1` (admin) o verificar que existan.
2. **FK constraints**: El script base desactiva FK checks. Importar en el orden indicado.
3. **Campo `proveedor_nombre`**: Fue agregado por la migración `2026_07_18_000001`. Verificar que existe en la VPS.
4. **Campo `sucursal_id`**: Existe en `indigo_ordenes_items` de Digipharma pero NO en la migración VPS. Se puede ignorar o agregar.
5. **Pedidos_trazabilidad**: Importar después de pedidos.

## Estado del MonitoringService

- Los comandos artisan `SyncIndigoOrders` y `SyncIndigoOrdersCommand` están **INACTIVOS** (código comentado).
- La ruta `POST /api/inventario/ordenes-compra/sync` está activa para invocación manual.
- Para reactivar la sincronización automática, descomentar el código en `app/Console/Commands/SyncIndigoOrders.php`.
