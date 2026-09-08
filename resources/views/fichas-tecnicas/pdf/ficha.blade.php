{{--
    Plantilla del PDF de ficha técnica — "FICHA TÉCNICA PRESTACIÓN DE SERVICIOS DE SALUD".
    Réplica del maquetado institucional legacy (includes/ficha_pdf.php, formato F-GJ-145 MD),
    unificando includes/pdf.php, pdf_os.php, ficha_pdf.php y ficha_os_pdf.php.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ficha Técnica No. {{ $ficha->consecutivo ?? 'Borrador '.$ficha->id }}</title>
    <style>
        @page { margin: 24px 22px 40px 22px; }
        * { box-sizing: border-box; }
        body { font-family: Arial, 'DejaVu Sans', sans-serif; font-size: 8px; color: #000; margin: 0; }

        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        table, td, th { border: 1px solid #595959; border-collapse: collapse; font-size: 8px; }
        td, th { padding: 3px 4px; vertical-align: middle; }
        th { background: #d8d8ef; text-align: center; }
        thead { display: table-header-group; }

        .section-title { background-color: #f0f0f0; font-weight: bold; font-size: 10px;
                         padding: 5px; text-align: center; }
        .lbl { font-weight: normal; }
        .cen { text-align: center; }
        .num { text-align: right; }
        .left { text-align: left; }

        /* Encabezado */
        .hdr-logo { width: 130px; text-align: center; font-weight: bold; font-size: 13px; color: #b91c1c; }
        .hdr-tit  { text-align: center; font-weight: bold; font-size: 11px; }

        .aviso-os { background: #fff3cd; border: 1px solid #ffe69c; padding: 5px 7px;
                    margin: 4px 0; font-size: 8px; }
        .total td { background: #f0f0f0; font-weight: bold; }
        .nota-final { font-size: 7.5px; text-align: center; }
    </style>
</head>
<body>

{{-- ══════════ ENCABEZADO ══════════ --}}
<table>
    <thead>
        <tr>
            <td rowspan="4" class="hdr-logo">{{ $ficha->empresa->prefijo ?? 'MEDILASER' }}</td>
            <td rowspan="4" class="hdr-tit" style="width: 330px;">
                FICHA TÉCNICA PRESTACIÓN DE SERVICIOS DE SALUD
            </td>
            <td class="cen">VERSIÓN</td>
            <td class="cen">11</td>
        </tr>
        <tr>
            <td class="cen">VIGENCIA</td>
            <td class="cen">MARZO 2022</td>
        </tr>
        <tr>
            <td class="cen">CÓDIGO</td>
            <td class="cen">F-GJ-145 MD</td>
        </tr>
        <tr>
            <td class="cen">PÁGINAS</td>
            <td class="cen"><span class="pagenum"></span></td>
        </tr>
        <tr>
            <td colspan="4" class="cen">
                Sucursal: {{ strtoupper($ficha->sucursal->nombre ?? $ficha->sucursal_legacy ?? 'N/D') }} -
                Fecha de Generación: {{ $generadoEn->format('Y-m-d, H:i:s') }} -
                No de Ficha: <strong>{{ $ficha->consecutivo ?? 'BORRADOR-'.$ficha->id }}</strong>
            </td>
        </tr>
    </thead>
</table>

@if ($ficha->esActualizacion())
    <div class="aviso-os">
        <strong>ACTUALIZACIÓN (versión {{ $ficha->version }})</strong>
        de la ficha {{ $ficha->padre->consecutivo ?? '—' }}.
        <strong>Motivo:</strong> {{ $ficha->obs_os ?? 'No especificado' }}
    </div>
@endif

{{-- ══════════ 1. DATOS GENERALES ══════════ --}}
<table>
    <tbody>
        <tr><td colspan="4" class="section-title">1. DATOS GENERALES</td></tr>
        <tr>
            <td class="left" style="width:20%;">Nombre del Prestador:</td>
            <td class="left" style="width:30%;">{{ $ficha->agremiacion->nombre ?? '—' }}</td>
            <td class="left" style="width:20%;">Nit:</td>
            <td class="left" style="width:30%;">{{ $ficha->agremiacion->nit ?? '—' }}</td>
        </tr>
        <tr>
            <td class="left">Representante Legal:</td>
            <td class="left">{{ $ficha->agremiacion->rep_legal ?? '—' }}</td>
            <td class="left">Cédula del Representante:</td>
            <td class="left">{{ $ficha->agremiacion->cc_rep_legal ?? '—' }}</td>
        </tr>
        <tr>
            <td class="left">Dirección Prestador:</td>
            <td class="left">{{ $ficha->agremiacion->direccion ?? '—' }}</td>
            <td class="left">Teléfono:</td>
            <td class="left">{{ $ficha->agremiacion->telefono ?? '—' }}</td>
        </tr>
        <tr>
            <td class="left">Especialidad Contratada:</td>
            <td class="left">{{ $ficha->especialidad->descripcion ?? '—' }}</td>
            <td class="left">Valor Estimado:</td>
            <td class="left">${{ number_format((float) $ficha->vlr_contrato, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="left">Fecha de Inicio:</td>
            <td class="left">{{ $ficha->fecha_ini?->format('Y-m-d') ?? '—' }}</td>
            <td class="left">Fecha Fin:</td>
            <td class="left">{{ $ficha->fecha_fin?->format('Y-m-d') ?? '—' }}</td>
        </tr>
        <tr>
            <td class="left">Presentación Factura:</td>
            <td class="left">Último día hábil de cada mes</td>
            <td class="left">Forma de Pago:</td>
            <td class="left">{{ $ficha->formaPago->descripcion ?? '90 DÍAS HÁBILES' }}</td>
        </tr>
        <tr>
            <td class="left">Objeto del Contrato:</td>
            <td class="left" colspan="3">{{ $ficha->objetoContrato->descripcion ?? '—' }}</td>
        </tr>
    </tbody>
</table>

{{-- ══════════ 2. RELACIÓN DE PROFESIONALES ══════════ --}}
<table>
    <tbody>
        <tr><td colspan="4" class="section-title">2. RELACIÓN DE PROFESIONALES PRESTADORES DE LOS SERVICIOS</td></tr>
        <tr>
            <th colspan="2">NOMBRE COMPLETO</th>
            <th>DOCUMENTO</th>
            <th>TARJETA PROFESIONAL</th>
        </tr>
        @forelse ($ficha->profesionales as $prof)
            <tr>
                <td colspan="2" class="left">{{ $prof->nombre }}</td>
                <td class="cen">{{ $prof->documento }}</td>
                <td class="cen">{{ $prof->tarjeta_profesional ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="4" class="cen">Sin profesionales vinculados</td></tr>
        @endforelse
    </tbody>
</table>

{{-- ══════════ 3. DESCRIPCIÓN DE SERVICIOS Y TARIFAS ══════════ --}}
<table>
    <tr><td colspan="8" class="section-title">3. DESCRIPCIÓN DE SERVICIOS Y TARIFAS CONTRATADOS</td></tr>
    <tr>
        <th style="width:4%;">No.</th>
        <th style="width:12%;">Servicio</th>
        <th>Descripción</th>
        <th style="width:11%;">Forma Pago</th>
        <th style="width:9%;">Homólogo</th>
        <th style="width:7%;">Variación</th>
        <th style="width:11%;">Valor</th>
        <th style="width:18%;">Observación del Ítem</th>
    </tr>
    @forelse ($detalles as $i => $d)
        <tr>
            <td class="cen">{{ $i + 1 }}</td>
            <td class="left">{{ $d->tipo_liquidacion === 'CUPS' ? 'CUPS' : ($d->tipo_servicio ?? $d->tipo_liquidacion ?? '—') }}</td>
            <td class="left">
                @if ($d->tipo_liquidacion === 'CUPS')
                    {{ $d->cups }}@if($d->cups_descripcion) - {{ $d->cups_descripcion }}@endif
                @elseif ($d->tipo_liquidacion === 'GRUPO')
                    {{ $d->grupo }}@if($d->grupo_descripcion) - {{ $d->grupo_descripcion }}@endif
                @elseif ($d->tipo_liquidacion === 'SUBGRUPO')
                    {{ $d->subgrupo }}@if($d->subgrupo_descripcion) - {{ $d->subgrupo_descripcion }}@endif
                @else
                    {{ $d->tipo_servicio ?? '-' }}
                @endif
            </td>
            <td class="cen">{{ $d->forma_pago ?: 'VALOR FIJO' }}</td>
            <td class="cen">{{ $d->homologo ?: '-' }}</td>
            <td class="cen">
                @if ($d->variacion !== null && $d->variacion !== '')
                    {{ (int) $d->variacion > 0 ? '+' : '' }}{{ $d->variacion }}%
                @else
                    -
                @endif
            </td>
            <td class="num">${{ number_format((float) $d->valor, 2, ',', '.') }}</td>
            <td class="left">{{ $d->obs_item_descripcion ?: '-' }}</td>
        </tr>
    @empty
        <tr><td colspan="8" class="cen">Sin servicios registrados</td></tr>
    @endforelse
    <tr class="total">
        <td colspan="6" class="num">TOTAL SERVICIOS</td>
        <td class="num">${{ number_format((float) $ficha->valor_total_detalles, 2, ',', '.') }}</td>
        <td></td>
    </tr>
</table>

{{-- ══════════ 4. OBSERVACIONES GENERALES ══════════ --}}
<table>
    <tr><td colspan="2" class="section-title">4. OBSERVACIONES GENERALES</td></tr>
    @forelse ($ficha->observaciones as $i => $obs)
        <tr>
            <td class="cen" style="width:5%;">{{ $i + 1 }}</td>
            <td class="left">{{ $obs->desc_obs }}</td>
        </tr>
    @empty
        <tr><td colspan="2" class="cen">Sin observaciones generales registradas.</td></tr>
    @endforelse
</table>

{{-- ══════════ 5. PÓLIZA DE RIESGOS ══════════ --}}
<table>
    <tr><td colspan="4" class="section-title">5. PÓLIZA DE RIESGOS</td></tr>
    <tr>
        <td class="left" style="width:30%;">PÓLIZA DE RESPONSABILIDAD CIVIL DE CLÍNICAS Y HOSPITALES</td>
        <td class="left" colspan="3">{{ $polizaCuantia }}</td>
    </tr>
    <tr>
        <td class="left">PÓLIZA ÚNICA DE CUMPLIMIENTO</td>
        <td class="left" colspan="3">CUANTÍA ESPECIALIDAD</td>
    </tr>
    <tr>
        <td class="left" rowspan="6">POLÍTICAS Y LINEAMIENTOS</td>
        <td class="left" colspan="3">1. NO SE PERMITE EL DESARROLLO DE ACTIVIDADES SIMULTÁNEAS QUE GENEREN DOBLE FACTURACIÓN.</td>
    </tr>
    <tr><td class="left" colspan="3">2. NO SE RECONOCERÁN VALORES SUPERIORES A LA TARIFA CANCELADA POR LAS EAPB O NORMATIVIDAD LEGAL VIGENTE.</td></tr>
    <tr><td class="left" colspan="3">3. SE DARÁ CUMPLIMIENTO A ESTÁNDARES DE CALIDAD, PUNTUALIDAD Y OPORTUNIDAD DEFINIDOS POR LA INSTITUCIÓN.</td></tr>
    <tr><td class="left" colspan="3">4. LA FORMULACIÓN DE TECNOLOGÍAS EN SALUD NO INCLUIDAS EN EL PBS DEBERÁ ESTAR AJUSTADA A LO DEFINIDO POR LA NORMATIVIDAD.</td></tr>
    <tr><td class="left" colspan="3">5. LAS GLOSAS GENERADAS POR LAS E.R.P Y LOS MAYORES VALORES PAGADOS EN CUALQUIER MOMENTO SERÁN DESCONTADOS CUANDO SEAN INHERENTES A SU ACTUAR MÉDICO.</td></tr>
    <tr><td class="left" colspan="3">6. EN CASO DE QUE SE PACTEN TARIFAS EN LAS QUE SE REALICEN PROCEDIMIENTOS EN IGUAL O DIFERENTE VÍA DE ACCESO, EN IGUAL O DIFERENTE ACTO, SE APLICARÁ EL MANUAL BASE PACTADO PARA DICHA TARIFA; EN EL CASO DE TARIFAS PROPIAS APLICARÁ LO REGULADO EN EL MANUAL ISS.</td></tr>
</table>

{{-- ══════════ 6. CHEQUEO DOCUMENTAL ══════════ --}}
<table>
    <tr><td colspan="4" class="section-title">6. CHEQUEO DOCUMENTAL</td></tr>
    <tr>
        <th colspan="3">DESCRIPCIÓN DEL DOCUMENTO</th>
        <th>VERIFICACIÓN LEGAL</th>
    </tr>
    @foreach ([
        'CERTIFICADO DE EXISTENCIA Y REPRESENTACIÓN LEGAL Y/O REGISTRO SINDICAL (AGREMIACIONES)',
        'REGISTRO ÚNICO TRIBUTARIO (RUT)',
        'CERTIFICADO DE CUENTA BANCARIA',
        'CÉDULA REPRESENTANTE LEGAL',
        'FORMULARIO SARLAFT DEL CONTRATISTA',
        'OFERTA O PROPUESTA DE SERVICIOS',
        'F-TH-089 MD, LISTA DE CHEQUEO DE HOJAS DE VIDA',
    ] as $doc)
        <tr>
            <td colspan="3" class="left">{{ $doc }}</td>
            <td></td>
        </tr>
    @endforeach
</table>

{{-- ══════════ 7. LEGALIZACIÓN - FIRMAS ══════════ --}}
<table>
    <tr><td colspan="4" class="section-title">7. LEGALIZACIÓN - FIRMAS DE LAS PARTES</td></tr>
    <tr>
        <td class="cen" style="height:40px;"></td>
        <td class="cen" style="height:40px;"></td>
        <td class="cen" style="height:40px;">
            {{ $ficha->fecha_autoriza ? 'Autorizada' : 'No Firmada' }}
        </td>
        <td class="cen" style="height:40px;">
            {{ $ficha->fecha_aprueba ? 'Aprobada' : 'No Firmada' }}
        </td>
    </tr>
    <tr>
        <td class="cen">Firma Contratista</td>
        <td class="cen">Firma Supervisor</td>
        <td class="cen">VoBo Contratación</td>
        <td class="cen">VoBo Vice Financiera</td>
    </tr>
    <tr><td colspan="4" class="cen">Elaboró: {{ $ficha->generador->name ?? '—' }}</td></tr>
</table>

<script type="text/php">
    if (isset($pdf)) {
        $font = $fontMetrics->get_font("Arial, Helvetica, sans-serif", "normal");
        $pdf->text(270, 780, "Pag " . $PAGE_NUM . " de " . $PAGE_COUNT, $font, 8);
    }
</script>

</body>
</html>
