<?php

namespace App\Models\GLPI;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class GLPIComputer extends Model
{
    /**
     * Este modelo no usa base de datos local, es solo para estructurar datos de GLPI
     */
    protected $connection = null;
    
    protected $fillable = [
        'id',
        'name',
        'serial',
        'otherserial',
        'locations_id',
        'locations_name',
        'users_id',
        'users_name',
        'groups_id',
        'groups_name',
        'states_id',
        'states_name',
        'manufacturers_id',
        'manufacturers_name',
        'computertypes_id',
        'computertypes_name',
        'computermodels_id',
        'computermodels_name',
        'operatingsystems_id',
        'operatingsystems_name',
        'operatingsystemversions_id',
        'operatingsystemversions_name',
        'operatingsystemservicepacks_id',
        'operatingsystemservicepacks_name',
        'comment',
        'date_creation',
        'date_mod',
        'is_deleted',
        'is_template',
        'entities_id',
        'entities_name'
    ];

    protected $casts = [
        'date_creation' => 'datetime',
        'date_mod' => 'datetime',
        'is_deleted' => 'boolean',
        'is_template' => 'boolean',
    ];

    /**
     * Crear instancia desde datos de GLPI API
     */
    public static function fromGLPIData(array $data): self
    {
        $computer = new self();
        
        // Mapear campos básicos
        $computer->fill([
            'id' => $data['id'] ?? null,
            'name' => $data['name'] ?? null,
            'serial' => $data['serial'] ?? null,
            'otherserial' => $data['otherserial'] ?? null,
            'comment' => $data['comment'] ?? null,
            'date_creation' => isset($data['date_creation']) ? Carbon::parse($data['date_creation']) : null,
            'date_mod' => isset($data['date_mod']) ? Carbon::parse($data['date_mod']) : null,
            'is_deleted' => $data['is_deleted'] ?? false,
            'is_template' => $data['is_template'] ?? false,
        ]);

        // Mapear campos con relaciones (IDs y nombres)
        $relationFields = [
            'locations' => 'Ubicación',
            'users' => 'Usuario',
            'groups' => 'Grupo',
            'states' => 'Estado',
            'manufacturers' => 'Fabricante',
            'computertypes' => 'Tipo de Computadora',
            'computermodels' => 'Modelo de Computadora',
            'operatingsystems' => 'Sistema Operativo',
            'operatingsystemversions' => 'Versión del SO',
            'operatingsystemservicepacks' => 'Service Pack del SO',
            'entities' => 'Entidad'
        ];

        foreach ($relationFields as $field => $description) {
            $idField = $field . '_id';
            $nameField = $field . '_name';
            
            $computer->$idField = $data[$idField] ?? null;
            $computer->$nameField = $data[$nameField] ?? null;
        }

        return $computer;
    }

    /**
     * Convertir a array para envío a GLPI API
     */
    public function toGLPIArray(): array
    {
        $data = [];
        
        // Campos básicos
        if ($this->name) $data['name'] = $this->name;
        if ($this->serial) $data['serial'] = $this->serial;
        if ($this->otherserial) $data['otherserial'] = $this->otherserial;
        if ($this->comment) $data['comment'] = $this->comment;
        
        // Campos de relación (solo IDs)
        $relationFields = [
            'locations_id',
            'users_id',
            'groups_id',
            'states_id',
            'manufacturers_id',
            'computertypes_id',
            'computermodels_id',
            'operatingsystems_id',
            'operatingsystemversions_id',
            'operatingsystemservicepacks_id',
            'entities_id'
        ];

        foreach ($relationFields as $field) {
            if ($this->$field !== null) {
                $data[$field] = $this->$field;
            }
        }

        return $data;
    }

    /**
     * Obtener información resumida para listas
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'serial' => $this->serial,
            'location' => $this->locations_name,
            'user' => $this->users_name,
            'state' => $this->states_name,
            'manufacturer' => $this->manufacturers_name,
            'model' => $this->computermodels_name,
            'os' => $this->operatingsystems_name,
            'os_version' => $this->operatingsystemversions_name,
            'last_update' => $this->date_mod?->format('Y-m-d H:i:s'),
            'created' => $this->date_creation?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Calcular antigüedad en años
     */
    public function getAgeInYears(): ?float
    {
        if (!$this->date_creation) {
            return null;
        }

        return $this->date_creation->diffInYears(now());
    }

    /**
     * Verificar si la computadora está activa
     */
    public function isActive(): bool
    {
        return !$this->is_deleted && !$this->is_template;
    }

    /**
     * Obtener estado de obsolescencia basado en antigüedad
     */
    public function getObsolescenceStatus(): array
    {
        $age = $this->getAgeInYears();
        
        if ($age === null) {
            return [
                'status' => 'unknown',
                'message' => 'Fecha de creación no disponible',
                'age' => null,
                'color' => '#6c757d'
            ];
        }

        if ($age <= 3) {
            return [
                'status' => 'optimal',
                'message' => 'Óptimo',
                'age' => $age,
                'color' => '#198754'
            ];
        } elseif ($age <= 5) {
            return [
                'status' => 'functional',
                'message' => 'Funcional',
                'age' => $age,
                'color' => '#0dcaf0'
            ];
        } elseif ($age <= 7) {
            return [
                'status' => 'potentially_obsolete',
                'message' => 'Potencialmente Obsoleto',
                'age' => $age,
                'color' => '#ffc107'
            ];
        } else {
            return [
                'status' => 'obsolete',
                'message' => 'Obsoleto',
                'age' => $age,
                'color' => '#dc3545'
            ];
        }
    }

    /**
     * Formatear datos para matriz de obsolescencia
     */
    public function toObsolescenceMatrix(): array
    {
        $obsolescenceStatus = $this->getObsolescenceStatus();
        
        return [
            'id' => $this->id,
            'name' => $this->name,
            'serial' => $this->serial,
            'location' => $this->locations_name,
            'user' => $this->users_name,
            'manufacturer' => $this->manufacturers_name,
            'model' => $this->computermodels_name,
            'os' => $this->operatingsystems_name,
            'os_version' => $this->operatingsystemversions_name,
            'age_years' => $obsolescenceStatus['age'],
            'obsolescence_status' => $obsolescenceStatus['status'],
            'obsolescence_message' => $obsolescenceStatus['message'],
            'obsolescence_color' => $obsolescenceStatus['color'],
            'last_update' => $this->date_mod?->format('Y-m-d'),
            'created' => $this->date_creation?->format('Y-m-d'),
            'is_active' => $this->isActive()
        ];
    }

    /**
     * Validar datos antes de enviar a GLPI
     */
    public function validate(): array
    {
        $errors = [];

        if (empty($this->name)) {
            $errors[] = 'El nombre de la computadora es requerido';
        }

        if (strlen($this->name) > 255) {
            $errors[] = 'El nombre no puede exceder 255 caracteres';
        }

        if ($this->serial && strlen($this->serial) > 255) {
            $errors[] = 'El número de serie no puede exceder 255 caracteres';
        }

        return $errors;
    }
}