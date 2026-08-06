{{--
    Plantilla única del PDF de ficha técnica.
    Reemplaza includes/pdf.php, pdf_os.php, ficha_pdf.php y ficha_os_pdf.php
    del sistema JADE legacy (cuatro copias del mismo maquetado en FPDF).
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ficha Técnica {{ $ficha->consecutivo ?? 'Borrador '.$ficha->id }}</title>
    <style>
        @page { margin: 90px 28px 55px 28px; }
        body  { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #212529; margin: 0; }

        header { position: fixed; top: -72px; left: 0; right: 0; height: 62px; }
        footer { position: fixed; bottom: -40px; left: 0; right: 0; height: 30px;
                 font-size: 7px; color: #6c757d; border-top: 1px solid #dee2e6; padding-top: 4px; }

        .titulo      { font-size: 13px; font-weight: bold; text-align: center; margin: 0; }
        .subtitulo   { font-size: 9px; text-align: center; color: #495057; margin: 2px 0 0; }
        .consecutivo { font-size: 11px; font-weight: bold; text-align: right; }

        table       { width: 100%; border-collapse: collapse; }
        .datos td   { padding: 3px 5px; border-bottom: 1px solid #e9ecef; vertical-align: top; }
        .datos .lbl { width: 16%; font-weight: bold; color: #495057; background: #f8f9fa; }

        .servicios th { background: #e9ecef; border: 1px solid #adb5bd; padding: 4px 3px;
                        font-size: 7.5px; text-align: center; }
        .servicios td { border: 1px solid #ced4da; padding: 3px; font-size: 7.5px; }
        .servicios tbody tr:nth-child(even) td { background: #fbfbfc; }

        .grupo-head { background: #dee2e6; font-weight: bold; font-size: 8px;
                      border: 1px solid #adb5bd; padding: 4px; }

        .seccion { font-size: 9.5px; font-weight: bold; margin: 12px 0 4px;
                   border-bottom: 1.5px solid #495057; padding-bottom: 2px; }

        .num   { text-align: right; }
        .cen   { text-align: center; }
        .total { background: #f1f3f5; font-weight: bold; }

        .badge { display: inline-block; padding: 1px 6px; border-radius: 8px;
                 color: #fff; font-size: 7.5px; }
        .firmas { margin-top: 26px; }
        .firmas td { width: 33%; padding: 22px 8px 4px; border-top: 1px solid #495057;
                     text-align: center; font-size: 7.5px; vertical-align: top; }
        .aviso-os { background: #fff3cd; border: 1px solid #ffe69c; padding: 6px 8px;
                    margin: 8px 0; font-size: 8px; }
    </style>
</head>
<body>

<header>
    <table>
        <tr>
            <td style="width: 60%;">
                <p class="titulo">FICHA TÉCNICA DE CONTRATACIÓN DE SERVICIOS MÉDICOS</p>
                <p class="subtitulo">{{ $ficha->empresa->nombre ?? 'JadeOne' }}</p>
            </td>
            <td style="width: 40%;" class="consecutivo">
                {{ $ficha->consecutivo ?? 'BORRADOR No. '.$ficha->id }}<br>
                <span class="badge" style="background: {{ $ficha->estado->color_hex ?? '#6c757d' }};">
                    {{ $ficha->estado->descripcion ?? '' }}
                </span>
            </td>
        </tr>
    </table>
</header>

<footer>
    <table>
        <tr>
            <td>JadeOne · Fichas Técnicas Médicas · Generado {{ $generadoEn->format('d/m/Y H:i') }}</td>
            <td class="cen">{{ $ficha->consecutivo ?? 'Borrador '.$ficha->id }}</td>
            <td style="text-align: right;">Página <span class="pagenum"></span></td>
        </tr>
    </table>
</footer>

@if ($ficha->esActualizacion())
    <div class="aviso-os">
        <strong>ACTUALIZACIÓN (versión {{ $ficha->version }})</strong>
        de la ficha {{ $ficha->padre->consecutivo ?? '—' }}.<br>
        <strong>Motivo:</strong> {{ $ficha->obs_os ?? 'No especificado' }}
    </div>
@endif

<div class="seccion">1. DATOS DEL CONTRATO</div>
<table class="datos">
    <tr>
        <td class="lbl">Agremiación</td>
        <td>{{ $ficha->agremiacion->nombre ?? '—' }}</td>
        <td class="lbl">NIT</td>
        <td>{{ $ficha->agremiacion->nit ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Rep. legal</td>
        <td>{{ $ficha->agremiacion->rep_legal ?? '—' }}</td>
        <td class="lbl">C.C.</td>
        <td>{{ $ficha->agremiacion->cc_rep_legal ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Objeto</td>
        <td colspan="3">{{ $ficha->objetoContrato->descripcion ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Especialidad</td>
        <td>{{ $ficha->especialidad->descripcion ?? '—' }}</td>
        <td class="lbl">Perfil</td>
        <td>{{ $ficha->especialidad->perfil ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Sucursal</td>
        <td>{{ $ficha->sucursal->nombre ?? $ficha->sucursal_legacy ?? '—' }}</td>
        <td class="lbl">Valor estimado</td>
        <td class="num">${{ number_format((float) $ficha->vlr_contrato, 2, ',', '.') }}</td>
    </tr>
    <tr>
        <td class="lbl">Vigencia</td>
        <td>
            {{ $ficha->fecha_ini?->format('d/m/Y') }} — {{ $ficha->fecha_fin?->format('d/m/Y') }}
            @if ($ficha->dias_restantes !== null)
                ({{ $ficha->vigencia_estado }}, {{ $ficha->dias_restantes }} días)
            @endif
        </td>
        <td class="lbl">Registro</td>
        <td>{{ $ficha->fecha_reg?->format('d/m/Y H:i') ?? '—' }}</td>
    </tr>
</table>

<div class="seccion">2. PROFESIONALES VINCULADOS ({{ $ficha->profesionales->count() }})</div>
<table class="servicios">
    <thead>
        <tr>
            <th style="width: 5%;">#</th>
            <th style="width: 18%;">Documento</th>
            <th>Nombre</th>
            <th style="width: 20%;">Tarjeta profesional</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($ficha->profesionales as $i => $profesional)
            <tr>
                <td class="cen">{{ $i + 1 }}</td>
                <td class="cen">{{ $profesional->documento }}</td>
                <td>{{ $profesional->nombre }}</td>
                <td class="cen">{{ $profesional->tarjeta_profesional ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="cen">Sin profesionales vinculados</td></tr>
        @endforelse
    </tbody>
</table>

<div class="seccion">3. SERVICIOS CONTRATADOS ({{ $detalles->count() }})</div>
<table class="servicios">
    <thead>
        <tr>
            <th style="width: 8%;">CUPS</th>
            <th>Descripción</th>
            <th style="width: 11%;">Liquidación</th>
            <th style="width: 11%;">Homólogo</th>
            <th style="width: 10%;">Forma pago</th>
            <th style="width: 6%;">%</th>
            <th style="width: 11%;">Valor</th>
            <th style="width: 5%;">Obs.</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($porGrupo as $nombreGrupo => $items)
            @if ($porGrupo->count() > 1)
                <tr><td colspan="8" class="grupo-head">{{ $nombreGrupo }}</td></tr>
            @endif
            @foreach ($items as $detalle)
                <tr>
                    <td class="cen">{{ $detalle->cups ?? '—' }}</td>
                    <td>{{ $detalle->cups_descripcion ?? $detalle->homologo_descripcion ?? '—' }}</td>
                    <td class="cen">{{ $detalle->tipo_liquidacion ?? '—' }}</td>
                    <td class="cen">{{ $detalle->homologo ?? '—' }}</td>
                    <td class="cen">{{ $detalle->forma_pago ?? '—' }}</td>
                    <td class="cen">{{ $detalle->variacion ?? '—' }}</td>
                    <td class="num">${{ number_format((float) $detalle->valor, 2, ',', '.') }}</td>
                    <td class="cen">{{ $detalle->id_obs_item ?? '' }}</td>
                </tr>
            @endforeach
        @empty
            <tr><td colspan="8" class="cen">Sin servicios registrados</td></tr>
        @endforelse
        <tr class="total">
            <td colspan="6" style="text-align: right;">TOTAL SERVICIOS</td>
            <td class="num">${{ number_format((float) $ficha->valor_total_detalles, 2, ',', '.') }}</td>
            <td></td>
        </tr>
    </tbody>
</table>

@if ($homologos->isNotEmpty())
    <div class="seccion">4. HOMOLOGACIÓN DE CÓDIGOS</div>
    <table class="servicios">
        <thead>
            <tr>
                <th style="width: 9%;">CUPS</th>
                <th>Descripción CUPS</th>
                <th style="width: 12%;">Manual</th>
                <th style="width: 9%;">Código</th>
                <th>Descripción manual</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($homologos as $homologo)
                <tr>
                    <td class="cen">{{ $homologo->cod_cups }}</td>
                    <td>{{ $homologo->desc_cups }}</td>
                    <td class="cen">{{ $homologo->tipo_manual }}</td>
                    <td class="cen">{{ $homologo->code_manual }}</td>
                    <td>{{ $homologo->desc_manual }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if ($observaciones->isNotEmpty())
    <div class="seccion">5. CONVENCIONES DE OBSERVACIONES</div>
    <table class="servicios">
        <thead>
            <tr><th style="width: 8%;">Código</th><th>Descripción</th></tr>
        </thead>
        <tbody>
            @foreach ($observaciones as $observacion)
                <tr>
                    <td class="cen">{{ $observacion->codigo }}</td>
                    <td>{{ $observacion->descripcion }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if ($ficha->observaciones->isNotEmpty())
    <div class="seccion">6. OBSERVACIONES GENERALES</div>
    <table class="servicios">
        <tbody>
            @foreach ($ficha->observaciones as $observacion)
                <tr><td>{{ $observacion->desc_obs }}</td></tr>
            @endforeach
        </tbody>
    </table>
@endif

<div class="seccion">TRAZABILIDAD Y FIRMAS</div>
<table class="firmas">
    <tr>
        <td>
            <strong>{{ $ficha->generador->name ?? '—' }}</strong><br>
            Generador<br>
            {{ $ficha->fecha_reg?->format('d/m/Y H:i') ?? '—' }}
        </td>
        <td>
            <strong>{{ $ficha->autorizador->name ?? 'Pendiente' }}</strong><br>
            Autorizó — Dirección Médica<br>
            {{ $ficha->fecha_autoriza?->format('d/m/Y H:i') ?? '—' }}
        </td>
        <td>
            <strong>{{ $ficha->aprobador->name ?? 'Pendiente' }}</strong><br>
            Aprobó — Vicepresidencia Financiera<br>
            {{ $ficha->fecha_aprueba?->format('d/m/Y H:i') ?? '—' }}
        </td>
    </tr>
</table>

<script type="text/php">
    if (isset($pdf)) {
        $pdf->page_script('
            $font = $fontMetrics->get_font("DejaVu Sans", "normal");
            $pdf->text(520, 748, $PAGE_NUM . " de " . $PAGE_COUNT, $font, 7, [0.42, 0.46, 0.49]);
        ');
    }
</script>

</body>
</html>
