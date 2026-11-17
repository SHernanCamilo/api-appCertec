<?php

declare(strict_types=1);

namespace App\Services\MesaServicio\Teams;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GraphTeamsClient
{
    private const CACHE_TOKEN = 'mesa_glpi_teams_graph_token';

    private const CACHE_TOKEN_USUARIO = 'mesa_glpi_teams_user_token';

    private const CARPETA_CHAT = 'Microsoft Teams Chat Files';

    /**
     * @return list<array{id: string, nombre: string}>
     */
    public function equipos(): array
    {
        try {
            return $this->pedirEquipos(true);
        } catch (RuntimeException $primero) {
            try {
                return $this->pedirEquipos(false);
            } catch (RuntimeException) {
                throw $primero;
            }
        }
    }

    /**
     * @return list<array{id: string, nombre: string}>
     */
    private function pedirEquipos(bool $ordenado): array
    {
        $parametros = [
            '$select' => 'id,displayName',
            '$filter' => "resourceProvisioningOptions/Any(x:x eq 'Team')",
            '$top' => '200',
        ];
        if ($ordenado) {
            $parametros['$count'] = 'true';
            $parametros['$orderby'] = 'displayName';
        }

        $response = $this->cliente()
            ->withHeaders(['ConsistencyLevel' => 'eventual'])
            ->get('https://graph.microsoft.com/v1.0/groups', $parametros);

        $this->exigir($response, 'No se pudieron listar los equipos de Teams. La aplicación necesita el permiso Group.Read.All.');

        return $this->mapear($response->json('value'));
    }

    /**
     * @return list<array{id: string, nombre: string}>
     */
    public function canales(string $teamId): array
    {
        $teamId = trim($teamId);
        if ($teamId === '') {
            throw new RuntimeException('Falta el equipo de Teams.');
        }

        $response = $this->cliente()->get(
            'https://graph.microsoft.com/v1.0/teams/'.rawurlencode($teamId).'/channels',
            ['$select' => 'id,displayName']
        );

        $this->exigir($response, 'No se pudieron listar los canales. La aplicación necesita el permiso Channel.ReadBasic.All.');

        return $this->mapear($response->json('value'));
    }

    /**
     * Chats de grupo en los que está la cuenta de notificaciones.
     *
     * @return list<array{id: string, nombre: string}>
     */
    public function chats(): array
    {
        $token = $this->tokenUsuario();
        $url = 'https://graph.microsoft.com/v1.0/me/chats';
        $consulta = ['$top' => '50', '$select' => 'id,topic,chatType'];
        $lista = [];
        $paginas = 0;

        while (is_string($url) && $url !== '' && $paginas < 4) {
            $response = Http::withToken($token)->acceptJson()->timeout(25)->get($url, $consulta);
            $this->exigir($response, 'No se pudieron leer los chats de la cuenta de notificaciones.');
            foreach ($response->json('value') ?? [] as $fila) {
                if (! is_array($fila) || ($fila['chatType'] ?? '') === 'oneOnOne') {
                    continue;
                }
                $id = trim((string) ($fila['id'] ?? ''));
                $nombre = trim((string) ($fila['topic'] ?? ''));
                if ($id === '' || $nombre === '') {
                    continue;
                }
                $lista[] = ['id' => $id, 'nombre' => $nombre];
            }
            $url = $response->json('@odata.nextLink');
            $consulta = [];
            $paginas++;
        }

        usort($lista, fn (array $a, array $b): int => strcasecmp($a['nombre'], $b['nombre']));

        return $lista;
    }

    /**
     * @param  array{nombre: string, bytes: string}|null  $adjunto
     */
    public function publicarEnCanal(string $teamId, string $channelId, string $html, ?array $adjunto = null): void
    {
        $teamId = trim($teamId);
        $channelId = trim($channelId);
        if ($teamId === '' || $channelId === '') {
            throw new RuntimeException('Faltan el equipo o el canal de Teams.');
        }

        $url = 'https://graph.microsoft.com/v1.0/teams/'
            .rawurlencode($teamId)
            .'/channels/'
            .rawurlencode($channelId)
            .'/messages';

        $referencia = null;
        if ($adjunto !== null && ($adjunto['bytes'] ?? '') !== '') {
            $referencia = $this->guardarAdjuntoCanal($teamId, $channelId, $adjunto['nombre'], $adjunto['bytes']);
        }

        $response = $this->cliente()->post($url, $this->mensajeConAdjunto($html, $referencia));

        $this->exigir($response, 'No se pudo publicar en el canal. La aplicación necesita el permiso ChannelMessage.Send.');
    }

    /**
     * @param  array{nombre: string, bytes: string}|null  $adjunto
     */
    public function publicarEnChat(string $chatId, string $html, ?array $adjunto = null): void
    {
        $chatId = self::chatIdDesdeTexto($chatId) ?? trim($chatId);
        if ($chatId === '') {
            throw new RuntimeException('Falta el identificador del chat de Teams.');
        }

        $url = 'https://graph.microsoft.com/v1.0/chats/'.rawurlencode($chatId).'/messages';
        $referencia = null;
        if ($adjunto !== null && ($adjunto['bytes'] ?? '') !== '') {
            $referencia = $this->guardarAdjuntoChat($adjunto['nombre'], $adjunto['bytes']);
        }
        $response = Http::withToken($this->tokenUsuario())
            ->acceptJson()
            ->timeout(60)
            ->post($url, $this->mensajeConAdjunto($html, $referencia));

        $this->exigir($response, 'No se pudo publicar en el chat de Teams.');
    }

    /**
     * Teams solo incrusta imágenes en el mensaje. El Excel se adjunta como archivo del chat.
     *
     * @return array{id: string, url: string, nombre: string}
     */
    private function guardarAdjuntoChat(string $nombre, string $bytes): array
    {
        $nombre = trim($nombre);
        if ($nombre === '' || $bytes === '') {
            throw new RuntimeException('El Excel de casos está vacío.');
        }

        $this->carpetaArchivosChat();
        $token = $this->tokenUsuario();
        $ruta = 'https://graph.microsoft.com/v1.0/me/drive/root:/'
            .rawurlencode(self::CARPETA_CHAT).'/'.rawurlencode($nombre).':/content';
        $subida = Http::withToken($token)
            ->withBody($bytes, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->timeout(60)
            ->put($ruta);
        $this->exigir($subida, 'No se pudo adjuntar el Excel al chat.');

        $itemId = trim((string) $subida->json('id'));
        if ($itemId === '') {
            throw new RuntimeException('Microsoft no devolvió el Excel adjunto.');
        }

        $detalle = Http::withToken($token)
            ->acceptJson()
            ->timeout(25)
            ->get('https://graph.microsoft.com/v1.0/me/drive/items/'.$itemId, [
                '$select' => 'id,name,eTag,webDavUrl,webUrl',
            ]);
        $this->exigir($detalle, 'No se pudo leer el Excel adjunto.');

        return $this->referenciaArchivo($detalle->json(), $nombre);
    }

    /**
     * @return array{id: string, url: string, nombre: string}
     */
    private function guardarAdjuntoCanal(string $teamId, string $channelId, string $nombre, string $bytes): array
    {
        $nombre = trim($nombre);
        if ($nombre === '' || $bytes === '') {
            throw new RuntimeException('El Excel de casos está vacío.');
        }

        $carpeta = $this->cliente()->get(
            'https://graph.microsoft.com/v1.0/teams/'.rawurlencode($teamId)
            .'/channels/'.rawurlencode($channelId).'/filesFolder'
        );
        $this->exigir($carpeta, 'No se pudo abrir la carpeta de archivos del canal.');
        $driveId = trim((string) $carpeta->json('parentReference.driveId'));
        $folderId = trim((string) $carpeta->json('id'));
        if ($driveId === '' || $folderId === '') {
            throw new RuntimeException('El canal de Teams no tiene carpeta de archivos.');
        }

        $subida = Http::withToken($this->token())
            ->withBody($bytes, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->timeout(60)
            ->put(
                'https://graph.microsoft.com/v1.0/drives/'.$driveId
                .'/items/'.$folderId.':/'.rawurlencode($nombre).':/content'
            );
        $this->exigir($subida, 'No se pudo adjuntar el Excel al canal.');

        $itemId = trim((string) $subida->json('id'));
        $detalle = Http::withToken($this->token())
            ->acceptJson()
            ->timeout(25)
            ->get('https://graph.microsoft.com/v1.0/drives/'.$driveId.'/items/'.$itemId, [
                '$select' => 'id,name,eTag,webDavUrl,webUrl',
            ]);
        $this->exigir($detalle, 'No se pudo leer el Excel adjunto.');

        return $this->referenciaArchivo($detalle->json(), $nombre);
    }

    private function carpetaArchivosChat(): void
    {
        $token = $this->tokenUsuario();
        $drive = Http::withToken($token)->acceptJson()->timeout(25)->get('https://graph.microsoft.com/v1.0/me/drive');
        if ($drive->status() === 404) {
            throw new RuntimeException(
                'La cuenta de notificaciones no puede adjuntar archivos. En la aplicación de ese inicio de sesión falta el permiso delegado Files.ReadWrite, con consentimiento de administrador, y la cuenta debe haber abierto OneDrive al menos una vez.'
            );
        }
        $this->exigir($drive, 'No se pudo abrir el almacenamiento de la cuenta de notificaciones.');

        $url = 'https://graph.microsoft.com/v1.0/me/drive/root:/'.rawurlencode(self::CARPETA_CHAT);
        $existe = Http::withToken($token)->acceptJson()->timeout(25)->get($url);
        if ($existe->successful()) {
            return;
        }
        if ($existe->status() !== 404) {
            $this->exigir($existe, 'No se pudo abrir la carpeta de archivos del chat.');
        }

        $crear = Http::withToken($token)->acceptJson()->timeout(25)->post(
            'https://graph.microsoft.com/v1.0/me/drive/root/children',
            [
                'name' => self::CARPETA_CHAT,
                'folder' => new \stdClass(),
                '@microsoft.graph.conflictBehavior' => 'fail',
            ]
        );
        if ($crear->status() === 409) {
            return;
        }
        $this->exigir($crear, 'No se pudo preparar la carpeta de archivos del chat.');
    }

    /**
     * @param  array<string, mixed>|null  $item
     * @return array{id: string, url: string, nombre: string}
     */
    private function referenciaArchivo(?array $item, string $nombre): array
    {
        $etiqueta = (string) ($item['eTag'] ?? '');
        if (! preg_match('/\{([0-9a-fA-F-]{36})\}/', $etiqueta, $coincide)) {
            throw new RuntimeException('Microsoft no devolvió el identificador del Excel.');
        }
        $url = trim((string) ($item['webDavUrl'] ?? $item['webUrl'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('Microsoft no devolvió el enlace del Excel.');
        }

        return [
            'id' => $coincide[1],
            'url' => $url,
            'nombre' => trim((string) ($item['name'] ?? $nombre)) ?: $nombre,
        ];
    }

    /**
     * @param  array{id: string, url: string, nombre: string}|null  $adjunto
     * @return array<string, mixed>
     */
    private function mensajeConAdjunto(string $html, ?array $adjunto): array
    {
        if ($adjunto === null) {
            return [
                'body' => [
                    'contentType' => 'html',
                    'content' => str_replace('<!--EXCEL-->', '', $html),
                ],
            ];
        }

        return [
            'body' => [
                'contentType' => 'html',
                'content' => str_replace('<!--EXCEL-->', '<attachment id="'.$adjunto['id'].'"></attachment>', $html),
            ],
            'attachments' => [[
                'id' => $adjunto['id'],
                'contentType' => 'reference',
                'contentUrl' => $adjunto['url'],
                'name' => $adjunto['nombre'],
            ]],
        ];
    }

    public static function chatIdDesdeTexto(string $texto): ?string
    {
        $texto = trim(urldecode($texto));
        if (preg_match('/19:[0-9a-f]{8,}@thread\.v2/i', $texto, $coincide)) {
            return $coincide[0];
        }

        return null;
    }

    private function cliente(): PendingRequest
    {
        return Http::withToken($this->token())
            ->acceptJson()
            ->timeout(25);
    }

    private function token(): string
    {
        $cacheado = Cache::get(self::CACHE_TOKEN);
        if (is_string($cacheado) && $cacheado !== '') {
            return $cacheado;
        }

        [$tenant, $client, $secret] = $this->credenciales();
        $response = Http::asForm()->timeout(20)->post(
            "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
            [
                'client_id' => $client,
                'client_secret' => $secret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]
        );

        if ($response->failed()) {
            Log::warning('Token Graph de Teams falló', ['status' => $response->status()]);
            throw new RuntimeException('No se pudo obtener el token de Microsoft Graph.');
        }

        $token = (string) $response->json('access_token');
        if ($token === '') {
            throw new RuntimeException('Microsoft Graph no devolvió un token.');
        }

        Cache::put(self::CACHE_TOKEN, $token, now()->addMinutes(50));

        return $token;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function credenciales(): array
    {
        $tenant = trim((string) config('services.microsoft_graph.tenant_id'));
        $client = trim((string) config('services.microsoft_graph.client_id'));
        $secret = trim((string) config('services.microsoft_graph.client_secret'));

        if ($tenant === '' || strcasecmp($tenant, 'common') === 0 || $client === '' || $secret === '') {
            throw new RuntimeException('Faltan las credenciales de Microsoft Graph del tenant.');
        }

        return [$tenant, $client, $secret];
    }

    private function tokenUsuario(): string
    {
        $cacheado = Cache::get(self::CACHE_TOKEN_USUARIO);
        if (is_string($cacheado) && $cacheado !== '') {
            return $cacheado;
        }

        $tenant = trim((string) env('EMAIL_AZURE_TENANT_ID', env('MICROSOFT_MEDILASER_TENANT_ID')));
        $client = trim((string) env('EMAIL_AZURE_CLIENT_ID'));
        $usuario = trim((string) env('MICROSOFT_EMAIL'));
        $clave = (string) env('MICROSOFT_PASSWORD');

        if ($tenant === '' || $client === '' || $usuario === '' || $clave === '') {
            throw new RuntimeException('Falta el usuario de notificaciones para entrar al chat.');
        }

        $response = Http::asForm()
            ->timeout(25)
            ->post("https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token", [
                'grant_type' => 'password',
                'client_id' => $client,
                'scope' => 'https://graph.microsoft.com/.default',
                'username' => $usuario,
                'password' => $clave,
            ]);

        if ($response->failed()) {
            Log::warning('Token del usuario de notificaciones rechazado', ['status' => $response->status()]);
            throw new RuntimeException('La cuenta de notificaciones no pudo iniciar sesión en Microsoft.');
        }

        $token = (string) $response->json('access_token');
        if ($token === '') {
            throw new RuntimeException('Microsoft no devolvió un token para la cuenta de notificaciones.');
        }

        Cache::put(self::CACHE_TOKEN_USUARIO, $token, now()->addMinutes(50));

        return $token;
    }

    private function exigir(Response $response, string $fallback): void
    {
        if ($response->successful()) {
            return;
        }

        $detalle = trim((string) ($response->json('error.message') ?? ''));
        $mensaje = $detalle === '' ? $fallback.' (HTTP '.$response->status().')' : $fallback.' '.$detalle;
        throw new RuntimeException($mensaje);
    }

    /**
     * @return list<array{id: string, nombre: string}>
     */
    private function mapear(mixed $filas): array
    {
        if (! is_array($filas)) {
            return [];
        }

        $lista = [];
        foreach ($filas as $fila) {
            if (! is_array($fila)) {
                continue;
            }
            $id = trim((string) ($fila['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $lista[] = [
                'id' => $id,
                'nombre' => trim((string) ($fila['displayName'] ?? $id)),
            ];
        }

        usort($lista, fn (array $a, array $b): int => strcasecmp($a['nombre'], $b['nombre']));

        return $lista;
    }
}
