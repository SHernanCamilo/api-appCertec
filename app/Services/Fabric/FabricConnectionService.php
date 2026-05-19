<?php

namespace App\Services\Fabric;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Gestiona la conexión a Microsoft Fabric via Service Principal (OAuth2).
 *
 * Flujo:
 *   1. Obtiene Access Token de Azure AD (cache 55 min)
 *   2. Conecta a SQL Server usando el token como AccessToken
 *   3. Retorna un recurso sqlsrv listo para consultas
 */
class FabricConnectionService
{
    private const TOKEN_CACHE_KEY = 'fabric_aad_token';
    private const TOKEN_TTL_MIN   = 55; // El token dura 60 min, renovamos a los 55

    private string $host;
    private int    $port;
    private string $database;
    private string $clientId;
    private string $clientSecret;
    private string $tenantId;

    public function __construct()
    {
        $this->host         = config('database.connections.fabric.host');
        $this->port         = (int) config('database.connections.fabric.port', 1433);
        $this->database     = config('database.connections.fabric.database');
        $this->clientId     = env('FABRIC_CLIENT_ID');
        $this->clientSecret = env('FABRIC_CLIENT_SECRET');
        $this->tenantId     = env('FABRIC_TENANT_ID');
    }

    /**
     * Obtiene un token AAD (con cache).
     */
    public function getAccessToken(): string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, self::TOKEN_TTL_MIN * 60, function () {
            return $this->fetchNewToken();
        });
    }

    /**
     * Solicita un nuevo token a Azure AD.
     */
    private function fetchNewToken(): string
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
                'scope'         => 'https://database.windows.net/.default',
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
            throw new \RuntimeException("Error cURL al obtener token AAD: {$curlErr}");
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || empty($data['access_token'])) {
            $error = $data['error_description'] ?? $data['error'] ?? 'Error desconocido';
            throw new \RuntimeException("Error obteniendo token AAD (HTTP {$httpCode}): {$error}");
        }

        Log::info('FabricConnectionService: Token AAD renovado', [
            'expires_in' => $data['expires_in'] ?? 'N/A',
        ]);

        return $data['access_token'];
    }

    /**
     * Abre una conexión sqlsrv a Fabric usando AccessToken.
     *
     * @return resource
     * @throws \RuntimeException
     */
    public function connect(): mixed
    {
        $token = $this->getAccessToken();

        $serverString = "{$this->host},{$this->port}";

        $connOptions = [
            'Database'               => $this->database,
            'Encrypt'                => true,
            'TrustServerCertificate' => false,
            'AccessToken'            => $token,
            'LoginTimeout'           => 30,
            'CharacterSet'           => 'UTF-8',
        ];

        $conn = sqlsrv_connect($serverString, $connOptions);

        if (!$conn) {
            // Limpiar token cacheado por si expiró antes de tiempo
            Cache::forget(self::TOKEN_CACHE_KEY);

            $errors = sqlsrv_errors(SQLSRV_ERR_ALL) ?? [];
            $msg = collect($errors)->pluck('message')->implode(' | ');
            throw new \RuntimeException("No se pudo conectar a Microsoft Fabric: {$msg}");
        }

        return $conn;
    }

    /**
     * Ejecuta una query y retorna los resultados como array.
     *
     * @param string $sql
     * @param array  $params Parámetros para query parametrizada
     * @return array
     */
    public function query(string $sql, array $params = []): array
    {
        $conn = $this->connect();

        try {
            $stmt = empty($params)
                ? sqlsrv_query($conn, $sql)
                : sqlsrv_query($conn, $sql, $params);

            if (!$stmt) {
                $errors = sqlsrv_errors(SQLSRV_ERR_ALL) ?? [];
                $msg = collect($errors)->pluck('message')->implode(' | ');
                throw new \RuntimeException("Error en query Fabric: {$msg}");
            }

            $results = [];
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                // Convertir DateTime a string
                foreach ($row as $key => $value) {
                    if ($value instanceof \DateTime) {
                        $row[$key] = $value->format('Y-m-d H:i:s');
                    }
                }
                $results[] = $row;
            }

            sqlsrv_free_stmt($stmt);
            return $results;

        } finally {
            sqlsrv_close($conn);
        }
    }

    /**
     * Invalida el token cacheado (útil si hay error de autenticación).
     */
    public function clearTokenCache(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
    }
}
