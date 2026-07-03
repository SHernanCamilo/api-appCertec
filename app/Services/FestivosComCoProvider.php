<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * Proveedor de festivos para festivos.com.co
 * 
 * API Documentation: https://www.festivos.com.co/api/v1/docs
 */
class FestivosComCoProvider
{
    private string $baseUrl = 'https://www.festivos.com.co/api/v1';
    
    /**
     * Obtener festivos de un año específico
     */
    public function obtenerFestivos(int $anio): array
    {
        try {
            $apiKey = config('services.festivos.key');
            
            if (!$apiKey) {
                Log::warning('FESTIVOS_API_KEY no configurada en .env');
                return $this->obtenerFestivosFallback($anio);
            }

            Log::info("Consultando festivos.com.co para año {$anio}");
            
            $response = Http::withToken($apiKey)
                ->timeout(10)
                ->get("{$this->baseUrl}/festivos", [
                    'year' => $anio
                ]);

            Log::info('Respuesta de API festivos.com.co', [
                'status' => $response->status(),
                'body_length' => strlen($response->body())
            ]);

            if (!$response->successful()) {
                Log::error('Error en API festivos.com.co', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return $this->obtenerFestivosFallback($anio);
            }

            $data = $response->json();
            
            if (!isset($data['data']) || !is_array($data['data'])) {
                Log::warning('Respuesta inesperada de API festivos.com.co', [
                    'response' => $data,
                    'keys' => array_keys($data ?? [])
                ]);
                return $this->obtenerFestivosFallback($anio);
            }

            if (empty($data['data'])) {
                Log::warning('API festivos.com.co retornó array vacío', [
                    'anio' => $anio,
                    'response' => $data
                ]);
                return $this->obtenerFestivosFallback($anio);
            }

            return collect($data['data'])->map(function ($festivo) {
                return [
                    'fecha' => $festivo['date'] ?? $festivo['fecha'],
                    'nombre' => $festivo['name_es'] ?? $festivo['name'] ?? $festivo['nombre'] ?? $festivo['title'],
                    'tipo' => $festivo['type'] ?? 'festivo'
                ];
            })->toArray();

        } catch (\Exception $e) {
            Log::error('Excepción al consultar festivos.com.co', [
                'error' => $e->getMessage(),
                'anio' => $anio
            ]);
            return $this->obtenerFestivosFallback($anio);
        }
    }

    /**
     * Verificar si una fecha específica es festivo
     */
    public function esFestivo(string $fecha): bool
    {
        try {
            $carbon = Carbon::parse($fecha);
            $anio = $carbon->year;
            
            $festivos = $this->obtenerFestivos($anio);
            
            return collect($festivos)->contains(function ($festivo) use ($fecha) {
                return $festivo['fecha'] === $fecha;
            });
            
        } catch (\Exception $e) {
            Log::error('Error verificando si es festivo', [
                'fecha' => $fecha,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Obtener festivos de un mes específico
     */
    public function obtenerFestivosMes(int $anio, int $mes): array
    {
        $festivosAnio = $this->obtenerFestivos($anio);
        
        return collect($festivosAnio)->filter(function ($festivo) use ($anio, $mes) {
            $fechaFestivo = Carbon::parse($festivo['fecha']);
            return $fechaFestivo->year === $anio && $fechaFestivo->month === $mes;
        })->values()->toArray();
    }

    /**
     * Test de conectividad con la API
     */
    public function testConexion(): array
    {
        try {
            $apiKey = config('services.festivos.key');
            
            if (!$apiKey) {
                return [
                    'success' => false,
                    'message' => 'API Key no configurada'
                ];
            }

            $anioActual = Carbon::now()->year;
            $response = Http::withToken($apiKey)
                ->timeout(5)
                ->get("{$this->baseUrl}/festivos", [
                    'year' => $anioActual
                ]);

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'message' => $response->successful() 
                    ? 'Conexión exitosa con festivos.com.co' 
                    : 'Error de conexión: ' . $response->body(),
                'data' => $response->successful() ? $response->json() : null
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Excepción: ' . $e->getMessage()
            ];
        }
    }
}