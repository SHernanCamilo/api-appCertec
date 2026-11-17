<?php

declare(strict_types=1);

namespace App\Services\MesaServicio\Teams;

use Carbon\Carbon;
use Throwable;

class GlpiTeamsMensaje
{
    public const URL_TABLERO = 'https://jade.medilaser.com.co/plataform/mesaServicio/tableros/tic';

    private const MAX_GRUPOS = 40;

    private const MAX_TICKETS = 20;

    /**
     * @param  array<string, mixed>  $tablero
     * @param  array{nombre?: string, modo?: string, grupos?: list<string>|null}  $config
     * @return array{titulo: string, texto: string, html: string, adaptive: array<string, mixed>, casos: int}
     */
    public function construir(array $tablero, array $config): array
    {
        $modo = ($config['modo'] ?? 'resumen') === 'detalle' ? 'detalle' : 'resumen';
        $filtros = $this->filtros($config['grupos'] ?? []);
        $tickets = $this->filtrar($tablero['tickets'] ?? [], $filtros);
        $nombre = trim((string) ($config['nombre'] ?? ''));
        if ($nombre === '') {
            $nombre = 'Destino Teams';
        }
        $cuando = $this->cuando($tablero['generado_en'] ?? null);
        $origen = trim((string) ($tablero['grupo']['nombre'] ?? 'Tablero TIC'));
        if ($origen === '') {
            $origen = 'Tablero TIC';
        }

        if ($modo === 'detalle') {
            return $this->detalle($nombre, $origen, $cuando, $tickets, $filtros);
        }

        return $this->resumen($nombre, $origen, $cuando, $tickets);
    }

    /**
     * @param  list<array<string, mixed>>  $tickets
     * @param  list<string>  $filtros
     * @return array{titulo: string, texto: string, html: string, adaptive: array<string, mixed>, casos: int}
     */
    private function detalle(string $nombre, string $origen, string $cuando, array $tickets, array $filtros): array
    {
        $conteo = $this->conteo($tickets);
        $casos = count($tickets);
        $alcance = $filtros === [] ? 'Grupos: todos' : 'Grupos: '.implode(', ', $filtros);
        $lineas = [
            $cuando.' · '.$origen,
            $alcance,
            $this->frase($conteo),
        ];

        if ($casos === 0) {
            $lineas[] = 'Sin casos abiertos para este destino.';
        }

        $visibles = array_slice($tickets, 0, self::MAX_TICKETS);
        foreach ($visibles as $ticket) {
            $id = (int) ($ticket['id'] ?? 0);
            $titulo = trim((string) ($ticket['titulo'] ?? 'Sin título'));
            $lineas[] = '#'.$id.' '.$titulo;
            $alerta = (string) ($ticket['alerta'] ?? '');
            $tiempo = trim((string) ($ticket['tiempo_texto'] ?? ''));
            if ($alerta === 'sin_ans' && strcasecmp($tiempo, 'Sin ANS') === 0) {
                $tiempo = '';
            }
            $meta = array_values(array_filter([
                trim((string) ($ticket['tecnico'] ?? '')) ?: 'Sin asignar',
                $this->etiquetaAlerta($alerta),
                $tiempo,
                trim((string) ($ticket['prioridad'] ?? '')),
                trim((string) ($ticket['entidad_corta'] ?? '')),
            ], static fn (string $parte): bool => $parte !== ''));
            if ($meta !== []) {
                $lineas[] = implode(' · ', $meta);
            }
            $url = trim((string) ($ticket['url'] ?? ''));
            if ($url !== '') {
                $lineas[] = $url;
            }
        }

        $resto = $casos - count($visibles);
        if ($resto > 0) {
            $lineas[] = 'y '.$resto.' casos más.';
        }

        $titulo = $nombre.' · '.($casos === 1 ? '1 caso' : $casos.' casos');

        return $this->empaquetar($titulo, $lineas, $casos);
    }

    /**
     * @param  array<string, mixed>  $tablero
     * @param  array{nombre?: string, modo?: string, grupos?: list<string>|null}  $config
     * @return list<array<string, mixed>>
     */
    public function casos(array $tablero, array $config): array
    {
        return $this->filtrar($tablero['tickets'] ?? [], $this->filtros($config['grupos'] ?? []));
    }

    /**
     * @param  list<array<string, mixed>>  $tickets
     * @return array{titulo: string, texto: string, html: string, adaptive: array<string, mixed>, casos: int}
     */
    private function resumen(string $nombre, string $origen, string $cuando, array $tickets): array
    {
        $grupos = $this->porGrupo($tickets);
        $conteo = $this->conteo($tickets);
        $visibles = array_slice($grupos, 0, self::MAX_GRUPOS);
        $resto = count($grupos) - count($visibles);
        $titulo = 'Tablero TIC — '.$nombre;

        return [
            'titulo' => $titulo,
            'texto' => $this->textoTabla($cuando, $origen, $conteo, $visibles, $resto),
            'html' => $this->htmlTabla($titulo, $cuando, $origen, $conteo, $visibles, $resto),
            'adaptive' => $this->adaptiveTabla($titulo, $cuando, $origen, $conteo, $visibles, $resto),
            'casos' => count($tickets),
        ];
    }

    /**
     * @param  list<string>  $lineas
     * @return array{titulo: string, texto: string, html: string, adaptive: array<string, mixed>, casos: int}
     */
    private function empaquetar(string $titulo, array $lineas, int $casos): array
    {
        $bloques = [[
            'type' => 'TextBlock',
            'text' => $titulo,
            'weight' => 'Bolder',
            'size' => 'Medium',
            'wrap' => true,
        ]];
        $html = '<p><b>'.$this->escapar($titulo).'</b></p>';

        foreach ($lineas as $linea) {
            $bloques[] = [
                'type' => 'TextBlock',
                'text' => $linea,
                'wrap' => true,
                'spacing' => 'Small',
            ];
            $html .= '<div>'.$this->escapar($linea).'</div>';
        }

        $html .= $this->botonTablero();

        return [
            'titulo' => $titulo,
            'texto' => implode("\n", $lineas)."\n\n".$this->textoTablero(),
            'html' => $html,
            'adaptive' => $this->tarjeta($bloques),
            'casos' => $casos,
        ];
    }

    /**
     * @param  array{total: int, sin_asignar: int, en_tiempo: int, por_vencer: int, vencidos: int, sin_ans: int}  $conteo
     * @param  list<array{nombre: string, nivel: int, total: int, sin_asignar: int, en_tiempo: int, por_vencer: int, vencidos: int, sin_ans: int}>  $grupos
     */
    private function textoTabla(string $cuando, string $origen, array $conteo, array $grupos, int $resto): string
    {
        $lineas = [
            $cuando.' · '.$origen,
            $this->textoTablero(),
            'Total '.$conteo['total'].' · En tiempo '.$conteo['en_tiempo'].' · Por vencer '.$conteo['por_vencer'].' · Vencidos '.$conteo['vencidos'],
            'Grupo | En tiempo | Por vencer | Vencidos | Total',
        ];

        if ($grupos === []) {
            $lineas[] = 'Sin casos abiertos.';

            return implode("\n", $lineas);
        }

        foreach ($grupos as $grupo) {
            $lineas[] = $this->nombreGrupo($grupo)
                .' | '.$grupo['en_tiempo']
                .' | '.$grupo['por_vencer']
                .' | '.$grupo['vencidos']
                .' | '.$grupo['total'];
        }

        if ($resto > 0) {
            $lineas[] = 'y '.$resto.' grupos más.';
        }

        return implode("\n", $lineas);
    }

    /**
     * @param  array{total: int, sin_asignar: int, en_tiempo: int, por_vencer: int, vencidos: int, sin_ans: int}  $conteo
     * @param  list<array{nombre: string, nivel: int, total: int, sin_asignar: int, en_tiempo: int, por_vencer: int, vencidos: int, sin_ans: int}>  $grupos
     */
    private function htmlTabla(string $titulo, string $cuando, string $origen, array $conteo, array $grupos, int $resto): string
    {
        $filas = '';
        foreach ($grupos as $indice => $grupo) {
            $fondo = $indice % 2 === 0 ? '#ffffff' : '#f8f9fa';
            $filas .= '<tr style="background:'.$fondo.';">'
                .'<td style="padding:8px 10px;border-bottom:1px solid #dee2e6;">'.$this->escapar($this->nombreGrupo($grupo)).'</td>'
                .$this->celdaNumero((int) $grupo['en_tiempo'], '#198754')
                .$this->celdaNumero((int) $grupo['por_vencer'], '#d97706')
                .$this->celdaNumero((int) $grupo['vencidos'], '#dc3545')
                .$this->celdaNumero((int) $grupo['total'], '#0b5ed7')
                .'</tr>';
        }

        if ($filas === '') {
            $filas = '<tr><td colspan="5" style="padding:12px;color:#6c757d;">Sin casos abiertos.</td></tr>';
        } elseif ($resto > 0) {
            $filas .= '<tr><td colspan="5" style="padding:8px 10px;color:#6c757d;">y '.$resto.' grupos más.</td></tr>';
        }

        return '<div style="font-family:Segoe UI,sans-serif;max-width:750px;">'
            .'<div style="background:#1a1a2e;color:#ffffff;padding:16px 20px;border-radius:10px 10px 0 0;">'
            .'<div style="font-size:18px;font-weight:700;">'.$this->escapar($titulo).'</div>'
            .'<div style="margin-top:6px;opacity:0.85;font-size:13px;">'.$this->escapar($cuando.' · '.$origen).'</div>'
            .'</div>'
            .'<div style="background:#f8f9fa;padding:14px 20px;border-left:4px solid #1a1a2e;font-size:14px;">'
            .'<strong>Total:</strong> '.(int) $conteo['total']
            .' &nbsp;|&nbsp; <strong style="color:#198754;">En tiempo:</strong> '.(int) $conteo['en_tiempo']
            .' &nbsp;|&nbsp; <strong style="color:#d97706;">Por vencer:</strong> '.(int) $conteo['por_vencer']
            .' &nbsp;|&nbsp; <strong style="color:#dc3545;">Vencidos:</strong> '.(int) $conteo['vencidos']
            .'</div>'
            .$this->botonTablero()
            .'<div style="padding:16px 20px;">'
            .'<table style="width:100%;border-collapse:collapse;font-size:13px;">'
            .'<thead><tr>'
            .'<th style="padding:10px;text-align:left;color:#0b5ed7;border-bottom:2px solid #0b5ed7;">Grupo</th>'
            .'<th style="padding:10px;text-align:center;color:#198754;border-bottom:2px solid #198754;">En tiempo</th>'
            .'<th style="padding:10px;text-align:center;color:#d97706;border-bottom:2px solid #d97706;">Por vencer</th>'
            .'<th style="padding:10px;text-align:center;color:#dc3545;border-bottom:2px solid #dc3545;">Vencidos</th>'
            .'<th style="padding:10px;text-align:center;color:#0b5ed7;border-bottom:2px solid #0b5ed7;">Total</th>'
            .'</tr></thead><tbody>'.$filas.'</tbody></table></div>'
            .'<div style="padding:12px;border-radius:0 0 10px 10px;text-align:center;font-size:12px;color:#6c757d;">'
            .'Mesa de Servicio — Tablero TIC<br>Mensaje automático generado por el sistema'
            .'</div></div>';
    }

    /**
     * @param  array{total: int, sin_asignar: int, en_tiempo: int, por_vencer: int, vencidos: int, sin_ans: int}  $conteo
     * @param  list<array{nombre: string, nivel: int, total: int, sin_asignar: int, en_tiempo: int, por_vencer: int, vencidos: int, sin_ans: int}>  $grupos
     * @return array<string, mixed>
     */
    private function adaptiveTabla(string $titulo, string $cuando, string $origen, array $conteo, array $grupos, int $resto): array
    {
        $bloques = [
            [
                'type' => 'TextBlock',
                'text' => $titulo,
                'weight' => 'Bolder',
                'size' => 'Medium',
                'wrap' => true,
            ],
            [
                'type' => 'TextBlock',
                'text' => $cuando.' · '.$origen,
                'isSubtle' => true,
                'wrap' => true,
                'spacing' => 'None',
            ],
            [
                'type' => 'TextBlock',
                'text' => 'Total '.$conteo['total'].' · En tiempo '.$conteo['en_tiempo'].' · Por vencer '.$conteo['por_vencer'].' · Vencidos '.$conteo['vencidos'],
                'wrap' => true,
            ],
            $this->filaAdaptive('Grupo', 'En tiempo', 'Por vencer', 'Vencidos', 'Total', true),
        ];

        if ($grupos === []) {
            $bloques[] = [
                'type' => 'TextBlock',
                'text' => 'Sin casos abiertos.',
                'isSubtle' => true,
                'wrap' => true,
            ];
        }

        foreach ($grupos as $grupo) {
            $bloques[] = $this->filaAdaptive(
                $this->nombreGrupo($grupo),
                (string) $grupo['en_tiempo'],
                (string) $grupo['por_vencer'],
                (string) $grupo['vencidos'],
                (string) $grupo['total'],
                false
            );
        }

        if ($resto > 0) {
            $bloques[] = [
                'type' => 'TextBlock',
                'text' => 'y '.$resto.' grupos más.',
                'isSubtle' => true,
                'wrap' => true,
            ];
        }

        return $this->tarjeta($bloques);
    }

    /**
     * @return array<string, mixed>
     */
    private function filaAdaptive(string $grupo, string $enTiempo, string $porVencer, string $vencidos, string $total, bool $encabezado): array
    {
        $peso = $encabezado ? 'Bolder' : 'Default';

        return [
            'type' => 'ColumnSet',
            'spacing' => 'Small',
            'columns' => [
                $this->columnaAdaptive($grupo, 'stretch', $peso, 'Left'),
                $this->columnaAdaptive($enTiempo, 'auto', $peso, 'Center'),
                $this->columnaAdaptive($porVencer, 'auto', $peso, 'Center'),
                $this->columnaAdaptive($vencidos, 'auto', $peso, 'Center'),
                $this->columnaAdaptive($total, 'auto', $peso, 'Center'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function columnaAdaptive(string $texto, string $ancho, string $peso, string $alineacion): array
    {
        return [
            'type' => 'Column',
            'width' => $ancho,
            'items' => [[
                'type' => 'TextBlock',
                'text' => $texto,
                'weight' => $peso,
                'wrap' => true,
                'horizontalAlignment' => $alineacion,
            ]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $bloques
     * @return array<string, mixed>
     */
    private function tarjeta(array $bloques): array
    {
        $bloques[] = [
            'type' => 'TextBlock',
            'text' => '[VER TABLERO]('.self::URL_TABLERO.')',
            'size' => 'ExtraLarge',
            'weight' => 'Bolder',
            'color' => 'Accent',
            'horizontalAlignment' => 'Center',
            'spacing' => 'Medium',
            'wrap' => true,
        ];

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => $bloques,
                    'actions' => [[
                        'type' => 'Action.OpenUrl',
                        'title' => 'VER TABLERO',
                        'style' => 'positive',
                        'url' => self::URL_TABLERO,
                    ]],
                ],
            ]],
        ];
    }

    private function textoTablero(): string
    {
        return "VER TABLERO\n".self::URL_TABLERO;
    }

    private function botonTablero(): string
    {
        return '<div style="padding:16px 20px;text-align:center;border-top:1px solid #dee2e6;border-bottom:1px solid #dee2e6;">'
            .'<a href="'.self::URL_TABLERO.'" style="color:#0b5ed7;font-size:22px;font-weight:700;text-decoration:underline;letter-spacing:0.6px;">VER TABLERO</a>'
            .'</div>';
    }

    private function celdaNumero(int $valor, string $color): string
    {
        $estilo = $valor > 0 ? 'color:'.$color.';font-weight:700;' : 'color:#6c757d;';

        return '<td style="padding:8px 10px;border-bottom:1px solid #dee2e6;text-align:center;'.$estilo.'">'.$valor.'</td>';
    }

    /**
     * @param  array{nombre: string, nivel: int}  $grupo
     */
    private function nombreGrupo(array $grupo): string
    {
        return $this->etiquetaNivel((int) $grupo['nivel']).' · '.$grupo['nombre'];
    }

    /**
     * @param  mixed  $tickets
     * @param  list<string>  $filtros
     * @return list<array<string, mixed>>
     */
    private function filtrar(mixed $tickets, array $filtros): array
    {
        if (! is_array($tickets)) {
            return [];
        }

        $lista = [];
        foreach ($tickets as $ticket) {
            if (! is_array($ticket)) {
                continue;
            }
            $grupo = trim((string) ($ticket['grupo_actual'] ?? 'Sin grupo'));
            if ($grupo === '') {
                $grupo = 'Sin grupo';
            }
            if (! $this->incluido($grupo, $filtros)) {
                continue;
            }
            $lista[] = $ticket;
        }

        return $lista;
    }

    /**
     * @param  list<array<string, mixed>>  $tickets
     * @return list<array{nombre: string, nivel: int, total: int, sin_asignar: int, en_tiempo: int, por_vencer: int, vencidos: int, sin_ans: int}>
     */
    private function porGrupo(array $tickets): array
    {
        $mapa = [];
        foreach ($tickets as $ticket) {
            $nombre = trim((string) ($ticket['grupo_actual'] ?? 'Sin grupo'));
            if ($nombre === '') {
                $nombre = 'Sin grupo';
            }
            if (! isset($mapa[$nombre])) {
                $mapa[$nombre] = [
                    'nombre' => $nombre,
                    'nivel' => (int) ($ticket['nivel'] ?? 1),
                    'total' => 0,
                    'sin_asignar' => 0,
                    'en_tiempo' => 0,
                    'por_vencer' => 0,
                    'vencidos' => 0,
                    'sin_ans' => 0,
                ];
            }
            $this->sumar($mapa[$nombre], $ticket);
        }

        $lista = array_values($mapa);
        usort($lista, function (array $a, array $b): int {
            $nivel = $a['nivel'] <=> $b['nivel'];
            if ($nivel !== 0) {
                return $nivel;
            }

            return strcasecmp($a['nombre'], $b['nombre']);
        });

        return $lista;
    }

    /**
     * @param  list<array<string, mixed>>  $tickets
     * @return array{total: int, sin_asignar: int, en_tiempo: int, por_vencer: int, vencidos: int, sin_ans: int}
     */
    private function conteo(array $tickets): array
    {
        $conteo = [
            'total' => 0,
            'sin_asignar' => 0,
            'en_tiempo' => 0,
            'por_vencer' => 0,
            'vencidos' => 0,
            'sin_ans' => 0,
        ];
        foreach ($tickets as $ticket) {
            $this->sumar($conteo, $ticket);
        }

        return $conteo;
    }

    /**
     * @param  array<string, int|string>  $conteo
     * @param  array<string, mixed>  $ticket
     */
    private function sumar(array &$conteo, array $ticket): void
    {
        $conteo['total']++;
        if ($this->sinAsignar($ticket)) {
            $conteo['sin_asignar']++;
        }

        $alerta = (string) ($ticket['alerta'] ?? '');
        if ($alerta === 'sin_ans') {
            $alerta = 'por_vencer';
        }
        if ($alerta === 'en_tiempo') {
            $conteo['en_tiempo']++;
        } elseif ($alerta === 'por_vencer') {
            $conteo['por_vencer']++;
        } elseif ($alerta === 'vencido') {
            $conteo['vencidos']++;
        }
    }

    /**
     * @param  array{total: int, sin_asignar: int, en_tiempo: int, por_vencer: int, vencidos: int, sin_ans: int}  $conteo
     */
    private function frase(array $conteo): string
    {
        $total = (int) $conteo['total'];
        $casos = $total === 1 ? '1 caso' : $total.' casos';
        $partes = [
            'Sin asignar '.$conteo['sin_asignar'],
            'en tiempo '.$conteo['en_tiempo'],
            'por vencer '.$conteo['por_vencer'],
            'vencidos '.$conteo['vencidos'],
        ];
        if ((int) $conteo['sin_ans'] > 0) {
            $partes[] = 'sin ANS '.$conteo['sin_ans'];
        }

        return $casos.'. '.implode(', ', $partes).'.';
    }

    /**
     * @param  array<string, mixed>  $ticket
     */
    private function sinAsignar(array $ticket): bool
    {
        $tecnico = $this->normalizar((string) ($ticket['tecnico'] ?? ''));

        return $tecnico === '' || $tecnico === 'sin asignar';
    }

    /**
     * @param  mixed  $grupos
     * @return list<string>
     */
    private function filtros(mixed $grupos): array
    {
        if (! is_array($grupos)) {
            return [];
        }

        $lista = [];
        foreach ($grupos as $grupo) {
            $texto = trim((string) $grupo);
            if ($texto !== '') {
                $lista[] = $texto;
            }
        }

        return array_values(array_unique($lista));
    }

    /**
     * @param  list<string>  $filtros
     */
    private function incluido(string $grupo, array $filtros): bool
    {
        if ($filtros === []) {
            return true;
        }

        $actual = $this->normalizar($grupo);
        foreach ($filtros as $filtro) {
            $esperado = $this->normalizar($filtro);
            if ($esperado === '') {
                continue;
            }
            if ($actual === $esperado) {
                return true;
            }
            if (mb_strlen($esperado) >= 4 && str_contains($actual, $esperado)) {
                return true;
            }
        }

        return false;
    }

    private function etiquetaNivel(int $nivel): string
    {
        return $nivel === 4 ? 'Proveedor' : 'N'.$nivel;
    }

    private function etiquetaAlerta(string $alerta): string
    {
        return match ($alerta) {
            'vencido' => 'Vencido',
            'por_vencer', 'sin_ans' => 'Por vencer',
            'en_tiempo' => 'En tiempo',
            default => $alerta !== '' ? $alerta : 'Sin estado',
        };
    }

    private function cuando(mixed $valor): string
    {
        $zona = (string) config('app.timezone', 'America/Bogota');
        if (! is_string($valor) || trim($valor) === '') {
            return Carbon::now($zona)->format('d/m/Y H:i');
        }

        try {
            return Carbon::parse($valor)->timezone($zona)->format('d/m/Y H:i');
        } catch (Throwable) {
            return Carbon::now($zona)->format('d/m/Y H:i');
        }
    }

    private function normalizar(string $valor): string
    {
        $valor = mb_strtolower(trim($valor));
        $limpio = preg_replace('/\s+/u', ' ', $valor);

        return $limpio ?? $valor;
    }

    private function escapar(string $valor): string
    {
        return htmlspecialchars($valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
