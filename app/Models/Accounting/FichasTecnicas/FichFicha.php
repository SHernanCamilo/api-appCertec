<?php

declare(strict_types=1);

namespace App\Models\Accounting\FichasTecnicas;

use App\Enums\FichasTecnicas\EstadoFicha;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ficha técnica médica (entidad principal del módulo).
 *
 * Legacy: tabla `ficha`.
 *
 * Los `scope*` de esta clase reemplazan las 12 funciones `listar*()`
 * duplicadas en `config/config_borradores.php`, `config_proc.php`,
 * `config_rech.php` y `config_finalizadas.php` del sistema JADE.
 *
 * @property int         $id
 * @property string|null $consecutivo
 * @property int|null    $id_padre
 * @property int         $version
 * @property int|null    $id_empresa
 * @property int|null    $id_sucursal
 * @property string|null $sucursal_legacy
 * @property int         $id_agremiacion
 * @property int         $id_objeto_contrato
 * @property int         $id_especialidad
 * @property string      $vlr_contrato
 * @property \Illuminate\Support\Carbon $fecha_ini
 * @property \Illuminate\Support\Carbon $fecha_fin
 * @property int         $id_estado
 * @property int         $id_user_reg
 * @property int         $total_detalles
 * @property string      $valor_total_detalles
 * @property int         $total_profesionales
 */
class FichFicha extends Model
{
    use SoftDeletes;

    protected $table = 'fich_fichas';

    protected $fillable = [
        'consecutivo',
        'id_padre',
        'reemplazada_por_id',
        'version',
        'id_empresa',
        'id_sucursal',
        'sucursal_legacy',
        'id_agremiacion',
        'id_objeto_contrato',
        'id_especialidad',
        'vlr_contrato',
        'fecha_ini',
        'fecha_fin',
        'id_estado',
        'wf_instancia_id',
        'id_user_reg',
        'fecha_reg',
        'fecha_envio_flujo',
        'ciclos_flujo',
        'user_autoriza_id',
        'fecha_autoriza',
        'obs_autoriza',
        'user_aprueba_id',
        'fecha_aprueba',
        'obs_aprueba',
        'fecha_vigencia_inicio',
        'obs_os',
        'motivo_modificacion',
        'novedad',
    ];

    /**
     * Los contadores los mantienen los triggers de base de datos:
     * se exponen como solo lectura para evitar escrituras inconsistentes.
     */
    protected $guarded = [
        'id',
        'total_detalles',
        'valor_total_detalles',
        'total_profesionales',
    ];

    protected $casts = [
        'version'               => 'integer',
        'ciclos_flujo'          => 'integer',
        'vlr_contrato'          => 'decimal:2',
        'valor_total_detalles'  => 'decimal:2',
        'total_detalles'        => 'integer',
        'total_profesionales'   => 'integer',
        'fecha_ini'             => 'date',
        'fecha_fin'             => 'date',
        'fecha_reg'             => 'datetime',
        'fecha_envio_flujo'     => 'datetime',
        'fecha_autoriza'        => 'datetime',
        'fecha_aprueba'         => 'datetime',
        'fecha_vigencia_inicio' => 'datetime',
    ];

    protected $appends = ['dias_restantes', 'vigencia_estado', 'estado_codigo'];

    // ─────────────────────────────────────────────────────────────────────
    // Relaciones
    // ─────────────────────────────────────────────────────────────────────

    public function estado(): BelongsTo
    {
        return $this->belongsTo(FichEstado::class, 'id_estado');
    }

    public function agremiacion(): BelongsTo
    {
        return $this->belongsTo(FichAgremiacion::class, 'id_agremiacion');
    }

    public function especialidad(): BelongsTo
    {
        return $this->belongsTo(FichEspecialidad::class, 'id_especialidad');
    }

    public function objetoContrato(): BelongsTo
    {
        return $this->belongsTo(FichObjetoContrato::class, 'id_objeto_contrato');
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'id_empresa');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class, 'id_sucursal');
    }

    public function generador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id_user_reg');
    }

    public function autorizador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_autoriza_id');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_aprueba_id');
    }

    /** Ficha original cuando este registro es una actualización (OS). */
    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'id_padre');
    }

    /** Actualizaciones (OS) generadas a partir de esta ficha. */
    public function versiones(): HasMany
    {
        return $this->hasMany(self::class, 'id_padre')->orderBy('version');
    }

    /**
     * Versión que reemplaza a esta ficha.
     *
     * Se fija cuando una solicitud de modificación es aprobada: la ficha
     * anterior conserva su historial de vigencia y queda enlazada a la nueva.
     */
    public function reemplazadaPor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reemplazada_por_id');
    }

    /** Instancia activa en el motor de flujos. */
    public function instanciaWorkflow(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Workflow\WfInstancia::class, 'wf_instancia_id');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(FichDetalle::class, 'id_ficha');
    }

    public function profesionales(): BelongsToMany
    {
        return $this->belongsToMany(
            FichProfesional::class,
            'fich_ficha_profesional',
            'id_ficha',
            'id_profesional'
        )->withPivot('novedad')->withTimestamps();
    }

    public function observaciones(): HasMany
    {
        return $this->hasMany(FichObservacion::class, 'id_ficha');
    }

    public function comentarios(): HasMany
    {
        return $this->hasMany(FichComentario::class, 'id_ficha')->latest();
    }

    public function historialEstados(): HasMany
    {
        return $this->hasMany(FichHistorialEstado::class, 'id_ficha')->latest();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Atributos derivados
    // ─────────────────────────────────────────────────────────────────────

    public function getDiasRestantesAttribute(): ?int
    {
        if ($this->fecha_fin === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->fecha_fin->startOfDay(), false);
    }

    /** VIGENTE | PROXIMA | ALERTA | CRITICA | VENCIDA */
    public function getVigenciaEstadoAttribute(): ?string
    {
        $dias = $this->dias_restantes;

        if ($dias === null) {
            return null;
        }

        return match (true) {
            $dias < 0   => 'VENCIDA',
            $dias <= 10 => 'CRITICA',
            $dias <= 15 => 'ALERTA',
            $dias <= 30 => 'PROXIMA',
            default     => 'VIGENTE',
        };
    }

    public function getEstadoCodigoAttribute(): ?string
    {
        return EstadoFicha::tryFromId((int) $this->id_estado)?->value;
    }

    public function estadoEnum(): EstadoFicha
    {
        return EstadoFicha::fromId((int) $this->id_estado);
    }

    public function esActualizacion(): bool
    {
        return $this->id_padre !== null;
    }

    /** El generador puede editar la ficha en su estado actual. */
    public function esEditable(): bool
    {
        return $this->estadoEnum()->esEditable();
    }

    /**
     * La ficha admite que el generador solicite una modificación, lo que crea
     * una nueva versión y reinicia el flujo de aprobación.
     */
    public function permiteSolicitarModificacion(): bool
    {
        return $this->estadoEnum()->permiteSolicitarModificacion()
            && $this->reemplazada_por_id === null;
    }

    /** Vigencia expirada según `fecha_fin`. */
    public function estaVencida(): bool
    {
        return $this->estadoEnum()->cuentaVigencia()
            && $this->fecha_fin !== null
            && $this->fecha_fin->startOfDay()->isBefore(now()->startOfDay());
    }

    /** La vigencia contractual ya arrancó. */
    public function vigenciaIniciada(): bool
    {
        return $this->fecha_ini !== null
            && ! $this->fecha_ini->startOfDay()->isAfter(now()->startOfDay());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Scopes de consulta
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Filtra por un grupo de estados del enum.
     *
     * @param  list<EstadoFicha>  $estados
     */
    public function scopeEnEstados(Builder $query, array $estados): Builder
    {
        return $query->whereIn('id_estado', EstadoFicha::ids($estados));
    }

    public function scopeBorradores(Builder $query): Builder
    {
        return $query->enEstados(EstadoFicha::borradores());
    }

    /** Fichas con una decisión pendiente en el flujo. */
    public function scopeEnProceso(Builder $query): Builder
    {
        return $query->enEstados(EstadoFicha::enProceso());
    }

    /** Bandeja del Director Médico. */
    public function scopePorAutorizar(Builder $query): Builder
    {
        return $query->enEstados(EstadoFicha::pendientesAutorizacion());
    }

    /** Bandeja del VP Financiero. */
    public function scopePorAprobar(Builder $query): Builder
    {
        return $query->enEstados(EstadoFicha::pendientesRevisionFinanciera());
    }

    /** Devoluciones pendientes de corrección por el generador. */
    public function scopeRechazadas(Builder $query): Builder
    {
        return $query->enEstados(EstadoFicha::correccionRequerida());
    }

    /** Aprobadas y vigentes: tienen valor contractual. */
    public function scopeFinalizadas(Builder $query): Builder
    {
        return $query->enEstados(EstadoFicha::finalizadas());
    }

    public function scopeAprobadas(Builder $query): Builder
    {
        return $query->enEstados(EstadoFicha::aprobadas());
    }

    public function scopeCanceladas(Builder $query): Builder
    {
        return $query->enEstados(EstadoFicha::canceladas());
    }

    /** Con vigencia contractual activa a la fecha. */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->finalizadas()->whereDate('fecha_fin', '>=', now()->toDateString());
    }

    public function scopeVencidas(Builder $query): Builder
    {
        return $query->finalizadas()->whereDate('fecha_fin', '<', now()->toDateString());
    }

    /**
     * Aprobadas cuya vigencia ya arrancó y aún no fueron marcadas como vigentes.
     *
     * Alimenta el comando programado que promueve `aprobada` → `vigente`.
     */
    public function scopeListasParaVigencia(Builder $query): Builder
    {
        return $query->enEstados(EstadoFicha::aprobadas())
            ->whereDate('fecha_ini', '<=', now()->toDateString());
    }

    /** Sin una versión posterior que las reemplace. */
    public function scopeVersionActual(Builder $query): Builder
    {
        return $query->whereNull('reemplazada_por_id');
    }

    public function scopeProximasAVencer(Builder $query, int $dias = 30): Builder
    {
        return $query->finalizadas()
            ->whereDate('fecha_fin', '>=', now()->toDateString())
            ->whereDate('fecha_fin', '<=', now()->addDays($dias)->toDateString());
    }

    public function scopeDelGenerador(Builder $query, int $userId): Builder
    {
        return $query->where('id_user_reg', $userId);
    }

    public function scopeDeSucursal(Builder $query, int|string $sucursal): Builder
    {
        return is_int($sucursal)
            ? $query->where('id_sucursal', $sucursal)
            : $query->where('sucursal_legacy', $sucursal);
    }

    public function scopeDeEmpresa(Builder $query, int $idEmpresa): Builder
    {
        return $query->where('id_empresa', $idEmpresa);
    }

    /** Relaciones mínimas para un listado sin caer en N+1. */
    public function scopeParaListado(Builder $query): Builder
    {
        return $query->with([
            'estado:id,codigo,descripcion,tipo,color_hex,es_editable,es_final',
            'agremiacion:id,nombre,nit',
            'especialidad:id,descripcion,perfil',
            'objetoContrato:id,descripcion',
            'empresa:id,nombre,prefijo',
            'generador:id,name,email',
        ]);
    }
}
