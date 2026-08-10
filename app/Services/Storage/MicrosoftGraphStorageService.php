<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Servicio de almacenamiento usando Microsoft Graph API.
 *
 * Autenticación: Client Credentials (Application permissions, sin delegación).
 *
 * Permisos de Azure AD requeridos (tipo Application, no Delegated):
 *   - Files.ReadWrite.All  → acceso a OneDrive de cualquier usuario del tenant
 *   - Sites.ReadWrite.All  → acceso a SharePoint sites/libraries (recomendado)
 *   - Sites.Selected       → acceso solo a sites específicos (más seguro)
 *
 * Configuración en Azure Portal:
 *   1. App Registration → crear o usar existente
 *   2. API Permissions → Add → Microsoft Graph → Application permissions
 *   3. Agregar: Sites.ReadWrite.All (o Sites.Selected para granularidad)
 *   4. Grant Admin Consent
 *   5. Certificates & Secrets → nuevo Client Secret
 *
 * Estrategia recomendada: usar un SharePoint Site dedicado ("Anticipos")
 * como Document Library, así no se depende del OneDrive de un usuario.
 */
class MicrosoftGraphStorageService
{
    private string $tenantId;
    private string $clientId;
    private string $clientSecret;
    private string $driveId;
    private string $basePath;
    private string $graphUrl = 'https://graph.microsoft.com/v1.0';

    public function __construct()
    {
        $this->tenantId = config('services.microsoft_graph.tenant_id');
        $this->clientId = config('services.microsoft_graph.client_id');
        $this->clientSecret = config('services.microsoft_graph.client_secret');
        $this->driveId = config('services.microsoft_graph.drive_id', '');
        $this->basePath = config('services.microsoft_graph.base_path', 'Anticipos');
    }

    /**
     * Obtiene access token usando Client Credentials flow (application permission).
     * Token se cachea por 55 minutos (expira en 60).
     */
    public function getAccessToken(): string
    {
        return Cache::remember('msgraph_app_token', 3300, function () {
            $response = Http::asForm()->post(
                "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token",
                [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ]
            );

            if (!$response->successful()) {
                Log::error('Error obteniendo token de Graph API', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('No se pudo obtener token de Microsoft Graph: ' . $response->body());
            }

            return $response->json('access_token');
        });
    }

    /**
     * Sube un archivo a OneDrive/SharePoint via Graph API.
     *
     * Para archivos < 4MB usa PUT directo.
     * Para archivos > 4MB usa upload session (chunked).
     *
     * @param string $rutaRemota Ruta dentro del drive (ej: "Anticipos/ANT-2026-00001/soporte.pdf")
     * @param string $contenido Contenido binario del archivo
     * @param string|null $mimeType
     *
     * @return array ['id' => driveItemId, 'webUrl' => url, 'size' => bytes]
     */
    public function upload(string $rutaRemota, string $contenido, ?string $mimeType = null): array
    {
        $token = $this->getAccessToken();
        $fullPath = "{$this->basePath}/{$rutaRemota}";
        $size = strlen($contenido);

        // Si el archivo es mayor a 4MB, usar upload session
        if ($size > 4 * 1024 * 1024) {
            return $this->uploadLargeFile($fullPath, $contenido, $token);
        }

        // Upload directo (< 4MB)
        $endpoint = $this->buildDriveEndpoint("/root:/{$fullPath}:/content");

        $response = Http::withToken($token)
            ->withHeaders(['Content-Type' => $mimeType ?? 'application/octet-stream'])
            ->withBody($contenido, $mimeType ?? 'application/octet-stream')
            ->put($endpoint);

        if (!$response->successful()) {
            Log::error('Error subiendo archivo a Graph', [
                'path' => $fullPath,
                'status' => $response->status(),
                'error' => $response->body(),
            ]);
            throw new \Exception("Error subiendo archivo a OneDrive: " . $response->json('error.message', $response->body()));
        }

        $data = $response->json();

        return [
            'id' => $data['id'],
            'webUrl' => $data['webUrl'] ?? null,
            'size' => $data['size'] ?? $size,
            'name' => $data['name'] ?? basename($rutaRemota),
        ];
    }

    /**
     * Sube un archivo desde un UploadedFile de Laravel.
     */
    public function uploadFromFile(string $rutaRemota, \Illuminate\Http\UploadedFile $file): array
    {
        return $this->upload(
            $rutaRemota,
            file_get_contents($file->getRealPath()),
            $file->getMimeType()
        );
    }

    /**
     * Descarga un archivo y retorna su contenido.
     */
    public function download(string $rutaRemota): string
    {
        $token = $this->getAccessToken();
        $fullPath = "{$this->basePath}/{$rutaRemota}";
        $endpoint = $this->buildDriveEndpoint("/root:/{$fullPath}:/content");

        $response = Http::withToken($token)->get($endpoint);

        if (!$response->successful()) {
            throw new \Exception("Error descargando archivo de OneDrive: " . $response->status());
        }

        return $response->body();
    }

    /**
     * Genera un link de descarga temporal (sharing link).
     * Válido por el tiempo configurado o hasta que se revoque.
     */
    public function createSharingLink(string $rutaRemota, string $type = 'view', int $expirationHours = 1): string
    {
        $token = $this->getAccessToken();
        $fullPath = "{$this->basePath}/{$rutaRemota}";

        // Primero obtener el item ID
        $endpoint = $this->buildDriveEndpoint("/root:/{$fullPath}");
        $itemResponse = Http::withToken($token)->get($endpoint);

        if (!$itemResponse->successful()) {
            throw new \Exception("Archivo no encontrado en OneDrive: {$rutaRemota}");
        }

        $itemId = $itemResponse->json('id');

        // Crear sharing link
        $linkEndpoint = $this->buildDriveEndpoint("/items/{$itemId}/createLink");
        $linkResponse = Http::withToken($token)->post($linkEndpoint, [
            'type' => $type, // view, edit, embed
            'scope' => 'organization', // organization o anonymous
            'expirationDateTime' => now()->addHours($expirationHours)->toIso8601String(),
        ]);

        if (!$linkResponse->successful()) {
            throw new \Exception("Error creando link de compartir: " . $linkResponse->body());
        }

        return $linkResponse->json('link.webUrl');
    }

    /**
     * Elimina un archivo del drive.
     */
    public function delete(string $rutaRemota): bool
    {
        $token = $this->getAccessToken();
        $fullPath = "{$this->basePath}/{$rutaRemota}";
        $endpoint = $this->buildDriveEndpoint("/root:/{$fullPath}");

        $response = Http::withToken($token)->delete($endpoint);

        if ($response->status() === 404) {
            return true; // ya no existe
        }

        if (!$response->successful()) {
            Log::error('Error eliminando archivo de Graph', [
                'path' => $fullPath,
                'status' => $response->status(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * Verifica si un archivo existe.
     */
    public function exists(string $rutaRemota): bool
    {
        $token = $this->getAccessToken();
        $fullPath = "{$this->basePath}/{$rutaRemota}";
        $endpoint = $this->buildDriveEndpoint("/root:/{$fullPath}");

        $response = Http::withToken($token)->get($endpoint);

        return $response->successful();
    }

    /**
     * Lista archivos en una carpeta.
     */
    public function listFiles(string $carpetaRemota): array
    {
        $token = $this->getAccessToken();
        $fullPath = "{$this->basePath}/{$carpetaRemota}";
        $endpoint = $this->buildDriveEndpoint("/root:/{$fullPath}:/children");

        $response = Http::withToken($token)->get($endpoint);

        if (!$response->successful()) {
            return [];
        }

        return collect($response->json('value', []))->map(fn($item) => [
            'id' => $item['id'],
            'name' => $item['name'],
            'size' => $item['size'] ?? 0,
            'webUrl' => $item['webUrl'] ?? null,
            'lastModified' => $item['lastModifiedDateTime'] ?? null,
        ])->toArray();
    }

    // =========================================================================
    // PRIVADOS
    // =========================================================================

    /**
     * Upload de archivos grandes (> 4MB) usando upload session.
     */
    private function uploadLargeFile(string $fullPath, string $contenido, string $token): array
    {
        // 1. Crear upload session
        $endpoint = $this->buildDriveEndpoint("/root:/{$fullPath}:/createUploadSession");

        $sessionResponse = Http::withToken($token)->post($endpoint, [
            'item' => [
                '@microsoft.graph.conflictBehavior' => 'replace',
            ],
        ]);

        if (!$sessionResponse->successful()) {
            throw new \Exception("Error creando upload session: " . $sessionResponse->body());
        }

        $uploadUrl = $sessionResponse->json('uploadUrl');
        $totalSize = strlen($contenido);
        $chunkSize = 5 * 1024 * 1024; // 5MB chunks
        $offset = 0;
        $result = null;

        // 2. Subir en chunks
        while ($offset < $totalSize) {
            $chunk = substr($contenido, $offset, $chunkSize);
            $endByte = min($offset + $chunkSize, $totalSize) - 1;

            $chunkResponse = Http::withHeaders([
                'Content-Length' => strlen($chunk),
                'Content-Range' => "bytes {$offset}-{$endByte}/{$totalSize}",
            ])->withBody($chunk, 'application/octet-stream')
              ->put($uploadUrl);

            if ($chunkResponse->status() === 200 || $chunkResponse->status() === 201) {
                $result = $chunkResponse->json();
                break;
            } elseif ($chunkResponse->status() !== 202) {
                throw new \Exception("Error en chunk upload: " . $chunkResponse->body());
            }

            $offset += $chunkSize;
        }

        return [
            'id' => $result['id'] ?? null,
            'webUrl' => $result['webUrl'] ?? null,
            'size' => $result['size'] ?? $totalSize,
            'name' => $result['name'] ?? basename($fullPath),
        ];
    }

    /**
     * Construye el endpoint del drive.
     * Si hay drive_id configurado, usa /drives/{driveId}
     * Si no, usa /me/drive (no funciona con app-only, necesita drive_id)
     */
    private function buildDriveEndpoint(string $path): string
    {
        if (!empty($this->driveId)) {
            return "{$this->graphUrl}/drives/{$this->driveId}{$path}";
        }

        // Fallback: site default drive (necesita site_id configurado)
        $siteId = config('services.microsoft_graph.site_id', '');
        if (!empty($siteId)) {
            return "{$this->graphUrl}/sites/{$siteId}/drive{$path}";
        }

        throw new \Exception(
            "Debe configurar GRAPH_DRIVE_ID o GRAPH_SITE_ID para usar Graph Storage con permisos de aplicación"
        );
    }
}
