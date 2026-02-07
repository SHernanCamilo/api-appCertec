<?php

namespace App\Models\MatrizObsolescencia;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MatzobsActivosD extends Model
{
    use HasFactory;

    protected $table = 'matzobs_activos_d';
    
    protected $fillable = [
        'activo_c_id', // Campo correcto según la BD
        'marca',
        'tipo',
        'referencia',
        'tipo_unidad',
        'fecha_compra',
        'modalidad',
        'proveedor',
        'edad',
        'edad_v_util',
        'valoracion_edad',
        'tamano_ram',
        'generacion_ram',
        'valoracion_ram',
        'procesador',
        'numero_procesador',
        'valoracion_procesador',
        'tipo_disco',
        'tamano_disco',
        'interfaz_conexion',
        'valoracion_disco',
        'incidencias_6_meses'
    ];

    protected $casts = [
        'fecha_compra' => 'date',
        'tamano_ram' => 'decimal:2',
        'numero_procesador' => 'integer',
        'tamano_disco' => 'decimal:2',
        'edad' => 'integer',
        'edad_v_util' => 'integer',
        'valoracion_edad' => 'decimal:2',
        'valoracion_ram' => 'decimal:2',
        'valoracion_procesador' => 'decimal:2',
        'valoracion_disco' => 'decimal:2',
        'incidencias_6_meses' => 'integer'
    ];

    /**
     * Relación con el activo principal
     */
    public function activo()
    {
        return $this->belongsTo(MatzobsActivosC::class, 'activo_c_id'); // Campo correcto
    }

    /**
     * Scope para filtrar por marca
     */
    public function scopePorMarca($query, $marca)
    {
        return $query->where('marca', $marca);
    }

    /**
     * Scope para filtrar por tipo
     */
    public function scopePorTipo($query, $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    /**
     * Scope para filtrar por tamaño de RAM mínimo
     */
    public function scopeRamMinima($query, $tamanoMinimo)
    {
        return $query->where('tamano_ram', '>=', $tamanoMinimo);
    }

    /**
     * Scope para filtrar por tamaño de disco mínimo
     */
    public function scopeDiscoMinimo($query, $tamanoMinimo)
    {
        return $query->where('tamano_disco', '>=', $tamanoMinimo);
    }

    /**
     * Accessor para obtener el tamaño de RAM formateado
     */
    public function getTamanoRamFormateadoAttribute()
    {
        if (!$this->tamano_ram) return 'No especificado';
        
        if ($this->tamano_ram >= 1024) {
            return round($this->tamano_ram / 1024, 1) . ' TB';
        }
        
        return $this->tamano_ram . ' GB';
    }

    /**
     * Accessor para obtener el tamaño de disco formateado
     */
    public function getTamanoDiscoFormateadoAttribute()
    {
        if (!$this->tamano_disco) return 'No especificado';
        
        if ($this->tamano_disco >= 1024) {
            return round($this->tamano_disco / 1024, 1) . ' TB';
        }
        
        return $this->tamano_disco . ' GB';
    }

    /**
     * Accessor para obtener información completa del procesador
     */
    public function getProcesadorCompletoAttribute()
    {
        $info = [];
        
        if ($this->procesador) {
            $info[] = $this->procesador;
        }
        
        if ($this->numero_procesador && $this->numero_procesador > 1) {
            $info[] = "({$this->numero_procesador} núcleos)";
        }
        
        return !empty($info) ? implode(' ', $info) : 'No especificado';
    }

    /**
     * Accessor para obtener información completa de almacenamiento
     */
    public function getAlmacenamientoCompletoAttribute()
    {
        $info = [];
        
        if ($this->tamano_disco) {
            $info[] = $this->tamano_disco_formateado;
        }
        
        if ($this->tipo_disco) {
            $info[] = $this->tipo_disco;
        }
        
        if ($this->interfaz_conexion) {
            $info[] = "({$this->interfaz_conexion})";
        }
        
        return !empty($info) ? implode(' ', $info) : 'No especificado';
    }
}