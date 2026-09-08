{{--
    PDF de la ficha técnica — "FICHA TÉCNICA PRESTACIÓN DE SERVICIOS DE SALUD".
    Maquetado institucional F-GJ-145 MD, réplica del legacy includes/ficha_pdf.php.

    Notas de implementación (DomPDF):
      · table-layout: fixed  → respeta los anchos declarados y evita que una
        columna "estire" la tabla (problema típico de DomPDF con texto largo).
      · word-wrap/-break     → parte descripciones CUPS largas dentro de la celda.
      · thead como table-header-group → repite encabezados al saltar de página.
      · Fuente DejaVu Sans   → única con soporte Unicode completo (tildes/Ñ).
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ficha Técnica No. {{ $ficha->consecutivo ?? 'Borrador '.$ficha->id }}</title>
    <style>
        @page { margin: 20px 20px 34px 20px; }
        * { box-sizing: border-box; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 7.2px;
            line-height: 1.25;
            color: #000;
            margin: 0;
        }

        table {
            width: 100%;
            table-layout: fixed;
            border-collapse: collapse;
            margin: 0 0 3px;
        }
        table, td, th { border: 0.75px solid #595959; }
        td, th {
            padding: 2.5px 4px;
            vertical-align: middle;
            word-wrap: break-word;
            overflow-wrap: break-word;
        }
        th {
            background: #d8d8ef;
            text-align: center;
            font-weight: bold;
            font-size: 6.8px;
        }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }

        /* Título de sección: fondo gris claro, centrado, negrita */
        .sec {
            background-color: #f0f0f0;
            font-weight: bold;
            font-size: 9px;
            padding: 4px;
            text-align: center;
        }

        .cen   { text-align: center; }
        .num   { text-align: right; }
        .left  { text-align: left; }
        .total td { background: #f0f0f0; font-weight: bold; }

        /* Encabezado */
        .hdr-logo  { text-align: center; padding: 4px; }
        .hdr-logo img { width: 118px; height: auto; }
        .hdr-marca { font-weight: bold; font-size: 11px; color: #b91c1c; letter-spacing: .5px; }
        /* 8.6px en DejaVu Sans Bold cabe en una línea dentro del 54% del ancho.
           DejaVu es más ancha que la Arial del legacy, de ahí el tamaño menor. */
        .hdr-tit   { text-align: center; font-weight: bold; font-size: 8.6px; }

        /* Recuadro de control anidado: sin borde exterior ni padding propio
           para que sus filas queden a ras de la celda contenedora. */
        .hdr-ctrl-wrap { padding: 0; }
        .hdr-ctrl-tbl  { margin: 0; border: 0; }
        .hdr-ctrl-tbl td {
            font-size: 6.5px;
            padding: 2px 3px;
            border-top: 0;
            border-right: 0;
            white-space: nowrap;
        }
        .hdr-ctrl-tbl tr:last-child td { border-bottom: 0; }
        .hdr-ctrl-tbl tr td:first-child { border-left: 0; font-weight: bold; }

        /* Etiquetas de datos generales */
        .lbl { background: #fff; }

        .aviso-os {
            background: #fff3cd; border: 0.75px solid #ffe69c;
            padding: 4px 6px; margin: 0 0 3px; font-size: 7.2px;
        }

        /* Firmas: espacio para rúbrica + rótulo */
        .firma-espacio { height: 44px; vertical-align: bottom; }
        .firma-digital {
            display: inline-block;
            font-size: 6.3px;
            font-style: italic;
            color: #1d4ed8;
            border-top: 0.75px solid #1d4ed8;
            padding-top: 1px;
        }
        .firma-rotulo td { font-weight: bold; font-size: 6.8px; }
        .firma-nombre td { font-size: 6.3px; color: #444; }
    </style>
</head>
<body>

{{-- ══════════════════ ENCABEZADO ══════════════════ --}}
{{--
    Se usa una sola fila con 3 celdas y una tabla anidada para el recuadro de
    control. Evita `rowspan`, que en DomPDF distorsiona el cálculo de anchos y
    hacía que el título se partiera en dos líneas.
--}}
<table>
    <tbody>
        <tr>
            <td class="hdr-logo" width="20%">
                @if ($logoEmpresa)
                    <img src="{{ $logoEmpresa }}" alt="Logo">
                @else
                    <span class="hdr-marca">{{ $ficha->empresa->nombre ?? 'MEDILASER' }}</span>
                @endif
            </td>
            <td class="hdr-tit" width="53%">FICHA TÉCNICA PRESTACIÓN DE SERVICIOS DE SALUD</td>
            <td class="hdr-ctrl-wrap" width="27%">
                <table class="hdr-ctrl-tbl">
                    <tbody>
                        <tr><td class="cen" width="48%">VERSIÓN</td><td class="cen" width="52%">11</td></tr>
                        <tr><td class="cen">VIGENCIA</td><td class="cen">MARZO 2022</td></tr>
                        <tr><td class="cen">CÓDIGO</td><td class="cen">F-GJ-145 MD</td></tr>
                        <tr><td class="cen">PÁGINAS</td><td class="cen"><span class="pagenum"></span></td></tr>
                    </tbody>
                </table>
            </td>
        </tr>
        <tr>
            <td colspan="3" class="cen">
                @php
                    $sucLabel = match ($ficha->tipo_alcance) {
                        'nacional' => 'NACIONAL',
                        'sede'     => strtoupper($ficha->sedes->pluck('nombre')->implode(', ')),
                        default    => strtoupper(
                            $ficha->sucursales->pluck('nombre')->implode(', ')
                            ?: ($ficha->sucursal->nombre ?? $ficha->sucursal_legacy ?? 'N/D')
                        ),
                    };
                    $sucLabel = $sucLabel !== '' ? $sucLabel : 'N/D';
                @endphp
                Sucursal: {{ $sucLabel }} -
                Fecha de Generación: {{ $generadoEn->format('Y-m-d, H:i:s') }} -
                No de Ficha: <strong>{{ $ficha->consecutivo ?? 'BORRADOR-'.$ficha->id }}</strong>
            </td>
        </tr>
    </tbody>
</table>

@if ($ficha->esActualizacion())
    <div class="aviso-os">
        <strong>ACTUALIZACIÓN (versión {{ $ficha->version }})</strong>
        de la ficha {{ $ficha->padre->consecutivo ?? '—' }}.
        <strong>Motivo:</strong> {{ $ficha->obs_os ?? 'No especificado' }}
    </div>
@endif

{{-- ══════════════════ 1. DATOS GENERALES ══════════════════ --}}
<table>
    <tbody>
        <tr><td colspan="4" class="sec">1. DATOS GENERALES</td></tr>
        <tr>
            <td class="left lbl" width="19%">Nombre del Prestador:</td>
            <td class="left" width="31%">{{ $ficha->agremiacion->nombre ?? '—' }}</td>
            <td class="left lbl" width="19%">Nit:</td>
            <td class="left" width="31%">{{ $ficha->agremiacion->nit ?? '—' }}</td>
        </tr>
        <tr>
            <td class="left lbl">Representante Legal:</td>
            <td class="left">{{ $ficha->agremiacion->rep_legal ?? '—' }}</td>
            <td class="left lbl">Cédula del Representante:</td>
            <td class="left">{{ $ficha->agremiacion->cc_rep_legal ?? '—' }}</td>
        </tr>
        <tr>
            <td class="left lbl">Dirección Prestador:</td>
            <td class="left">{{ $ficha->agremiacion->direccion ?? '—' }}</td>
            <td class="left lbl">Teléfono:</td>
            <td class="left">{{ $ficha->agremiacion->telefono ?? '—' }}</td>
        </tr>
        <tr>
            <td class="left lbl">Especialidad Contratada:</td>
            <td class="left">{{ $ficha->especialidad->descripcion ?? '—' }}</td>
            <td class="left lbl">Valor Estimado:</td>
            <td class="left">${{ number_format((float) $ficha->vlr_contrato, 2, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="left lbl">Fecha de Inicio:</td>
            <td class="left">{{ $ficha->fecha_ini?->format('Y-m-d') ?? '—' }}</td>
            <td class="left lbl">Fecha Fin:</td>
            <td class="left">{{ $ficha->fecha_fin?->format('Y-m-d') ?? '—' }}</td>
        </tr>
        <tr>
            <td class="left lbl">Presentación Factura:</td>
            <td class="left">Último día hábil de cada mes</td>
            <td class="left lbl">Forma de Pago:</td>
            <td class="left">{{ $ficha->formaPago->descripcion ?? '90 DÍAS HÁBILES' }}</td>
        </tr>
        <tr>
            <td class="left lbl">Alcance:</td>
            <td class="left" colspan="3">
                @switch ($ficha->tipo_alcance)
                    @case ('nacional') Nacional (todas las sucursales) @break
                    @case ('sede')
                        Sedes: {{ $ficha->sedes->pluck('nombre')->implode(', ') ?: '—' }}
                        @break
                    @default
                        Sucursales: {{ $ficha->sucursales->pluck('nombre')->implode(', ') ?: '—' }}
                @endswitch
            </td>
        </tr>
        <tr>
            <td class="left lbl">Objeto del Contrato:</td>
            <td class="left" colspan="3">{{ $ficha->objetoContrato->descripcion ?? '—' }}</td>
        </tr>
    </tbody>
</table>

{{-- ══════════════════ 2. RELACIÓN DE PROFESIONALES ══════════════════ --}}
<table>
    <tbody>
        <tr><td colspan="3" class="sec">2. RELACIÓN DE PROFESIONALES PRESTADORES DE LOS SERVICIOS</td></tr>
        <tr>
            <th width="50%">NOMBRE COMPLETO</th>
            <th width="25%">DOCUMENTO</th>
            <th width="25%">TARJETA PROFESIONAL</th>
        </tr>
        @forelse ($ficha->profesionales as $prof)
            <tr>
                <td class="left">{{ $prof->nombre }}</td>
                <td class="cen">{{ $prof->documento }}</td>
                <td class="cen">{{ $prof->tarjeta_profesional ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="cen">Sin profesionales vinculados</td></tr>
        @endforelse
    </tbody>
</table>

{{-- ══════════════════ 3. SERVICIOS Y TARIFAS ══════════════════ --}}
<table>
    <thead>
        <tr><td colspan="8" class="sec">3. DESCRIPCIÓN DE SERVICIOS Y TARIFAS CONTRATADOS</td></tr>
        <tr>
            <th width="4%">No.</th>
            <th width="13%">Servicio</th>
            <th width="29%">Descripción</th>
            <th width="9%">Forma Pago</th>
            <th width="8%">Homólogo</th>
            <th width="7%">Variación</th>
            <th width="10%">Valor</th>
            <th width="20%">Observación del Ítem</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($detalles as $i => $d)
            <tr>
                <td class="cen">{{ $i + 1 }}</td>
                <td class="left">{{ $d->tipo_liquidacion === 'CUPS' ? 'CUPS' : ($d->tipo_servicio ?: ($d->tipo_liquidacion ?: '-')) }}</td>
                <td class="left">
                    @if ($d->tipo_liquidacion === 'CUPS')
                        {{ $d->cups }}@if($d->cups_descripcion) - {{ $d->cups_descripcion }}@endif
                    @elseif ($d->tipo_liquidacion === 'GRUPO')
                        {{ $d->grupo }}@if($d->grupo_descripcion) - {{ $d->grupo_descripcion }}@endif
                    @elseif ($d->tipo_liquidacion === 'SUBGRUPO')
                        {{ $d->subgrupo }}@if($d->subgrupo_descripcion) - {{ $d->subgrupo_descripcion }}@endif
                    @else
                        {{ $d->tipo_servicio ?: '-' }}
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
    </tbody>
</table>

{{-- ══════════════════ 4. OBSERVACIONES GENERALES ══════════════════ --}}
<table>
    <tbody>
        <tr><td colspan="2" class="sec">4. OBSERVACIONES GENERALES</td></tr>
        @forelse ($ficha->observaciones as $i => $obs)
            <tr>
                <td class="cen" width="5%">{{ $i + 1 }}</td>
                <td class="left" width="95%">{{ $obs->desc_obs }}</td>
            </tr>
        @empty
            <tr><td colspan="2" class="cen">Sin observaciones generales registradas.</td></tr>
        @endforelse
    </tbody>
</table>

{{-- ══════════════════ 5. PÓLIZA DE RIESGOS ══════════════════ --}}
<table>
    <tbody>
        <tr><td colspan="2" class="sec">5. PÓLIZA DE RIESGOS</td></tr>
        <tr>
            <td class="left" width="30%">PÓLIZA DE RESPONSABILIDAD CIVIL DE CLÍNICAS Y HOSPITALES</td>
            <td class="left" width="70%">{{ $polizaCuantia }}</td>
        </tr>
        <tr>
            <td class="left">PÓLIZA ÚNICA DE CUMPLIMIENTO</td>
            <td class="left">CUANTÍA ESPECIALIDAD</td>
        </tr>
        <tr>
            <td class="left" rowspan="6">POLÍTICAS Y LINEAMIENTOS</td>
            <td class="left">1. NO SE PERMITE EL DESARROLLO DE ACTIVIDADES SIMULTÁNEAS QUE GENEREN DOBLE FACTURACIÓN.</td>
        </tr>
        <tr><td class="left">2. NO SE RECONOCERÁN VALORES SUPERIORES A LA TARIFA CANCELADA POR LAS EAPB O NORMATIVIDAD LEGAL VIGENTE.</td></tr>
        <tr><td class="left">3. SE DARÁ CUMPLIMIENTO A ESTÁNDARES DE CALIDAD, PUNTUALIDAD Y OPORTUNIDAD DEFINIDOS POR LA INSTITUCIÓN.</td></tr>
        <tr><td class="left">4. LA FORMULACIÓN DE TECNOLOGÍAS EN SALUD NO INCLUIDAS EN EL PBS DEBERÁ ESTAR AJUSTADA A LO DEFINIDO POR LA NORMATIVIDAD.</td></tr>
        <tr><td class="left">5. LAS GLOSAS GENERADAS POR LAS E.R.P Y LOS MAYORES VALORES PAGADOS EN CUALQUIER MOMENTO SERÁN DESCONTADOS CUANDO SEAN INHERENTES A SU ACTUAR MÉDICO.</td></tr>
        <tr><td class="left">6. EN CASO DE QUE SE PACTEN TARIFAS EN LAS QUE SE REALICEN PROCEDIMIENTOS EN IGUAL O DIFERENTE VÍA DE ACCESO, EN IGUAL O DIFERENTE ACTO, SE APLICARÁ EL MANUAL BASE PACTADO PARA DICHA TARIFA; EN EL CASO DE TARIFAS PROPIAS APLICARÁ LO REGULADO EN EL MANUAL ISS.</td></tr>
    </tbody>
</table>

{{-- ══════════════════ 6. CHEQUEO DOCUMENTAL ══════════════════ --}}
<table>
    <tbody>
        <tr><td colspan="2" class="sec">6. CHEQUEO DOCUMENTAL</td></tr>
        <tr>
            <th width="78%">DESCRIPCIÓN DEL DOCUMENTO</th>
            <th width="22%">VERIFICACIÓN LEGAL</th>
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
                <td class="left">{{ $doc }}</td>
                <td></td>
            </tr>
        @endforeach
    </tbody>
</table>

{{-- ══════════════════ 7. LEGALIZACIÓN - FIRMAS ══════════════════ --}}
<table>
    <tbody>
        <tr><td colspan="4" class="sec">7. LEGALIZACIÓN - FIRMAS DE LAS PARTES</td></tr>
        <tr>
            {{-- Contratista: espacio para firma manuscrita/escaneada --}}
            <td class="cen firma-espacio" width="25%"></td>
            {{-- Supervisor: quien elabora --}}
            <td class="cen firma-espacio" width="25%"></td>
            {{-- VoBo Contratación (autorizador / Dirección Médica) --}}
            <td class="cen firma-espacio" width="25%">
                @if ($ficha->fecha_autoriza)
                    <span class="firma-digital">Firmado digitalmente</span>
                @endif
            </td>
            {{-- VoBo Vicepresidencia Financiera (aprobador) --}}
            <td class="cen firma-espacio" width="25%">
                @if ($ficha->fecha_aprueba)
                    <span class="firma-digital">Firmado digitalmente</span>
                @endif
            </td>
        </tr>
        <tr class="firma-rotulo">
            <td class="cen">Firma Contratista</td>
            <td class="cen">Firma Supervisor</td>
            <td class="cen">VoBo Contratación</td>
            <td class="cen">VoBo Vice. Financiera</td>
        </tr>
        <tr class="firma-nombre">
            <td class="cen">{{ $ficha->agremiacion->rep_legal ?: '—' }}</td>
            <td class="cen">{{ $ficha->generador->name ?? '—' }}</td>
            <td class="cen">
                {{ $ficha->autorizador->name ?? 'Pendiente' }}
                @if($ficha->fecha_autoriza)<br>{{ $ficha->fecha_autoriza->format('d/m/Y H:i') }}@endif
            </td>
            <td class="cen">
                {{ $ficha->aprobador->name ?? 'Pendiente' }}
                @if($ficha->fecha_aprueba)<br>{{ $ficha->fecha_aprueba->format('d/m/Y H:i') }}@endif
            </td>
        </tr>
        <tr><td colspan="4" class="cen">Elaboró: {{ $ficha->generador->name ?? '—' }}</td></tr>
    </tbody>
</table>

<script type="text/php">
    if (isset($pdf)) {
        $font = $fontMetrics->get_font("DejaVu Sans", "normal");
        $texto = "Pág " . $PAGE_NUM . " de " . $PAGE_COUNT;
        $ancho = $fontMetrics->getTextWidth($texto, $font, 7);
        $pdf->text(($pdf->get_width() - $ancho) / 2, $pdf->get_height() - 24, $texto, $font, 7, [0.35, 0.35, 0.35]);
    }
</script>

</body>
</html>
