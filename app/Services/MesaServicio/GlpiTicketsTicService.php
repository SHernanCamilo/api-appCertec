<?php

declare(strict_types=1);

namespace App\Services\MesaServicio;

use App\Services\GLPI\GLPIService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class GlpiTicketsTicService
{
    private const ESTADOS = [
        1 => 'Nuevo',
        2 => 'En curso (asignado)',
        3 => 'En curso (planificado)',
        4 => 'En espera',
        5 => 'Resuelto',
        6 => 'Cerrado',
    ];

    private const PRIORIDADES = [
        1 => 'Muy baja',
        2 => 'Baja',
        3 => 'Media',
        4 => 'Alta',
        5 => 'Muy alta',
    ];

    public function __construct(private GLPIService $glpi)
    {
    }

    /**
     * @return array{
     *     generado_en: string,
     *     grupo: array{id: int, nombre: string},
     *     alerta_horas: int,
     *     url_glpi: string|null,
     *     resumen: array<string, int>,
     *     entidades: list<array{nombre: string, corta: string, total: int, vencidos: int, por_vencer: int}>,
     *     tickets: list<array<string, mixed>>
     * }
     */
    public function tablero(bool $forzar = false): array
    {
        $this->asegurarConfiguracion();

        $grupoId = max(1, (int) config('glpi.tic_tablero.grupo_id', 29));
        $alertaHoras = max(1, (int) config('glpi.tic_tablero.alerta_horas', 2));
        $ttl = max(15, (int) config('glpi.tic_tablero.cache_segundos', 60));
        $cacheKey = "mesa_glpi_tablero_tic_v2_{$grupoId}_{$alertaHoras}";

        if ($forzar) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, $ttl, function () use ($grupoId, $alertaHoras) {
            return $this->consultarTablero($grupoId, $alertaHoras);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function consultarTablero(int $grupoId, int $alertaHoras): array
    {
        $this->activarEntidadRaiz();

        $grupo = $this->leerGrupo($grupoId);
        $filas = $this->glpi->searchAllItems(
            'Ticket',
            [1, 2, 3, 4, 5, 7, 8, 12, 15, 18, 30, 80, 82],
            [
                2 => 'id',
                1 => 'titulo',
                3 => 'prioridad',
                4 => 'solicitante',
                5 => 'tecnico',
                7 => 'categoria',
                8 => 'grupo',
                12 => 'estado',
                15 => 'fecha_apertura',
                18 => 'vence_ans',
                30 => 'ans',
                80 => 'entidad',
                82 => 'vencido_glpi',
            ],
            400,
            [
                ['field' => 8, 'searchtype' => 'equals', 'value' => $grupoId],
                ['field' => 12, 'searchtype' => 'equals', 'value' => 'notold', 'link' => 'AND'],
            ]
        );

        $ahora = Carbon::now();
        $limite = $ahora->copy()->addHours($alertaHoras);
        $tickets = [];
        $porEntidad = [];
        $porGrupo = [];
        $porNivel = [
            1 => $this->resumenNivelVacio(1, 'Nivel 1'),
            2 => $this->resumenNivelVacio(2, 'Nivel 2'),
            3 => $this->resumenNivelVacio(3, 'Nivel 3'),
        ];
        $resumen = [
            'abiertos' => 0,
            'vencidos' => 0,
            'por_vencer' => 0,
            'en_tiempo' => 0,
            'sin_ans' => 0,
        ];

        foreach ($filas as $fila) {
            $ticket = $this->mapearTicket($fila, $ahora, $limite, $alertaHoras);
            $tickets[] = $ticket;
            $resumen['abiertos']++;
            $claveResumen = $ticket['alerta'] === 'vencido' ? 'vencidos' : $ticket['alerta'];
            if (isset($resumen[$claveResumen])) {
                $resumen[$claveResumen]++;
            }

            $nivel = (int) $ticket['nivel'];
            if (! isset($porNivel[$nivel])) {
                $porNivel[$nivel] = $this->resumenNivelVacio($nivel, 'Nivel '.$nivel);
            }
            $this->acumularConteo($porNivel[$nivel], $ticket['alerta']);

            $claveGrupo = (string) $ticket['grupo_actual'];
            if (! isset($porGrupo[$claveGrupo])) {
                $porGrupo[$claveGrupo] = [
                    'nombre' => $claveGrupo,
                    'nivel' => $nivel,
                    'total' => 0,
                    'vencidos' => 0,
                    'por_vencer' => 0,
                    'en_tiempo' => 0,
                    'sin_ans' => 0,
                ];
            }
            $this->acumularConteo($porGrupo[$claveGrupo], $ticket['alerta']);

            $clave = $ticket['entidad'];
            if (! isset($porEntidad[$clave])) {
                $porEntidad[$clave] = [
                    'nombre' => $ticket['entidad'],
                    'corta' => $ticket['entidad_corta'],
                    'total' => 0,
                    'vencidos' => 0,
                    'por_vencer' => 0,
                ];
            }
            $porEntidad[$clave]['total']++;
            if ($ticket['alerta'] === 'vencido') {
                $porEntidad[$clave]['vencidos']++;
            } elseif ($ticket['alerta'] === 'por_vencer') {
                $porEntidad[$clave]['por_vencer']++;
            }
        }

        usort($tickets, function (array $a, array $b): int {
            $cmpNivel = ((int) $a['nivel']) <=> ((int) $b['nivel']);
            if ($cmpNivel !== 0) {
                return $cmpNivel;
            }
            $cmpGrupo = strcasecmp((string) $a['grupo_actual'], (string) $b['grupo_actual']);
            if ($cmpGrupo !== 0) {
                return $cmpGrupo;
            }
            $orden = ['vencido' => 0, 'por_vencer' => 1, 'en_tiempo' => 2, 'sin_ans' => 3];
            $cmp = ($orden[$a['alerta']] ?? 9) <=> ($orden[$b['alerta']] ?? 9);
            if ($cmp !== 0) {
                return $cmp;
            }

            return ($a['minutos_restantes'] ?? PHP_INT_MAX) <=> ($b['minutos_restantes'] ?? PHP_INT_MAX);
        });

        $entidades = array_values($porEntidad);
        usort($entidades, fn (array $a, array $b) => $b['total'] <=> $a['total']);

        $gruposTecnicos = array_values($porGrupo);
        usort($gruposTecnicos, function (array $a, array $b): int {
            $cmp = ((int) $a['nivel']) <=> ((int) $b['nivel']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return $b['total'] <=> $a['total'];
        });

        ksort($porNivel);

        return [
            'generado_en' => $ahora->toIso8601String(),
            'grupo' => $grupo,
            'alerta_horas' => $alertaHoras,
            'url_glpi' => $this->urlBaseGlpi(),
            'resumen' => $resumen,
            'niveles' => array_values($porNivel),
            'grupos_tecnicos' => $gruposTecnicos,
            'entidades' => $entidades,
            'tickets' => $tickets,
        ];
    }

    /**
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function mapearTicket(array $fila, Carbon $ahora, Carbon $limite, int $alertaHoras): array
    {
        $prioridadId = $this->entero($fila['prioridad'] ?? null);
        $estadoId = $this->entero($fila['estado'] ?? null);
        $entidad = $this->texto($fila['entidad'] ?? null);
        $venceRaw = $this->texto($fila['vence_ans'] ?? null);
        $vence = $this->parseFecha($venceRaw);

        $alerta = 'sin_ans';
        $minutos = null;
        $tiempoTexto = 'Sin ANS';

        if ($vence) {
            $minutos = (int) round(($vence->getTimestamp() - $ahora->getTimestamp()) / 60);
            if ($vence->lessThanOrEqualTo($ahora)) {
                $alerta = 'vencido';
                $tiempoTexto = 'Vencido hace '.$this->formatearDuracion(abs($minutos));
            } elseif ($vence->lessThanOrEqualTo($limite)) {
                $alerta = 'por_vencer';
                $tiempoTexto = 'Vence en '.$this->formatearDuracion($minutos);
            } else {
                $alerta = 'en_tiempo';
                $tiempoTexto = 'Faltan '.$this->formatearDuracion($minutos);
            }
        }

        $id = (int) ($fila['id'] ?? 0);
        $grupos = $this->listaNombres($fila['grupo'] ?? null);
        $grupoActual = $this->resolverGrupoActual($grupos);

        return [
            'id' => $id,
            'titulo' => $this->texto($fila['titulo'] ?? null) ?: 'Sin título',
            'prioridad_id' => $prioridadId,
            'prioridad' => self::PRIORIDADES[$prioridadId] ?? ($prioridadId > 0 ? (string) $prioridadId : '—'),
            'estado_id' => $estadoId,
            'estado' => self::ESTADOS[$estadoId] ?? ($this->texto($fila['estado'] ?? null) ?: '—'),
            'solicitante' => $this->texto($fila['solicitante'] ?? null) ?: '—',
            'tecnico' => $this->texto($fila['tecnico'] ?? null) ?: 'Sin asignar',
            'categoria' => $this->texto($fila['categoria'] ?? null) ?: '—',
            'grupo' => implode(', ', $grupos),
            'grupos' => $grupos,
            'grupo_actual' => $grupoActual['nombre'],
            'nivel' => $grupoActual['nivel'],
            'entidad' => $entidad,
            'entidad_corta' => $this->entidadCorta($entidad),
            'fecha_apertura' => $this->texto($fila['fecha_apertura'] ?? null),
            'vence_ans' => $vence?->format('Y-m-d H:i:s'),
            'ans' => $this->texto($fila['ans'] ?? null) ?: '—',
            'alerta' => $alerta,
            'alerta_horas' => $alertaHoras,
            'minutos_restantes' => $minutos,
            'tiempo_texto' => $tiempoTexto,
            'url' => $this->urlTicket($id),
        ];
    }

    /**
     * El caso nace en Nivel 1 y se eleva a N2 o N3. El grupo actual es el de mayor nivel.
     *
     * @param  list<string>  $grupos
     * @return array{nombre: string, nivel: int}
     */
    private function resolverGrupoActual(array $grupos): array
    {
        $actual = [
            'nombre' => $grupos[0] ?? 'Sin grupo',
            'nivel' => 1,
        ];

        foreach ($grupos as $nombre) {
            $nivel = $this->nivelDesdeNombre($nombre);
            if ($nivel > $actual['nivel']) {
                $actual = [
                    'nombre' => $nombre,
                    'nivel' => $nivel,
                ];
            }
        }

        return $actual;
    }

    private function nivelDesdeNombre(string $nombre): int
    {
        if (preg_match('/nivel\s*(\d+)/iu', $nombre, $match)) {
            return max(1, (int) $match[1]);
        }

        return 1;
    }

    /**
     * @return array{nivel: int, nombre: string, total: int, vencidos: int, por_vencer: int, en_tiempo: int, sin_ans: int}
     */
    private function resumenNivelVacio(int $nivel, string $nombre): array
    {
        return [
            'nivel' => $nivel,
            'nombre' => $nombre,
            'total' => 0,
            'vencidos' => 0,
            'por_vencer' => 0,
            'en_tiempo' => 0,
            'sin_ans' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $conteo
     */
    private function acumularConteo(array &$conteo, string $alerta): void
    {
        $conteo['total']++;
        $clave = $alerta === 'vencido' ? 'vencidos' : $alerta;
        if (isset($conteo[$clave])) {
            $conteo[$clave]++;
        }
    }

    /**
     * @return list<string>
     */
    private function listaNombres(mixed $valor): array
    {
        if (! is_array($valor)) {
            $texto = $this->texto($valor);

            return $texto === '' ? [] : [$texto];
        }

        $nombres = [];
        foreach ($valor as $item) {
            $texto = trim($this->texto($item));
            if ($texto !== '' && ! in_array($texto, $nombres, true)) {
                $nombres[] = $texto;
            }
        }

        return $nombres;
    }

    /**
     * @return array{id: int, nombre: string}
     */
    private function leerGrupo(int $grupoId): array
    {
        try {
            $grupo = $this->glpi->getItem('Group', $grupoId);
            $nombre = trim((string) ($grupo['completename'] ?? $grupo['name'] ?? ''));

            return [
                'id' => $grupoId,
                'nombre' => $nombre !== '' ? $nombre : 'Grupo '.$grupoId,
            ];
        } catch (Throwable $e) {
            return [
                'id' => $grupoId,
                'nombre' => 'Grupo '.$grupoId,
            ];
        }
    }

    private function activarEntidadRaiz(): void
    {
        try {
            $this->glpi->changeActiveEntities(0, true);
        } catch (Throwable $e) {
            // La sesión API a veces responde true y no un array; se sigue con la entidad activa.
        }
    }

    private function asegurarConfiguracion(): void
    {
        if (trim((string) config('glpi.base_url')) === '' || trim((string) config('glpi.user_token')) === '') {
            throw new RuntimeException('GLPI no está configurado (GLPI_BASE_URL / GLPI_USER_TOKEN).');
        }
    }

    private function urlBaseGlpi(): ?string
    {
        $web = trim((string) config('glpi.web_url'));
        if ($web !== '') {
            return rtrim($web, '/');
        }

        $api = trim((string) config('glpi.base_url'));
        if ($api === '') {
            return null;
        }

        return rtrim((string) preg_replace('#/apirest\.php/?$#i', '', $api), '/');
    }

    private function urlTicket(int $id): ?string
    {
        $base = $this->urlBaseGlpi();
        if ($base === null || $id < 1) {
            return null;
        }

        return $base.'/front/ticket.form.php?id='.$id;
    }

    private function entidadCorta(string $ruta): string
    {
        $partes = array_values(array_filter(array_map('trim', explode('>', $ruta))));
        if ($partes === []) {
            return $ruta !== '' ? $ruta : 'Sin entidad';
        }

        $ultima = $partes[count($partes) - 1];
        if (count($partes) >= 2 && str_contains(mb_strtolower($ultima), 'tecnolog')) {
            return $partes[count($partes) - 2];
        }

        return $ultima;
    }

    private function parseFecha(?string $valor): ?Carbon
    {
        if ($valor === null || $valor === '' || strtoupper($valor) === 'NULL') {
            return null;
        }

        try {
            return Carbon::parse($valor);
        } catch (Throwable $e) {
            return null;
        }
    }

    private function formatearDuracion(int $minutos): string
    {
        $minutos = abs($minutos);
        $dias = intdiv($minutos, 1440);
        $horas = intdiv($minutos % 1440, 60);
        $mins = $minutos % 60;
        $partes = [];

        if ($dias > 0) {
            $partes[] = $dias.'d';
        }
        if ($horas > 0) {
            $partes[] = $horas.'h';
        }
        if ($mins > 0 || $partes === []) {
            $partes[] = $mins.'m';
        }

        return implode(' ', $partes);
    }

    private function texto(mixed $valor): string
    {
        if (is_array($valor)) {
            $partes = [];
            foreach ($valor as $item) {
                $texto = trim($this->texto($item));
                if ($texto !== '') {
                    $partes[] = $texto;
                }
            }

            return implode(', ', array_unique($partes));
        }

        if ($valor === null || $valor === false) {
            return '';
        }

        return trim((string) $valor);
    }

    private function entero(mixed $valor): int
    {
        if (is_array($valor)) {
            $valor = $valor[0] ?? 0;
        }

        return (int) $valor;
    }
}
