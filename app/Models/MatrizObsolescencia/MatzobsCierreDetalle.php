<?php

namespace App\Models\MatrizObsolescencia;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelo: MatzobsCierreDetalle
 *
 * Snapshot inmutable de un activo dentro de un cierre de inventario.
 * Todos los campos de matzobs_activos_c y matzobs_activos_d relevantes
 * se copian aquí para garantizar que el historial no cambie aunque
 * el activo sea modificado o eliminado posteriormente.
 *
 * @property int         $id
 * @property int         $cierre_id
 * @property int|null    $activo_c_id
 * @property int|null    $id_activo_glpi
 * @property string|null $nombre_equipo
 * @property int|null    $id_empresa
 * @property string|null $nombre_empresa
 * @property int|null    $id_sucursal
 * @property string|null $nombre_sucursal
 * @property int|null    $id_sede
 * @property string|null $nombre_sede
 * @property string|null $agente
 * @property string|null $placa
 * @property string|null $serial
 * @property string|null $ubicacion
 * @property string|null $usuario_glpi
 * @property float       $puntaje
 * @property string      $estado_obsolescencia   optimo|funcional|potencial|obsoleto
 * @property string|null $marca
 * @property string|null $tipo
 * @property string|null $referencia
 * @property string|null $tipo_unidad
 * @property \Carbon\Carbon|null $fecha_compra
 * @property string|null $modalidad
 * @property string|null $proveedor
 * @property string|null $sistema_operativo
 * @property float|null  $edad
 * @property float|null  $edad_v_util
 * @property float|null  $valoracion_edad
 * @property float|null  $tamano_ram
 * @property float|null  $max_ram
 * @property string|null $generacion_ram
 * @property float|null  $valoracion_ram
 * @property string|null $procesador
 * @property int|null    $numero_procesador
 * @property float|null  $valoracion_procesador
 * @property string|null $tipo_disco
 * @property float|null  $tamano_disco
 * @property string|null $interfaz_conexion
 * @property float|null  $valoracion_disco
 * @property int         $incidencias_6_meses
 */
class MatzobsCierreDetalle extends Model
{
    use HasFactory;

    protected $table = 'matzobs_cierre_detalle';

    protected $fillable = [
        'cierre_id',
        'activo_c_id',
        // Snapshot activos_c
        'id_activo_glpi',
        'nombre_equipo',
        'id_empresa',
        'nombre_empresa',
        'id_sucursal',
        'nombre_sucursal',
        'id_sede',
        'nombre_sede',
        'agente',
        'placa',
        'serial',
        'ubicacion',
        'usuario_glpi',
        'puntaje',
        'estado_obsolescencia',
        // Snapshot activos_d
        'marca',
        'tipo',
        'referencia',
        'tipo_unidad',
        'fecha_compra',
        'modalidad',
        'proveedor',
        'sistema_operativo',
        'edad',
        'edad_v_util',
        'valoracion_edad',
        'tamano_ram',
        'max_ram',
        'generacion_ram',
        'valoracion_ram',
        'procesador',
        'numero_procesador',
        'valoracion_procesador',
        'tipo_disco',
        'tamano_disco',
        'interfaz_conexion',
        'valoracion_disco',
        'incidencias_6_meses',
    ];

    protected $casts = [
        'fecha_compra'          => 'date',
        'puntaje'               => 'decimal:2',
        'edad'                  => 'decimal:1',
        'edad_v_util'           => 'float',
        'valoracion_edad'       => 'decimal:2',
        'tamano_ram'            => 'decimal:2',
        'max_ram'               => 'decimal:2',
        'valoracion_ram'        => 'decimal:2',
        'numero_procesador'     => 'integer',
        'valoracion_procesador' => 'decimal:2',
        'tamano_disco'          => 'decimal:2',
        'valoracion_disco'      => 'decimal:2',
        'incidencias_6_meses'   => 'integer',
    ];

    // ─── Relaciones ───────────────────────────────────────────────────────────

    /**
     * Cierre al que pertenece este detalle.
     */
    public function cierre(): BelongsTo
    {
        return $this->belongsTo(MatzobsCierre::class, 'cierre_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopePorEmpresa($query, int $empresaId)
    {
        return $query->where('id_empresa', $empresaId);
    }

    public function scopePorSucursal($query, int $sucursalId)
    {
        return $query->where('id_sucursal', $sucursalId);
    }

    public function scopePorSede($query, int $sedeId)
    {
        return $query->where('id_sede', $sedeId);
    }

    public function scopePorEstado($query, string $estado)
    {
        return $query->where('estado_obsolescencia', $estado);
    }

    public function scopeOptimos($query)
    {
        return $query->where('estado_obsolescencia', 'optimo');
    }

    public function scopeObsoletos($query)
    {
        return $query->where('estado_obsolescencia', 'obsoleto');
    }

    // ─── Helpers estáticos ────────────────────────────────────────────────────

    /**
     * Calcula el estado de obsolescencia a partir del puntaje.
     * Centralizado aquí para que el Job y el Controller usen la misma lógica.
     */
    public static function calcularEstado(float|null $puntaje): string
    {
        $p = (float) ($puntaje ?? 0);
        if ($p >= 100)           return 'optimo';
        if ($p >= 60 && $p < 100) return 'funcional';
        if ($p > 0  && $p < 60)  return 'potencial';
        return 'obsoleto';
    }

    /**
     * Construye el array de datos para insertar desde un activo y su detalle.
     * Recibe los objetos Eloquent de matzobs_activos_c y matzobs_activos_d.
     */
    public static function fromActivo(
        int    $cierreId,
        object $activoC,
        object|null $activoD
    ): array {
        return [
            'cierre_id'             => $cierreId,
            'activo_c_id'           => $activoC->id,
            // Snapshot C
            'id_activo_glpi'        => $activoC->id_activo_glpi,
            'nombre_equipo'         => $activoC->nombre_equipo,
            'id_empresa'            => $activoC->id_empresa,
            'nombre_empresa'        => $activoC->empresa?->nombre,
            'id_sucursal'           => $activoC->id_sucursal,
            'nombre_sucursal'       => $activoC->sucursal?->nombre,
            'id_sede'               => $activoC->id_sede,
            'nombre_sede'           => $activoC->sede?->nombre,
            'agente'                => $activoC->agente,
            'placa'                 => $activoC->placa,
            'serial'                => $activoC->serial,
            'ubicacion'             => $activoC->ubicacion,
            'usuario_glpi'          => $activoC->usuario_glpi ?? null,
            'puntaje'               => $activoC->puntaje ?? 0,
            'estado_obsolescencia'  => self::calcularEstado($activoC->puntaje),
            // Snapshot D
            'marca'                 => $activoD?->marca,
            'tipo'                  => $activoD?->tipo,
            'referencia'            => $activoD?->referencia,
            'tipo_unidad'           => $activoD?->tipo_unidad,
            'fecha_compra'          => $activoD?->fecha_compra,
            'modalidad'             => $activoD?->modalidad,
            'proveedor'             => $activoD?->proveedor,
            'sistema_operativo'     => $activoD?->sistema_operativo ?? null,
            'edad'                  => $activoD?->edad,
            'edad_v_util'           => $activoD?->edad_v_util,
            'valoracion_edad'       => $activoD?->valoracion_edad,
            'tamano_ram'            => $activoD?->tamano_ram,
            'max_ram'               => $activoD?->max_ram,
            'generacion_ram'        => $activoD?->generacion_ram,
            'valoracion_ram'        => $activoD?->valoracion_ram,
            'procesador'            => $activoD?->procesador,
            'numero_procesador'     => $activoD?->numero_procesador,
            'valoracion_procesador' => $activoD?->valoracion_procesador,
            'tipo_disco'            => $activoD?->tipo_disco,
            'tamano_disco'          => $activoD?->tamano_disco,
            'interfaz_conexion'     => $activoD?->interfaz_conexion,
            'valoracion_disco'      => $activoD?->valoracion_disco,
            'incidencias_6_meses'   => $activoD?->incidencias_6_meses ?? 0,
            'created_at'            => now(),
            'updated_at'            => now(),
        ];
    }
}
