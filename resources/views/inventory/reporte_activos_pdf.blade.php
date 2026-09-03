<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Toma de Inventario de Activos Fijos</title>
    <style>
        @page { margin: 90px 28px 60px 28px; }

        * { font-family: "DejaVu Sans", Arial, sans-serif; }
        body { font-size: 9px; color: #2b2f36; margin: 0; }

        /* ── Encabezado fijo ───────────────────────────────────────── */
        header {
            position: fixed;
            top: -70px; left: 0; right: 0;
            height: 70px;
        }
        .hd-table { width: 100%; border-collapse: collapse; }
        .hd-title { font-size: 15px; font-weight: bold; color: #1f3b6e; }
        .hd-sub   { font-size: 9px; color: #6b7280; }
        .hd-badge {
            text-align: right; font-size: 8px; color: #6b7280;
        }
        .hd-rule { border-bottom: 2px solid #1f3b6e; margin-top: 6px; }

        /* ── Pie fijo ──────────────────────────────────────────────── */
        footer {
            position: fixed;
            bottom: -40px; left: 0; right: 0;
            height: 40px;
            font-size: 7.5px; color: #9aa1ab;
            border-top: 1px solid #d7dbe0;
            padding-top: 4px;
        }
        .ft-table { width: 100%; border-collapse: collapse; }
        .ft-right { text-align: right; }
        .pagenum:after { content: counter(page) " / " counter(pages); }

        /* ── Bloque de filtros ─────────────────────────────────────── */
        .filtros {
            background: #f3f6fb; border: 1px solid #dbe3ef;
            border-radius: 4px; padding: 6px 8px; margin-bottom: 10px;
            font-size: 8.5px; color: #445;
        }
        .filtros strong { color: #1f3b6e; }

        /* ── Resumen (KPIs) ────────────────────────────────────────── */
        .resumen-tabla { width: 100%; border-collapse: separate; border-spacing: 5px 0; margin-bottom: 12px; }
        .kpi {
            width: 20%; text-align: center;
            background: #ffffff; border: 1px solid #e0e5ec; border-radius: 5px;
            padding: 8px 4px;
        }
        .kpi-num   { font-size: 16px; font-weight: bold; color: #1f3b6e; }
        .kpi-lbl   { font-size: 7.5px; color: #6b7280; text-transform: uppercase; letter-spacing: .3px; }
        .kpi.ok    { border-top: 3px solid #2e9e5b; }
        .kpi.nov   { border-top: 3px solid #d98a00; }
        .kpi.new   { border-top: 3px solid #8250c4; }
        .kpi.tot   { border-top: 3px solid #1f3b6e; }
        .kpi.act   { border-top: 3px solid #3b7ddd; }

        .seccion-titulo {
            font-size: 11px; font-weight: bold; color: #1f3b6e;
            margin: 6px 0 5px; padding-bottom: 3px;
            border-bottom: 1px solid #e0e5ec;
        }

        /* ── Tabla de detalle ──────────────────────────────────────── */
        table.detalle { width: 100%; border-collapse: collapse; }
        table.detalle th {
            background: #1f3b6e; color: #fff; font-size: 7.5px;
            padding: 5px 4px; text-align: left; font-weight: bold;
        }
        table.detalle td {
            border-bottom: 1px solid #e4e8ee; padding: 4px;
            font-size: 7.5px; vertical-align: top;
        }
        table.detalle tr:nth-child(even) td { background: #f7f9fc; }

        .badge {
            display: inline-block; padding: 1px 5px; border-radius: 8px;
            font-size: 7px; font-weight: bold;
        }
        .b-nov { background: #fff3df; color: #9a6200; }
        .b-ok  { background: #e4f5ea; color: #1e7a44; }
        .b-new { background: #efe7fb; color: #5f3aa6; }

        .empty { text-align: center; color: #9aa1ab; padding: 24px; font-style: italic; }
    </style>
</head>
<body>

    <header>
        <table class="hd-table">
            <tr>
                <td>
                    <div class="hd-title">Toma de Inventario de Activos Fijos</div>
                    <div class="hd-sub">Reporte consolidado de trazabilidad</div>
                </td>
                <td class="hd-badge">
                    Generado: {{ $generado }}<br>
                    Sistema JadeOne
                </td>
            </tr>
        </table>
        <div class="hd-rule"></div>
    </header>

    <footer>
        <table class="ft-table">
            <tr>
                <td>Documento generado automáticamente — información de referencia. Fuente oficial del activo: Indigo.</td>
                <td class="ft-right">Página <span class="pagenum"></span></td>
            </tr>
        </table>
    </footer>

    <main>
        {{-- Filtros aplicados --}}
        <div class="filtros">
            {{ $filtrosTexto ?? 'Filtros: Todos los inventarios' }}
        </div>

        {{-- Resumen KPIs --}}
        <div class="seccion-titulo">Resumen</div>
        <table class="resumen-tabla">
            <tr>
                <td class="kpi tot">
                    <div class="kpi-num">{{ number_format($resumen['total_tomas'] ?? 0) }}</div>
                    <div class="kpi-lbl">Total tomas</div>
                </td>
                <td class="kpi act">
                    <div class="kpi-num">{{ number_format($resumen['activos_distintos'] ?? 0) }}</div>
                    <div class="kpi-lbl">Activos inventariados</div>
                </td>
                <td class="kpi nov">
                    <div class="kpi-num">{{ number_format($resumen['con_novedades'] ?? 0) }}</div>
                    <div class="kpi-lbl">Con novedad</div>
                </td>
                <td class="kpi ok">
                    <div class="kpi-num">{{ number_format($resumen['sin_novedades'] ?? 0) }}</div>
                    <div class="kpi-lbl">Sin novedad</div>
                </td>
                <td class="kpi new">
                    <div class="kpi-num">{{ number_format($resumen['nuevos'] ?? 0) }}</div>
                    <div class="kpi-lbl">Nuevos (externos)</div>
                </td>
            </tr>
        </table>

        {{-- Detalle --}}
        <div class="seccion-titulo">Detalle de tomas ({{ count($filas) }})</div>
        <table class="detalle">
            <thead>
                <tr>
                    @foreach ($headers as $h)
                        <th>{{ $h }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($filas as $fila)
                    <tr>
                        @foreach ($fila as $clave => $celda)
                            <td>
                                @if ($clave === 'resultado')
                                    @php
                                        $cls = $celda === 'con_novedades' ? 'b-nov'
                                            : ($celda === 'externo' ? 'b-new' : 'b-ok');
                                        $txt = $celda === 'con_novedades' ? 'Con novedad'
                                            : ($celda === 'externo' ? 'Nuevo' : 'Sin novedad');
                                    @endphp
                                    <span class="badge {{ $cls }}">{{ $txt }}</span>
                                @else
                                    {{ $celda }}
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td class="empty" colspan="{{ count($headers) }}">
                            No hay registros para los filtros seleccionados.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </main>

</body>
</html>
