<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo para gestionar permisos de Cuadro de Turnos por usuario/empresa/sede
 * 
 * Tabla: seg_cuadro_turno_permisos
 */
class CuadroTurnoPermiso extends Model
{
    protected $table = 'seg_cuadro_turno_permisos';

    protected $fillable = [
        'user_id',
        'id_empresa',
        'id_sede',
        'tipo_permiso',
        'activo',
        'creado_por',
        'actualizado_por',
    ];

    protected $casts = [
        'activo' => 'boolean',
    ];

    // =========================================================================
    // RELACIONES
    // =========================================================================

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'id_sede');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopePorUsuario($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopePorEmpresa($query, int $empresaId)
    {
        return $query->where('id_empresa', $empresaId);
    }

    public function scopePorSede($query, int $sedeId)
    {
        return $query->where('id_sede', $sedeId);
    }

    public function scopePorTipoPermiso($query, string $tipoPermiso)
    {
        return $query->where('tipo_permiso', $tipoPermiso);
    }

    // =========================================================================
    // MÉTODOS ÚTILES
    // =========================================================================

    /**
     * Verifica si el usuario tiene permiso para una empresa/sede específica
     */
    public static function usuarioTienePermiso(int $userId, int $empresaId, ?int $sedeId = null, string $tipoPermiso = 'visualizar'): bool
    {
        $query = self::activos()
            ->where('user_id', $userId)
            ->where('id_empresa', $empresaId)
            ->where('tipo_permiso', $tipoPermiso);

        // Si se especifica sede, buscar permiso específico o permiso general (sin sede)
        if ($sedeId) {
            $query->where(function ($q) use ($sedeId) {
                $q->where('id_sede', $sedeId)
                  ->orWhereNull('id_sede');
            });
        } else {
            // Si no se especifica sede, solo buscar permisos sin sede (aplica a todas)
            $query->whereNull('id_sede');
        }

        return $query->exists();
    }

    /**
     * Obtiene todos los permisos de un usuario
     */
    public static function permisosDelUsuario(int $userId)
    {
        return self::activos()
            ->porUsuario($userId)
            ->with(['empresa', 'sede'])
            ->get();
    }
}
