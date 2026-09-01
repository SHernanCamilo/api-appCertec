<?php

declare(strict_types=1);

namespace App\Services\MesaServicio;

use App\Services\GLPI\GLPIService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
        $cacheKey = "mesa_glpi_tablero_tic_v4_{$grupoId}_{$alertaHoras}";

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

        $ids = [];
        $gruposPorTicket = [];
        $idsTecnicos = [];
        foreach ($filas as $fila) {
            $id = (int) ($fila['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $ids[] = $id;
            $gruposPorTicket[$id] = $this->listaNombres($fila['grupo'] ?? null);
            foreach ($this->extraerIds($fila['tecnico'] ?? null) as $userId) {
                $idsTecnicos[] = $userId;
            }
        }
        $ultimoGrupoPorTicket = $this->consultarUltimoGrupoHistorico($ids, $gruposPorTicket);
        $nombresTecnicos = $this->resolverNombresUsuarios($idsTecnicos);

        foreach ($filas as $fila) {
            $id = (int) ($fila['id'] ?? 0);
            $ticket = $this->mapearTicket(
                $fila,
                $ahora,
                $limite,
                $alertaHoras,
                $ultimoGrupoPorTicket[$id] ?? null,
                $nombresTecnicos
            );
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
     * @param  array{nombre: string, nivel: int}|null  $grupoHistorico
     * @param  array<int, string>  $nombresTecnicos
     * @return array<string, mixed>
     */
    private function mapearTicket(
        array $fila,
        Carbon $ahora,
        Carbon $limite,
        int $alertaHoras,
        ?array $grupoHistorico = null,
        array $nombresTecnicos = []
    ): array
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
        $grupoActual = $grupoHistorico ?? $this->resolverGrupoActual($grupos);

        return [
            'id' => $id,
            'titulo' => $this->texto($fila['titulo'] ?? null) ?: 'Sin título',
            'prioridad_id' => $prioridadId,
            'prioridad' => self::PRIORIDADES[$prioridadId] ?? ($prioridadId > 0 ? (string) $prioridadId : '—'),
            'estado_id' => $estadoId,
            'estado' => self::ESTADOS[$estadoId] ?? ($this->texto($fila['estado'] ?? null) ?: '—'),
            'solicitante' => $this->texto($fila['solicitante'] ?? null) ?: '—',
            'tecnico' => $this->nombresTecnicos($fila['tecnico'] ?? null, $nombresTecnicos) ?: 'Sin asignar',
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
     * Último grupo técnico aún asignado, según el histórico (mayor id en glpi_groups_tickets).
     * No usa N1/N2/N3: un caso puede volver a N2 después de haber pasado por N3.
     *
     * @param  list<int>  $ticketIds
     * @param  array<int, list<string>>  $gruposTecnicosPorTicket
     * @return array<int, array{nombre: string, nivel: int}>
     */
    private function consultarUltimoGrupoHistorico(array $ticketIds, array $gruposTecnicosPorTicket): array
    {
        $ticketIds = array_values(array_unique(array_filter($ticketIds)));
        if ($ticketIds === []) {
            return [];
        }

        try {
            $relaciones = [];
            foreach (array_chunk($ticketIds, 40) as $chunk) {
                $criteria = [];
                foreach ($chunk as $i => $ticketId) {
                    $criterio = [
                        'field' => 3,
                        'searchtype' => 'equals',
                        'value' => $ticketId,
                    ];
                    if ($i > 0) {
                        $criterio['link'] = 'OR';
                    }
                    $criteria[] = $criterio;
                }

                $filas = $this->glpi->searchAllItems(
                    'Group_Ticket',
                    [2, 3, 4],
                    [
                        2 => 'id',
                        3 => 'ticket_id',
                        4 => 'group_id',
                    ],
                    400,
                    $criteria
                );

                foreach ($filas as $fila) {
                    $ticketId = (int) ($fila['ticket_id'] ?? 0);
                    $groupId = (int) ($fila['group_id'] ?? 0);
                    $relId = (int) ($fila['id'] ?? 0);
                    if ($ticketId < 1 || $groupId < 1 || $relId < 1) {
                        continue;
                    }
                    $relaciones[$ticketId][] = [
                        'id' => $relId,
                        'group_id' => $groupId,
                    ];
                }
            }

            $groupIds = [];
            foreach ($relaciones as $rels) {
                foreach ($rels as $rel) {
                    $groupIds[] = $rel['group_id'];
                }
            }
            $nombresGrupos = $this->resolverNombresGrupos($groupIds);

            $resultado = [];
            foreach ($relaciones as $ticketId => $rels) {
                $tecnicos = $gruposTecnicosPorTicket[$ticketId] ?? [];
                $elegido = null;
                foreach ($rels as $rel) {
                    $nombre = $nombresGrupos[$rel['group_id']] ?? null;
                    if ($nombre === null || $nombre === '') {
                        continue;
                    }
                    if ($tecnicos !== [] && ! $this->coincideGrupo($nombre, $tecnicos)) {
                        continue;
                    }
                    if ($elegido === null || $rel['id'] > $elegido['id']) {
                        $elegido = [
                            'id' => $rel['id'],
                            'nombre' => $nombre,
                        ];
                    }
                }
                if ($elegido === null) {
                    continue;
                }
                $resultado[$ticketId] = [
                    'nombre' => $elegido['nombre'],
                    'nivel' => $this->nivelDesdeNombre($elegido['nombre']),
                ];
            }

            return $resultado;
        } catch (Throwable $e) {
            Log::warning('Tablero TIC: no se pudo leer el histórico de grupos técnicos: '.$e->getMessage());

            return [];
        }
    }

    /**
     * @param  list<int>  $groupIds
     * @return array<int, string>
     */
    private function resolverNombresGrupos(array $groupIds): array
    {
        $groupIds = array_values(array_unique(array_filter($groupIds)));
        if ($groupIds === []) {
            return [];
        }

        $nombres = [];
        try {
            $params = ['get_hateoas' => false];
            foreach ($groupIds as $i => $id) {
                $params['items['.$i.'][itemtype]'] = 'Group';
                $params['items['.$i.'][items_id]'] = $id;
            }
            $items = $this->glpi->normalizeCollection($this->glpi->get('/getMultipleItems', $params));
            foreach ($items as $item) {
                $id = (int) ($item['id'] ?? 0);
                $nombre = trim((string) ($item['completename'] ?? $item['name'] ?? ''));
                if ($id > 0 && $nombre !== '') {
                    $nombres[$id] = $nombre;
                }
            }
        } catch (Throwable $e) {
            Log::warning('Tablero TIC: no se pudieron resolver grupos en lote: '.$e->getMessage());
        }

        foreach ($groupIds as $id) {
            if (isset($nombres[$id])) {
                continue;
            }
            try {
                $grupo = $this->glpi->getItem('Group', $id);
                $nombre = trim((string) ($grupo['completename'] ?? $grupo['name'] ?? ''));
                if ($nombre !== '') {
                    $nombres[$id] = $nombre;
                }
            } catch (Throwable $e) {
                // El tablero sigue con el resto de grupos.
            }
        }

        return $nombres;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, string>
     */
    private function resolverNombresUsuarios(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if ($userIds === []) {
            return [];
        }

        $nombres = [];
        try {
            foreach (array_chunk($userIds, 50) as $chunk) {
                $params = ['get_hateoas' => false];
                foreach (array_values($chunk) as $i => $id) {
                    $params['items['.$i.'][itemtype]'] = 'User';
                    $params['items['.$i.'][items_id]'] = $id;
                }
                $items = $this->glpi->normalizeCollection($this->glpi->get('/getMultipleItems', $params));
                foreach ($items as $item) {
                    $id = (int) ($item['id'] ?? 0);
                    $nombre = $this->nombreUsuario($item);
                    if ($id > 0 && $nombre !== '') {
                        $nombres[$id] = $nombre;
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('Tablero TIC: no se pudieron resolver técnicos en lote: '.$e->getMessage());
        }

        foreach ($userIds as $id) {
            if (isset($nombres[$id])) {
                continue;
            }
            try {
                $usuario = $this->glpi->getItem('User', $id);
                $nombre = $this->nombreUsuario($usuario);
                if ($nombre !== '') {
                    $nombres[$id] = $nombre;
                }
            } catch (Throwable $e) {
                // El tablero sigue con el resto de técnicos.
            }
        }

        return $nombres;
    }

    /**
     * @param  array<string, mixed>  $usuario
     */
    private function nombreUsuario(array $usuario): string
    {
        $completo = trim(implode(' ', array_filter([
            trim((string) ($usuario['firstname'] ?? '')),
            trim((string) ($usuario['realname'] ?? '')),
        ])));
        if ($completo !== '') {
            return $completo;
        }

        foreach (['completename', 'name'] as $campo) {
            $valor = trim((string) ($usuario[$campo] ?? ''));
            if ($valor !== '') {
                return $valor;
            }
        }

        return '';
    }

    /**
     * @return list<int>
     */
    private function extraerIds(mixed $valor): array
    {
        if ($valor === null || $valor === false || $valor === '') {
            return [];
        }

        if (is_array($valor)) {
            $ids = [];
            foreach ($valor as $item) {
                foreach ($this->extraerIds($item) as $id) {
                    $ids[] = $id;
                }
            }

            return array_values(array_unique($ids));
        }

        $texto = trim((string) $valor);
        if (preg_match('/^\d+$/', $texto)) {
            return [(int) $texto];
        }

        if (preg_match('/^[\d\s,;]+$/', $texto) && preg_match_all('/\d+/', $texto, $matches)) {
            return array_values(array_unique(array_map('intval', $matches[0])));
        }

        return [];
    }

    /**
     * @param  array<int, string>  $nombres
     */
    private function nombresTecnicos(mixed $valor, array $nombres): string
    {
        $ids = $this->extraerIds($valor);
        if ($ids === []) {
            return $this->texto($valor);
        }

        $lista = [];
        foreach ($ids as $id) {
            $lista[] = $nombres[$id] ?? (string) $id;
        }

        return implode(', ', array_unique($lista));
    }

    /**
     * @param  list<string>  $lista
     */
    private function coincideGrupo(string $nombre, array $lista): bool
    {
        $objetivo = mb_strtolower(trim($nombre));
        foreach ($lista as $item) {
            if (mb_strtolower(trim((string) $item)) === $objetivo) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fallback si no llega el histórico: el de mayor nivel entre los grupos actuales.
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
