<?php

declare(strict_types=1);

namespace App\Services\MesaServicio\Teams;

use App\Models\MesaServicio\GlpiTeamsDestino;
use App\Services\MesaServicio\GlpiTicketsTicService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GlpiTeamsNotificador
{
    public function __construct(
        private GlpiTicketsTicService $tablero,
        private GlpiTeamsMensaje $mensaje,
        private GraphTeamsClient $graph,
    ) {
    }

    /**
     * @return list<string>
     */
    public function enviarPendientes(?int $id, bool $forzar): array
    {
        $query = GlpiTeamsDestino::query()->orderBy('nombre');
        if ($id !== null) {
            $query->whereKey($id);
        } else {
            $query->where('activo', true);
        }

        $destinos = $query->get();
        if (! $forzar) {
            $destinos = $destinos->filter(fn (GlpiTeamsDestino $destino): bool => $this->vence($destino))->values();
        }

        if ($destinos->isEmpty()) {
            return [];
        }

        $tablero = $this->tablero->tablero(false);
        $lineas = [];
        foreach ($destinos as $destino) {
            try {
                $estado = $this->despachar(
                    $destino,
                    $tablero,
                    $forzar ? false : (bool) $destino->omitir_si_vacio
                );
                $lineas[] = $destino->nombre.': '.$estado;
            } catch (Throwable) {
                $lineas[] = $destino->nombre.': error';
            }
        }

        return $lineas;
    }

    public function enviarAhora(GlpiTeamsDestino $destino): string
    {
        return $this->despachar($destino, $this->tablero->tablero(true), false);
    }

    public function enviarProgramado(GlpiTeamsDestino $destino): string
    {
        if (! $destino->activo) {
            return 'omitido';
        }

        return $this->despachar($destino, $this->tablero->tablero(true), (bool) $destino->omitir_si_vacio);
    }

    /**
     * @param  array{nombre?: string, modo?: string, grupos?: list<string>|null}  $config
     * @return array{titulo: string, texto: string, html: string, adaptive: array<string, mixed>, casos: int}
     */
    public function vista(array $config): array
    {
        return $this->mensaje->construir($this->tablero->tablero(false), $config);
    }

    /**
     * @param  array{titulo: string, texto: string, html: string, adaptive: array<string, mixed>, casos: int}  $mensaje
     * @return array<string, mixed>
     */
    public static function payloadWebhook(string $url, array $mensaje): array
    {
        if (self::esConectorOffice($url)) {
            return [
                '@type' => 'MessageCard',
                '@context' => 'https://schema.org/extensions',
                'themeColor' => '0B5ED7',
                'summary' => $mensaje['titulo'],
                'title' => $mensaje['titulo'],
                'text' => $mensaje['texto'],
                'potentialAction' => [[
                    '@type' => 'OpenUri',
                    'name' => 'VER TABLERO',
                    'targets' => [[
                        'os' => 'default',
                        'uri' => GlpiTeamsMensaje::URL_TABLERO,
                    ]],
                ]],
            ];
        }

        return $mensaje['adaptive'];
    }

    /**
     * @param  array<string, mixed>  $tablero
     */
    private function despachar(GlpiTeamsDestino $destino, array $tablero, bool $omitirVacio): string
    {
        $config = [
            'nombre' => $destino->nombre,
            'modo' => $destino->modo,
            'grupos' => $destino->grupos ?? [],
        ];
        $mensaje = $this->mensaje->construir($tablero, $config);

        if ($omitirVacio && $mensaje['casos'] === 0) {
            $destino->forceFill([
                'ultima_notificacion_at' => now(),
                'ultimo_estado' => 'omitido',
                'ultimo_error' => null,
            ])->save();

            return 'omitido';
        }

        $webhook = $destino->webhook();
        $chatId = GraphTeamsClient::chatIdDesdeTexto((string) $webhook)
            ?? $this->chatGuardado($destino);
        try {
            if ($chatId) {
                $this->graph->publicarEnChat($chatId, $mensaje['html']);
            } elseif ($webhook) {
                $this->publicarWebhook($webhook, $mensaje);
            } elseif ($destino->team_id && $destino->channel_id) {
                $this->graph->publicarEnCanal((string) $destino->team_id, (string) $destino->channel_id, $mensaje['html']);
            } else {
                throw new RuntimeException('El destino no tiene webhook, canal ni chat de Teams.');
            }
        } catch (Throwable $e) {
            $destino->forceFill([
                'ultimo_estado' => 'error',
                'ultimo_error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();
            Log::warning('Notificación Teams TIC falló', [
                'destino_id' => $destino->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $destino->forceFill([
            'ultima_notificacion_at' => now(),
            'ultimo_estado' => 'enviado',
            'ultimo_error' => null,
        ])->save();

        Log::info('Notificación Teams TIC enviada', [
            'destino_id' => $destino->id,
            'modo' => $destino->modo,
            'casos' => $mensaje['casos'],
            'via' => $chatId ? 'chat' : ($webhook ? 'webhook' : 'canal'),
        ]);

        return 'enviado';
    }

    /**
     * @param  array{titulo: string, texto: string, html: string, adaptive: array<string, mixed>, casos: int}  $mensaje
     */
    private function publicarWebhook(string $url, array $mensaje): void
    {
        $response = Http::acceptJson()
            ->timeout(25)
            ->post($url, self::payloadWebhook($url, $mensaje));

        if ($response->failed()) {
            throw new RuntimeException('El webhook de Teams respondió HTTP '.$response->status().'.');
        }
    }

    private function vence(GlpiTeamsDestino $destino): bool
    {
        if (! $destino->activo) {
            return false;
        }
        if ($destino->ultima_notificacion_at === null) {
            return true;
        }

        $minutos = max(5, (int) $destino->intervalo_minutos);

        return $destino->ultima_notificacion_at->lte(now()->subMinutes($minutos));
    }

    private static function esConectorOffice(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return str_contains($host, 'webhook.office.com') || str_contains($host, 'outlook.office.com');
    }

    private function chatGuardado(GlpiTeamsDestino $destino): ?string
    {
        if (filled($destino->team_id)) {
            return null;
        }

        return GraphTeamsClient::chatIdDesdeTexto((string) $destino->channel_id);
    }
}
