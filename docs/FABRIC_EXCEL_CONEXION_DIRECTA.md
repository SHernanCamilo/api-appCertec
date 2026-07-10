# 📊 Conexión directa Excel ↔ Laravel — Vistas de Fabric

**Fecha:** 10 de Julio de 2026  
**Para:** Equipo Angular + Usuarios finales

---

## Resumen

Para cargar vistas de 500K+ registros en Excel sin problemas de paginación,
usamos **descarga directa de CSV** que Excel consume en streaming.

El flujo es simple:
1. Usuario hace clic en "Abrir en Excel" en el visor web
2. Laravel genera un **link temporal firmado** (15 min de vida)
3. Excel se conecta a ese link y descarga TODOS los datos de una vez
4. El usuario puede "Actualizar datos" en Excel cuando quiera (regenera el CSV)

---

## ¿Por qué NO usar OData?

| Problema con OData | Solución CSV directo |
|-------------------|---------------------|
| Excel pide metadata XML complejo | CSV no necesita metadata |
| Paginación con múltiples requests | Una sola descarga completa |
| Límite de 5K filas por request | Sin límite (streaming) |
| Errores de formato "no es servicio OData" | CSV siempre funciona |
| 500K filas = 100 requests = lento | 1 request = rápido |

---

## Flujo para el usuario

### Desde la web (Angular):

```
1. Usuario abre una vista en el Fabric Viewer
2. Hace clic en botón "📊 Abrir en Excel"
3. Se genera un link temporal (15 min)
4. Se muestra un diálogo:
   "Link generado. Ábrelo en Excel → Datos → Desde texto/CSV"
   [Copiar link]  [Abrir Excel automáticamente]
5. Excel descarga los datos completos
```

### Desde Excel directamente:

```
1. Datos → Desde texto/CSV (o Desde la web)
2. Pegar la URL: https://jade-api.medilaser.com.co/api/fabric/viewer/csv/TOKEN_TEMPORAL
3. Separador: Punto y coma (;)
4. Codificación: UTF-8
5. Clic "Cargar" → todos los datos en la hoja
```

---

## Implementación Backend (Laravel)

### Endpoint: Generar link temporal para CSV

```
POST /api/fabric/viewer/csv/generate
Body: { schema_name: "dc", view: "VW_HC_GestantesRegistroTipo5_Nva", filters: {} }
Response: { url: "https://jade-api.medilaser.com.co/api/fabric/viewer/csv/abc123...", expires_in: 900 }
```

### Endpoint: Descargar CSV (streaming)

```
GET /api/fabric/viewer/csv/{token}
Response: text/csv (streaming directo, sin cargar en RAM)
```

---

## Ventajas de esta solución

| Aspecto | Valor |
|---------|-------|
| RAM del servidor | ~50MB constante (streaming) |
| Filas máximas | 1,048,576 (límite de Excel, no del servidor) |
| Tiempo 109K filas | ~3 min (descarga + escritura) |
| Tiempo 500K filas | ~10-15 min |
| Actualizable | Sí — "Actualizar datos" en Excel regenera |
| Seguridad | Token temporal firmado (15 min), un solo uso |
| Compatibilidad | Excel 2013+ / LibreOffice / Google Sheets |

---

## Implementación Angular — Botón "Abrir en Excel"

```typescript
// En el componente del visor
openInExcel(): void {
  this.http.post<{ url: string; expires_in: number }>(
    '/api/fabric/viewer/csv/generate',
    {
      schema_name: this.currentSchema,
      view: this.currentView,
      filters: this.activeFilters,
    }
  ).subscribe(res => {
    // Opción A: Copiar al portapapeles
    navigator.clipboard.writeText(res.url);
    this.snackBar.open(
      'Link copiado. Abre Excel → Datos → Desde texto/CSV → pegar URL',
      'OK', { duration: 10000 }
    );

    // Opción B: Abrir protocolo de Excel (si está instalado)
    // window.open(`ms-excel:ofe|u|${res.url}`);
  });
}
```

---

## Cómo funciona el streaming de CSV

```
Excel solicita GET /api/fabric/viewer/csv/TOKEN
  ↓
Laravel valida token (firmado, no expirado)
  ↓
Laravel inicia streaming:
  - Header: Content-Type: text/csv
  - Header: Content-Disposition: attachment
  - Escribe BOM UTF-8
  - Escribe sep=;
  - Escribe headers de columnas
  ↓
Loop: pagina 5K filas por request a Graph-Fabric
  - Recibe batch → escribe al stream → flush → libera memoria
  - Repite hasta que no haya más datos
  ↓
Excel recibe las filas mientras se escriben (streaming HTTP)
```

**RAM del servidor:** constante ~50MB sin importar si son 100K o 1M filas.

---

## Seguridad del link temporal

| Aspecto | Implementación |
|---------|----------------|
| Expiración | 15 minutos desde la creación |
| Firma | HMAC-SHA256 con APP_KEY de Laravel |
| Un solo uso | Se invalida después de la primera descarga |
| Vinculado a usuario | El token codifica el user_id |
| Sin credenciales en la URL | El token ES la credencial (no necesita Bearer) |

---

## Para el equipo de Angular — Checklist

```
[ ] Agregar botón "📊 Abrir en Excel" al lado de "Descargar Excel"
[ ] Llamar POST /api/fabric/viewer/csv/generate con schema/view/filters
[ ] Mostrar diálogo con el link + opción copiar
[ ] Opcionalmente: abrir con protocolo ms-excel:ofe|u|URL
[ ] El link expira en 15 min — mostrar countdown si quieren
```

---

## Para usuarios — Guía rápida

### Paso 1: Generar el link
- En la web, abre la vista que quieres cargar
- Haz clic en **"📊 Abrir en Excel"**
- Se copia un link al portapapeles

### Paso 2: Abrir en Excel
- Abre Excel → **Datos** → **Desde texto/CSV**
- Pega la URL en el campo de archivo/URL
- Separador: **Punto y coma**
- Codificación: **65001: Unicode (UTF-8)**
- Clic **Cargar**

### Paso 3: Actualizar datos
- Para obtener datos frescos: **Datos** → **Actualizar todo**
- El link dura 15 minutos — si expira, genera uno nuevo desde la web

---

## Alternativa: Power Query con paginación automática (ya funciona)

Si prefieres usar Power Query con el endpoint JSON paginado:

```
Datos → Nueva consulta → Desde otras fuentes → Consulta en blanco
```

Editor avanzado → pegar:

```m
let
    BaseUrl = "https://jade-api.medilaser.com.co/api/fabric/odata/link/TU_CODE_AQUI",
    Token = "TU_TOKEN_AQUI",
    
    GetPage = (skip) =>
        let
            Url = BaseUrl & "?$top=5000&$skip=" & Text.From(skip) & "&token=" & Token,
            Source = Json.Document(Web.Contents(Url)),
            Data = Source[value]
        in Data,
    
    AllPages = List.Generate(
        () => [Page = GetPage(0), Skip = 0],
        each List.Count([Page]) > 0,
        each [Page = GetPage([Skip] + 5000), Skip = [Skip] + 5000],
        each [Page]
    ),
    
    Combined = List.Combine(AllPages),
    AsTable = Table.FromRecords(Combined)
in
    AsTable
```

Esto pagina automáticamente cada 5,000 filas hasta cargar todo.

---

*Última actualización: 10 de Julio de 2026*
