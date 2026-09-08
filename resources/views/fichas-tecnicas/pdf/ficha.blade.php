{{--
    Plantilla del PDF de ficha técnica — "FICHA TÉCNICA PRESTACIÓN DE SERVICIOS DE SALUD".
    Maquetado alineado al formato institucional F-GJ-145 MD (Medilaser).
    Reemplaza includes/pdf.php, pdf_os.php, ficha_pdf.php y ficha_os_pdf.php del legacy.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ficha Técnica {{ $ficha->consecutivo ?? 'Borrador '.$ficha->id }}</title>
    <style>
        @page { margin: 104px 26px 58px 26px; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8px; color: #1f2937; margin: 0; }

        /* ── Encabezado institucional (membrete con recuadro de control) ── */
        header { position: fixed; top: -92px; left: 0; right: 0; height: 82px; }
        .hdr { width: 100%; border-collapse: collapse; border: 1.2px solid #1f2937; }
        .hdr td { border: 1px solid #1f2937; padding: 4px 6px; vertical-align: middle; }
        .hdr .logo { width: 20%; text-align: center; font-weight: bold; font-size: 11px; color: #b91c1c; }
        .hdr .titulo { width: 56%; text-align: center; }
        .hdr .titulo h1 { margin: 0; font-size: 12px; font-weight: bold; letter-spacing: .3px; }
        .hdr .titulo .sub { font-size: 7px; color: #374151; margin-top: 2px; }
        .hdr .ctrl { width: 24%; padding: 0; }
        .hdr .ctrl table { width: 100%; border-collapse: collapse; }
        .hdr .ctrl td { border: 1px solid #1f2937; font-size: 6.5px; padding: 2px 3px; text-align: center; }
        .hdr .ctrl .k { background: #f3f4f6; font-weight: bold; }

        footer { position: fixed; bottom: -42px; left: 0; right: 0; height: 32px;
                 font-size: 6.8px; color: #6b7280; border-top: 1px solid #d1d5db; padding-top: 4px; }

        /* ── Secciones ── */
        .sec { background: #1f2937; color: #fff; font-size: 8.5px; font-weight: bold;
               padding: 3px 6px; margin: 9px 0 0; text-transform: uppercase; letter-spacing: .3px; }

        table.grid { width: 100%; border-collapse: collapse; }
        .grid td, .grid th { border: 1px solid #9ca3af; padding: 3px 5px; vertical-align: top; }

        /* Datos generales (etiqueta/valor) */
        .datos td { padding: 3px 5px; }
        .datos .lbl { width: 15%; font-weight: bold; color: #374151; background: #f3f4f6; }
        .datos .val { width: 35%; }

        /* Tablas de datos */
        .tbl th { background: #e5e7eb; border: 1px solid #9ca3af; padding: 4px 3px;
                  font-size: 7px; text-align: center; text-transform: uppercase; }
        .tbl td { border: 1px solid #c9ced6; padding: 3px 4px; font-size: 7.2px; }
        .tbl tbody tr:nth-child(even) td { background: #fafbfc; }

        .num { text-align: right; }
        .cen { text-align: center; }
        .total td { background: #eef1f4; font-weight: bold; }
        .grupo-head { background: #d1d5db; font-weight: bold; font-size: 7.5px; }

        .lista { margin: 0; padding: 4px 6px 4px 20px; font-size: 7.4px; }
        .lista li { margin-bottom: 2px; }

        .nota { border: 1px solid #c9ced6; padding: 5px 7px; font-size: 7.2px; }
        .nota p { margin: 0 0 3px; }

        .badge { display: inline-block; padding: 1px 6px; border-radius: 8px; color: #fff; font-size: 7px; }
        .aviso-os { background: #fff3cd; border: 1px solid #ffe69c; padding: 5px 7px; margin: 6px 0; font-size: 7.6px; }

        /* Firmas */
        .firmas { margin-top: 30px; width: 100%; border-collapse: collapse; }
        .firmas td { width: 25%; padding: 26px 6px 3px; border-top: 1px solid #1f2937;
                     text-align: center; font-size: 7px; vertical-align: top; }
    </style>
</head>
<body>

<header>
    <table class="hdr">
        <tr>
            <td class="logo">{{ $ficha->empresa->prefijo ?? 'MEDILASER' }}</td>
            <td class="titulo">
                <h1>FICHA TÉCNICA PRESTACIÓN DE SERVICIOS DE SALUD</h1>
                <div class="sub">
                    Sucursal: {{ strtoupper($ficha->sucursal->nombre ?? $ficha->sucursal_legacy ?? 'N/D') }}
                    &nbsp;·&nbsp; Fecha de Generación: {{ $generadoEn->format('Y-m-d, H:i:s') }}
                    &nbsp;·&nbsp; No de Ficha: {{ $ficha->consecutivo ?? 'BORRADOR-'.$ficha->id }}
                </div>
            </td>
            <td class="ctrl">
                <table>
                    <tr><td class="k">VERSIÓN</td><td class="k">VIGENCIA</td></tr>
                    <tr><td>11</td><td>MARZO 2022</td></tr>
                    <tr><td class="k">CÓDIGO</td><td class="k">PÁGINAS</td></tr>
                    <tr><td>F-GJ-145 MD</td><td><span class="pagenum"></span></td></tr>
                </table>
            </td>
        </tr>
    </table>
</header>

<footer>
    <table style="width:100%;">
        <tr>
            <td>Elaboró: {{ $ficha->generador->name ?? '—' }}</td>
            <td class="cen">{{ $ficha->empresa->nombre ?? 'Medilaser S.A.' }} · Ficha Técnica</td>
            <td style="text-align:right;">{{ $ficha->consecutivo ?? 'Borrador '.$ficha->id }}</td>
        </tr>
    </table>
</footer>

@if ($ficha->esActualizacion())
    <div class="aviso-os">
        <strong>ACTUALIZACIÓN (versión {{ $ficha->version }})</strong>
        de la ficha {{ $ficha->padre->consecutivo ?? '—' }}.
        <strong>Motivo:</strong> {{ $ficha->obs_os ?? 'No especificado' }}
    </div>
@endif

{{-- ══════════ 1. DATOS GENERALES ══════════ --}}
<div class="sec">1. Datos Generales</div>
<table class="grid datos">
    <tr>
        <td class="lbl">Nombre del Prestador</td>
        <td class="val">{{ $ficha->agremiacion->nombre ?? '—' }}</td>
        <td class="lbl">Nit</td>
        <td class="val">{{ $ficha->agremiacion->nit ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Representante Legal</td>
        <td class="val">{{ $ficha->agremiacion->rep_legal ?? '—' }}</td>
        <td class="lbl">Cédula del Representante</td>
        <td class="val">{{ $ficha->agremiacion->cc_rep_legal ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Dirección Prestador</td>
        <td class="val">{{ $ficha->agremiacion->direccion ?? '—' }}</td>
        <td class="lbl">Teléfono</td>
        <td class="val">{{ $ficha->agremiacion->telefono ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Fecha de Inicio</td>
        <td class="val">{{ $ficha->fecha_ini?->format('Y-m-d') ?? '—' }}</td>
        <td class="lbl">Fecha Fin</td>
        <td class="val">{{ $ficha->fecha_fin?->format('Y-m-d') ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Valor Estimado</td>
        <td class="val">${{ number_format((float) $ficha->vlr_contrato, 2, ',', '.') }}</td>
        <td class="lbl">Especialidad Contratada</td>
        <td class="val">{{ $ficha->especialidad->descripcion ?? '—' }}</td>
    </tr>
    <tr>
        <td class="lbl">Objeto del Contrato</td>
        <td colspan="3">{{ $ficha->objetoContrato->descripcion ?? '—' }}</td>
    </tr>
</table>

{{-- ══════════ 2. RELACIÓN DE PROFESIONALES ══════════ --}}
<div class="sec">2. Relación de Profesionales Prestadores de los Servicios</div>
<table class="tbl">
    <thead>
        <tr>
            <th style="width: 6%;">No.</th>
            <th>Nombre Completo</th>
            <th style="width: 22%;">Documento</th>
            <th style="width: 22%;">Tarjeta Profesional</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($ficha->profesionales as $i => $prof)
            <tr>
                <td class="cen">{{ $i + 1 }}</td>
                <td>{{ $prof->nombre }}</td>
                <td class="cen">{{ $prof->documento }}</td>
                <td class="cen">{{ $prof->tarjeta_profesional ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="cen">Sin profesionales vinculados</td></tr>
        @endforelse
    </tbody>
</table>

{{-- ══════════ 3. DESCRIPCIÓN DE SERVICIOS Y TARIFAS ══════════ --}}
<div class="sec">3. Descripción de Servicios y Tarifas Contratados</div>
<table class="tbl">
    <thead>
        <tr>
            <th style="width: 4%;">No.</th>
            <th style="width: 12%;">Servicio</th>
            <th>Descripción</th>
            <th style="width: 11%;">Forma Pago</th>
            <th style="width: 9%;">Homólogo</th>
            <th style="width: 7%;">Variación</th>
            <th style="width: 11%;">Valor</th>
            <th style="width: 18%;">Observación del Ítem</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($detalles as $i => $d)
            <tr>
                <td class="cen">{{ $i + 1 }}</td>
                <td>
                    @if ($d->tipo_liquidacion === 'CUPS')
                        CUPS
                    @else
                        {{ $d->tipo_servicio ?? $d->tipo_liquidacion ?? '—' }}
                    @endif
                </td>
                <td>
                    @if ($d->tipo_liquidacion === 'CUPS')
                        {{ $d->cups }} @if($d->cups_descripcion)- {{ $d->cups_descripcion }}@endif
                    @elseif ($d->tipo_liquidacion === 'GRUPO')
                        GRUPO {{ $d->grupo }} @if($d->grupo_descripcion)- {{ $d->grupo_descripcion }}@endif
                    @elseif ($d->tipo_liquidacion === 'SUBGRUPO')
                        SUBGRUPO {{ $d->subgrupo }} @if($d->subgrupo_descripcion)- {{ $d->subgrupo_descripcion }}@endif
                    @else
                        {{ $d->tipo_servicio ?? '—' }}
                    @endif
                </td>
                <td class="cen">{{ $d->forma_pago ?? '—' }}</td>
                <td class="cen">{{ $d->homologo ?? '—' }}</td>
                <td class="cen">
                    @if ($d->variacion !== null && $d->variacion !== '')
                        {{ (int) $d->variacion > 0 ? '+' : '' }}{{ $d->variacion }}%
                    @else
                        —
                    @endif
                </td>
                <td class="num">${{ number_format((float) $d->valor, 2, ',', '.') }}</td>
                <td>{{ $d->obs_item_descripcion ?? '' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="cen">Sin servicios registrados</td></tr>
        @endforelse
        <tr class="total">
            <td colspan="6" style="text-align:right;">TOTAL SERVICIOS</td>
            <td class="num">${{ number_format((float) $ficha->valor_total_detalles, 2, ',', '.') }}</td>
            <td></td>
        </tr>
    </tbody>
</table>

{{-- ══════════ 4. OBSERVACIONES GENERALES ══════════ --}}
<div class="sec">4. Observaciones Generales</div>
@if ($ficha->observaciones->isNotEmpty())
    <ol class="lista">
        @foreach ($ficha->observaciones as $obs)
            <li>{{ $obs->desc_obs }}</li>
        @endforeach
    </ol>
@else
    <div class="nota"><p>Sin observaciones generales registradas.</p></div>
@endif

{{-- ══════════ 5. PÓLIZA DE RIESGOS Y LINEAMIENTOS ══════════ --}}
<div class="sec">5. Póliza de Riesgos · Políticas y Lineamientos</div>
<div class="nota">
    <p><strong>Póliza de responsabilidad civil de clínicas y hospitales</strong> — Cuantía especialidad 500 SMLMV.</p>
    <p><strong>Póliza única de cumplimiento</strong> — Cuantía especialidad.</p>
    <ol class="lista">
        <li>No se permite el desarrollo de actividades simultáneas que generen doble facturación.</li>
        <li>No se reconocerán valores superiores a la tarifa cancelada por las EAPB o normatividad legal vigente.</li>
        <li>Se dará cumplimiento a estándares de calidad, puntualidad y oportunidad definidos por la institución.</li>
        <li>La formulación de tecnologías en salud no incluidas en el PBS deberá ajustarse a la normatividad.</li>
        <li>Las glosas generadas por las E.R.P y los mayores valores pagados serán descontados cuando sean inherentes a su actuar médico.</li>
        <li>En procedimientos en igual o diferente vía de acceso o acto, se aplicará el manual base pactado para dicha tarifa; en tarifas propias aplicará lo regulado en el manual ISS.</li>
    </ol>
</div>

{{-- ══════════ 6. CHEQUEO DOCUMENTAL ══════════ --}}
<div class="sec">6. Chequeo Documental</div>
<table class="tbl">
    <thead>
        <tr><th style="width: 8%;">Verif.</th><th>Descripción del Documento</th></tr>
    </thead>
    <tbody>
        @foreach ([
            'Certificado de existencia y representación legal y/o registro sindical (agremiaciones)',
            'Registro Único Tributario (RUT)',
            'Certificado de cuenta bancaria',
            'Cédula representante legal',
            'Formulario SARLAFT del contratista',
            'Oferta o propuesta de servicios',
            'F-TH-089 MD, Lista de chequeo de hojas de vida',
        ] as $doc)
            <tr>
                <td class="cen">☐</td>
                <td>{{ $doc }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

{{-- ══════════ 7. LEGALIZACIÓN - FIRMAS ══════════ --}}
<div class="sec">7. Legalización · Firmas de las Partes</div>
<table class="firmas">
    <tr>
        <td>
            Firma Contratista<br>
            <span style="font-size:6.5px;color:#6b7280;">{{ $ficha->agremiacion->rep_legal ?? '' }}</span>
        </td>
        <td>
            Firma Supervisor<br>
            <span style="font-size:6.5px;color:#6b7280;">{{ $ficha->generador->name ?? '' }}</span>
        </td>
        <td>
            VoBo Contratación<br>
            <span style="font-size:6.5px;color:#6b7280;">
                {{ $ficha->autorizador->name ?? 'Pendiente' }}
                @if($ficha->fecha_autoriza) · {{ $ficha->fecha_autoriza->format('d/m/Y') }} @endif
            </span>
        </td>
        <td>
            VoBo Vice. Financiera<br>
            <span style="font-size:6.5px;color:#6b7280;">
                {{ $ficha->aprobador->name ?? 'Pendiente' }}
                @if($ficha->fecha_aprueba) · {{ $ficha->fecha_aprueba->format('d/m/Y') }} @endif
            </span>
        </td>
    </tr>
</table>

<script type="text/php">
    if (isset($pdf)) {
        $pdf->page_script('
            $font = $fontMetrics->get_font("DejaVu Sans", "normal");
            $pdf->text(520, 760, "Pag " . $PAGE_NUM . " de " . $PAGE_COUNT, $font, 6.8, [0.42, 0.46, 0.49]);
        ');
    }
</script>

</body>
</html>
