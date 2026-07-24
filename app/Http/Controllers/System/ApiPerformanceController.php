<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;

/**
 * Dashboard de rendimiento de la API.
 * Muestra: jobs activos/fallidos, colas saturadas, uso de Redis, y rutas lentas.
 */
class ApiPerformanceController extends Controller
{
    /**
     * GET /api/system/performance
     * Resumen completo: Horizon, Redis, colas, jobs recientes.
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'horizon'  => $this->getHorizonMetrics(),
            'redis'    => $this->getRedisMetrics(),
            'queues'   => $this->getQueueMetrics(),
            'jobs'     => $this->getRecentJobs(),
            'system'   => $this->getSystemMetrics(),
        ]);
    }

    /**
     * GET /api/system/performance/horizon
     * Estado detallado de Horizon: supervisors, workers, jobs.
     */
    public function horizon(): JsonResponse
    {
        return response()->json($this->getHorizonMetrics());
    }

    /**
     * GET /api/system/performance/jobs
     * Últimos 50 jobs con tiempos, estados, y errores.
     */
    public function jobs(): JsonResponse
    {
        return response()->json($this->getRecentJobs());
    }

    /**
     * GET /api/system/performance/failed-jobs
     * Jobs fallidos con detalle del error.
     */
    public function failedJobs(): JsonResponse
    {
        $failed = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(50)
            ->get(['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']);

        $jobs = $failed->map(function ($job) {
            $payload = json_decode($job->payload, true);
            return [
                'id'         => $job->id,
                'uuid'       => $job->uuid,
                'queue'      => $job->queue,
                'job_class'  => $payload['displayName'] ?? 'Unknown',
                'error'      => substr($job->exception, 0, 300),
                'failed_at'  => $job->failed_at,
            ];
        });

        return response()->json([
            'total'  => DB::table('failed_jobs')->count(),
            'recent' => $jobs,
        ]);
    }

    private function getHorizonMetrics(): array
    {
        try {
            $prefix = config('horizon.prefix', 'horizon:');
            $redis = Redis::connection('default');

            // Horizon stats
            $status = Cache::get('horizon:status', 'unknown');
            $totalProcessed = (int) ($redis->get("{$prefix}total_processes") ?? 0);

            // Supervisors activos
            $supervisors = $redis->smembers("{$prefix}supervisors") ?? [];

            // Jobs en cada cola
            $queues = ['default', 'exports', 'sync', 'notifications'];
            $queueSizes = [];
            foreach ($queues as $queue) {
                $queueSizes[$queue] = (int) ($redis->llen("queues:{$queue}") ?? 0);
            }

            return [
                'status'       => $status,
                'supervisors'  => count($supervisors),
                'queue_sizes'  => $queueSizes,
                'total_pending' => array_sum($queueSizes),
            ];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function getRedisMetrics(): array
    {
        try {
            $info = Redis::connection('default')->info();

            return [
                'connected_clients' => (int) ($info['Clients']['connected_clients'] ?? $info['connected_clients'] ?? 0),
                'used_memory_human' => $info['Memory']['used_memory_human'] ?? $info['used_memory_human'] ?? '?',
                'total_commands'    => (int) ($info['Stats']['total_commands_processed'] ?? $info['total_commands_processed'] ?? 0),
                'keys'              => (int) ($info['Keyspace']['db0']['keys'] ?? 0),
                'uptime_days'       => round((int) ($info['Server']['uptime_in_seconds'] ?? $info['uptime_in_seconds'] ?? 0) / 86400, 1),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getQueueMetrics(): array
    {
        $queues = ['default', 'exports', 'sync', 'notifications'];
        $result = [];

        foreach ($queues as $queue) {
            $size = (int) (Redis::connection('default')->llen("queues:{$queue}") ?? 0);
            $result[] = [
                'name'    => $queue,
                'pending' => $size,
                'status'  => $size > 10 ? 'saturated' : ($size > 0 ? 'busy' : 'idle'),
            ];
        }

        return $result;
    }

    private function getRecentJobs(): array
    {
        try {
            // Horizon guarda los jobs completados recientes
            $prefix = config('horizon.prefix', 'horizon:');
            $redis = Redis::connection('default');

            $completedIds = $redis->zrevrange("{$prefix}completed_jobs", 0, 29) ?? [];
            $failedIds = $redis->zrevrange("{$prefix}failed_jobs", 0, 19) ?? [];

            $completed = [];
            foreach ($completedIds as $id) {
                $job = $redis->hmget("{$prefix}{$id}", ['type', 'status', 'name', 'completed_at', 'payload']);
                if ($job[0]) {
                    $payload = json_decode($job[4] ?? '{}', true);
                    $completed[] = [
                        'id'     => $id,
                        'name'   => $job[2] ?? $payload['displayName'] ?? 'Unknown',
                        'status' => $job[1] ?? 'completed',
                        'at'     => $job[3] ?? null,
                    ];
                }
            }

            return [
                'completed_count' => count($completedIds),
                'failed_count'    => count($failedIds),
                'recent'          => array_slice($completed, 0, 20),
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function getSystemMetrics(): array
    {
        $memFree = 0;
        $memTotal = 0;
        $loadAvg = [0, 0, 0];

        if (function_exists('sys_getloadavg')) {
            $loadAvg = sys_getloadavg() ?: [0, 0, 0];
        }

        // Leer /proc/meminfo si estamos en Linux
        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m)) $memTotal = (int) $m[1] * 1024;
            if (preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $m)) $memFree = (int) $m[1] * 1024;
        }

        return [
            'load_avg_1m'      => round($loadAvg[0], 2),
            'load_avg_5m'      => round($loadAvg[1], 2),
            'load_avg_15m'     => round($loadAvg[2], 2),
            'memory_total_gb'  => round($memTotal / 1073741824, 1),
            'memory_free_gb'   => round($memFree / 1073741824, 1),
            'memory_used_pct'  => $memTotal > 0 ? round((1 - $memFree / $memTotal) * 100, 1) : 0,
            'php_memory_limit' => ini_get('memory_limit'),
            'php_version'      => PHP_VERSION,
            'laravel_version'  => app()->version(),
        ];
    }
}
