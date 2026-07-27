<?php

namespace App\Services\Fabric;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para acceder a Azure File Share (documentos PDF de imagenología).
 *
 * Soporta tres modos de acceso:
 *   1. MOUNT (CIFS): El share está montado localmente → lee directo del filesystem
 *   2. REST API (OAuth): Bearer token con rol "Storage File Data Privileged Reader"
 *   3. SMBCLIENT: Usa smbclient CLI para descargar archivos individuales
 *
 * Configuración (.env):
 *   AZURE_FILESHARE_MODE=mount|rest|smbclient
 *
 *   Para modo "mount":
 *     AZURE_FILESHARE_MOUNT_PATH=/mnt/documentosmedilaser
 *
 *   Para modo "rest":
 *     AZURE_FILESHARE_ACCOUNT=clinicamedilaserst
 *     AZURE_FILESHARE_SHARE=documentosmedilaser
 *     (Usa FABRIC_CLIENT_ID/SECRET/TENANT_ID - necesita rol asignado en Azure)
 *
 *   Para modo "smbclient":
 *     AZURE_FILESHARE_ACCOUNT=clinicamedilaserst
 *     AZURE_FILESHARE_SHARE=documentosmedilaser
 *     AZURE_FILESHARE_SMB_USER=clinicamedilaserst (storage account name)
 *     AZURE_FILESHARE_SMB_PASS=<Storage Account Key>
 */
class AzureFileShareService
{
    private const TOKEN_CACHE_KEY = 'azure_storage_token';
    private const TOKEN_TTL_MIN   = 55;

    private string $mode;
    private string $mountPath;
    private string $storageAccount;
    private string $shareName;
    private ?string $clientId;
    private ?string $clientSecret;
    private ?string $tenantId;
    private ?string $smbUser;
    private ?string $smbPass;

    public function __construct()
    {
        $this->mode           = env('AZURE_FILESHARE_MODE', 'mount');
        $this->mountPath      = rtrim(env('AZURE_FILESHARE_MOUNT_PATH', '/mnt/documentosmedilaser'), '/');
        $this->storageAccount = env('AZURE_FILESHARE_ACCOUNT', 'clinicamedilaserst');
        $this->shareName      = env('AZURE_FILESHARE_SHARE', 'documentosmedilaser');
        $this->clientId       = env('FABRIC_CLIENT_ID');
        $this->clientSecret   = env('FABRIC_CLIENT_SECRET');
        $this->tenantId       = env('FABRIC_TENANT_ID');
        $this->smbUser        = env('AZURE_FILESHARE_SMB_USER', $this->storageAccount);
        $this->smbPass        = env('AZURE_FILESHARE_SMB_PASS', '');
    }

    /**
     * Obtiene el contenido del archivo PDF.
     *
     * @param string $relativePath Ruta relativa dentro del share
     * @return array{content: string, size: int, mime: string}
     * @throws \RuntimeException
     */
    public function getFile(string $relativePath): array
    {
        $relativePath = $this->sanitizePath($relativePath);

        Log::info('AzureFileShareService: Solicitando archivo', [
            'mode' => $this->mode,
            'path' => $relativePath,
        ]);

        return match ($this->mode) {
            'mount'     => $this->getFileFromMount($relativePath),
            'rest'      => $this->getFileFromRest($relativePath),
            'smbclient' => $this->getFileFromSmbclient($relativePath),
            default     => throw new \RuntimeException("Modo no válido: {$this->mode}"),
        };
    }

    /**
     * Verifica si un archivo existe.
     */
    public function fileExists(string $relativePath): bool
    {
        $relativePath = $this->sanitizePath($relativePath);

        return match ($this->mode) {
            'mount'     => file_exists($this->mountPath . '/' . $relativePath),
            'rest'      => $this->checkFileExistsRest($relativePath),
            'smbclient' => $this->checkFileExistsSmbclient($relativePath),
            default     => false,
        };
    }

    // =========================================================================
    // MODO MOUNT (CIFS montado en /mnt/documentosmedilaser)
    // =========================================================================

    private function getFileFromMount(string $relativePath): array
    {
        $fullPath = $this->mountPath . '/' . $relativePath;

        if (!file_exists($fullPath)) {
            throw new \RuntimeException("Archivo no encontrado: {$relativePath}");
        }

        if (!is_readable($fullPath)) {
            throw new \RuntimeException("Sin permisos de lectura: {$relativePath}");
        }

        $content = file_get_contents($fullPath);
        if ($content === false) {
            throw new \RuntimeException("Error leyendo archivo: {$relativePath}");
        }

        return [
            'content' => $content,
            'size'    => strlen($content),
            'mime'    => mime_content_type($fullPath) ?: 'application/pdf',
        ];
    }

    // =========================================================================
    // MODO REST API (OAuth Bearer + x-ms-file-request-intent: backup)
    // Requiere rol "Storage File Data Privileged Reader" en el SP
    // API version: 2022-11-02 o superior
    // =========================================================================

    private function getFileFromRest(string $relativePath): array
    {
        $token = $this->getStorageToken();

        $url = sprintf(
            'https://%s.file.core.windows.net/%s/%s',
            $this->storageAccount,
            $this->shareName,
            $this->encodePathForAzure($relativePath)
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                'x-ms-version: 2022-11-02',
                'x-ms-file-request-intent: backup',
            ],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new \RuntimeException("Error cURL descargando archivo: {$curlErr}");
        }

        if ($httpCode === 403) {
            throw new \RuntimeException(
                'Acceso denegado. El Service Principal necesita el rol '
                . '"Storage File Data Privileged Reader" asignado en el Storage Account.'
            );
        }

        if ($httpCode === 404) {
            throw new \RuntimeException("Archivo no encontrado: {$relativePath}");
        }

        if ($httpCode !== 200) {
            Log::error('AzureFileShareService: Error REST', [
                'http_code' => $httpCode,
                'response'  => substr($response, 0, 500),
            ]);
            throw new \RuntimeException("Error descargando archivo (HTTP {$httpCode})");
        }

        return [
            'content' => $response,
            'size'    => strlen($response),
            'mime'    => $contentType ?: 'application/pdf',
        ];
    }

    private function checkFileExistsRest(string $relativePath): bool
    {
        try {
            $token = $this->getStorageToken();
            $url = sprintf(
                'https://%s.file.core.windows.net/%s/%s',
                $this->storageAccount,
                $this->shareName,
                $this->encodePathForAzure($relativePath)
            );

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY         => true,
                CURLOPT_HTTPHEADER     => [
                    "Authorization: Bearer {$token}",
                    'x-ms-version: 2022-11-02',
                    'x-ms-file-request-intent: backup',
                ],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    // =========================================================================
    // MODO SMBCLIENT (descarga via CLI smbclient — requiere Storage Account Key)
    // Útil cuando no se puede montar CIFS permanentemente
    // =========================================================================

    private function getFileFromSmbclient(string $relativePath): array
    {
        // smbclient usa backslashes en paths
        $smbPath = str_replace('/', '\\', $relativePath);
        $tempFile = tempnam(sys_get_temp_dir(), 'azpdf_');

        try {
            $share = sprintf(
                '//%s.file.core.windows.net/%s',
                $this->storageAccount,
                $this->shareName
            );

            // Construir comando smbclient
            $cmd = sprintf(
                'smbclient %s -U %s%%%s -m SMB3 -c %s 2>&1',
                escapeshellarg($share),
                escapeshellarg($this->smbUser),
                escapeshellarg($this->smbPass),
                escapeshellarg("get \"{$smbPath}\" \"{$tempFile}\"")
            );

            $output = [];
            $exitCode = 0;
            exec($cmd, $output, $exitCode);

            if ($exitCode !== 0) {
                $errorMsg = implode("\n", $output);

                if (str_contains($errorMsg, 'NT_STATUS_OBJECT_NAME_NOT_FOUND') ||
                    str_contains($errorMsg, 'NT_STATUS_NO_SUCH_FILE')) {
                    throw new \RuntimeException("Archivo no encontrado: {$relativePath}");
                }

                if (str_contains($errorMsg, 'NT_STATUS_LOGON_FAILURE') ||
                    str_contains($errorMsg, 'NT_STATUS_ACCESS_DENIED')) {
                    throw new \RuntimeException("Credenciales SMB inválidas o acceso denegado.");
                }

                Log::error('AzureFileShareService: smbclient error', [
                    'exit_code' => $exitCode,
                    'output'    => $errorMsg,
                    'path'      => $relativePath,
                ]);
                throw new \RuntimeException("Error smbclient (exit {$exitCode}): {$errorMsg}");
            }

            if (!file_exists($tempFile) || filesize($tempFile) === 0) {
                throw new \RuntimeException("smbclient no descargó el archivo: {$relativePath}");
            }

            $content = file_get_contents($tempFile);
            $size = strlen($content);

            return [
                'content' => $content,
                'size'    => $size,
                'mime'    => 'application/pdf',
            ];
        } finally {
            // Limpiar archivo temporal
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    private function checkFileExistsSmbclient(string $relativePath): bool
    {
        // Usar smbclient para verificar que el directorio padre contiene el archivo
        $dir = dirname(str_replace('/', '\\', $relativePath));
        $filename = basename($relativePath);

        $share = sprintf(
            '//%s.file.core.windows.net/%s',
            $this->storageAccount,
            $this->shareName
        );

        $cmd = sprintf(
            'smbclient %s -U %s%%%s -m SMB3 -c %s 2>&1',
            escapeshellarg($share),
            escapeshellarg($this->smbUser),
            escapeshellarg($this->smbPass),
            escapeshellarg("ls \"{$dir}\\{$filename}\"")
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        return $exitCode === 0;
    }

    // =========================================================================
    // TOKEN AZURE STORAGE (para modo REST)
    // =========================================================================

    private function getStorageToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, self::TOKEN_TTL_MIN * 60, function () {
            return $this->fetchStorageToken();
        });
    }

    private function fetchStorageToken(): string
    {
        $url = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope'         => 'https://storage.azure.com/.default',
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT    => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new \RuntimeException("Error cURL obteniendo token Storage: {$curlErr}");
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || empty($data['access_token'])) {
            $error = $data['error_description'] ?? $data['error'] ?? 'Error desconocido';
            Log::error('AzureFileShareService: Error token', compact('httpCode', 'error'));
            throw new \RuntimeException("Error token Azure Storage (HTTP {$httpCode}): {$error}");
        }

        Log::info('AzureFileShareService: Token Storage renovado');
        return $data['access_token'];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Sanitiza la ruta:
     *  - Remueve prefijo UNC (\\server\share\)
     *  - Normaliza backslashes a forward slashes
     *  - Remueve el nombre del share si viene incluido
     *  - Previene path traversal
     */
    private function sanitizePath(string $path): string
    {
        // Normalizar separadores
        $path = str_replace('\\', '/', $path);

        // Remover prefijo UNC: //clinicamedilaserst.file.core.windows.net/documentosmedilaser/
        $uncPattern = '#^//[^/]+/' . preg_quote($this->shareName, '#') . '/#i';
        $path = preg_replace($uncPattern, '', $path);

        // Si empieza con el nombre del share, removerlo
        $sharePrefix = $this->shareName . '/';
        if (stripos($path, $sharePrefix) === 0) {
            $path = substr($path, strlen($sharePrefix));
        }

        // Remover slash inicial
        $path = ltrim($path, '/');

        // Prevenir path traversal
        if (str_contains($path, '..')) {
            throw new \RuntimeException('Path traversal no permitido.');
        }

        return $path;
    }

    /**
     * Codifica cada segmento del path para Azure REST API.
     */
    private function encodePathForAzure(string $path): string
    {
        $segments = explode('/', $path);
        return implode('/', array_map('rawurlencode', $segments));
    }
}