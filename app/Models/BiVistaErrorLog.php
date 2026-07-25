<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Log de errores de vistas BI (timeout, fabric_error, etc.)
 * Se usa para:
 *   - Monitoreo en dashboard de metricas
 *   - Auto-mantenimiento de vistas fallidas
 *   - Notificaciones a admins BI
 */
class BiVistaErrorLog extends Model
{
    public $timestamps = false;

    protected $table = 'bi_vista_error_logs';

    protected $fillable = [
        'schema_name',
        'view_name',
        'error_type',
        'error_category',
        'error_message',
        'error_detail',
        'user_email',
        'department',
        'elapsed_ms',
        'auto_maintenance_applied',
        'notification_sent',
        'resolved_by',
        'resolved_at',
        'created_at',
    ];

    protected $casts = [
        'auto_maintenance_applied' => 'boolean',
        'notification_sent'        => 'boolean',
        'created_at'               => 'datetime',
        'resolved_at'              => 'datetime',
    ];

    // Tipos de error
    public const TYPE_TIMEOUT      = 'timeout';
    public const TYPE_FABRIC_ERROR = 'fabric_error';
    public const TYPE_PERMISSION   = 'permission';
    public const TYPE_UNKNOWN      = 'unknown';

    /**
     * Registra un error y aplica auto-mantenimiento si hay 3+ errores en 10 min.
     */
    public static function registrar(
        string $schema,
        string $view,
        string $errorType,
        string $message,
        ?string $detail = null,
        ?string $userEmail = null,
        ?string $department = null,
        ?int $elapsedMs = null,
        ?string $category = null,
    ): self {
        $log = self::create([
            'schema_name'    => $schema,
            'view_name'      => $view,
            'error_type'     => $errorType,
            'error_category' => $category ?? strtoupper($errorType),
            'error_message'  => $message,
            'error_detail'   => $detail ? substr($detail, 0, 2000) : null,
            'user_email'     => $userEmail,
            'department'     => $department,
            'elapsed_ms'     => $elapsedMs,
            'created_at'     => now(),
        ]);

        // Auto-mantenimiento: si hay 3+ errores en los ultimos 10 min para esta vista
        $recentErrors = self::where('schema_name', $schema)
            ->where('view_name', $view)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->count();

        if ($recentErrors >= 3) {
            $applied = self::aplicarMantenimiento($schema, $view);
            if ($applied) {
                $log->update(['auto_maintenance_applied' => true]);
            }
        }

        return $log;
    }

    /**
     * Pone la vista en estado "mantenimiento" en bi_vistas.
     */
    public static function aplicarMantenimiento(string $schema, string $view): bool
    {
        $biGrupo = BiGrupo::where('codigo', strtoupper($schema))->first();
        if (!$biGrupo) return false;

        $biVista = BiVista::where('id_bi_grupos', $biGrupo->id)
            ->where('nombre', $view)
            ->first();

        if (!$biVista) return false;

        // Solo aplicar si no esta ya en mantenimiento
        if (($biVista->estado ?? 'activo') === 'mantenimiento') {
            return false;
        }

        $biVista->update(['estado' => 'mantenimiento']);

        // Despachar notificacion por email a admins BI
        $recentError = self::where('schema_name', $schema)
            ->where('view_name', $view)
            ->latest('created_at')
            ->first();

        $errorCount = self::where('schema_name', $schema)
            ->where('view_name', $view)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->count();

        \App\Jobs\BiVistaErrorNotificationJob::dispatch(
            $schema,
            $view,
            $recentError->error_type ?? 'unknown',
            $recentError->error_message ?? 'Error recurrente detectado',
            $errorCount,
        );

        return true;
    }

    /**
     * Scope: errores recientes por schema/view
     */
    public function scopeParaVista($query, string $schema, string $view)
    {
        return $query->where('schema_name', $schema)->where('view_name', $view);
    }

    /**
     * Scope: solo no resueltos
     */
    public function scopeNoResueltos($query)
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Scope: filtro por rango de fechas
     */
    public function scopeEntreFechas($query, string $from, string $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }
}
