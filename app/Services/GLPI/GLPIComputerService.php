<?php

namespace App\Services\GLPI;

use Exception;

class GLPIComputerService
{
    protected $glpiService;
    protected $itemType = 'Computer';

    public function __construct(GLPIService $glpiService)
    {
        $this->glpiService = $glpiService;
    }

    /**
     * Obtener todas las computadoras
     */
    public function getAllComputers(array $params = []): array
    {
        $defaultParams = [
            'expand_dropdowns' => true,
            'get_hateoas' => true,
            'range' => '0-50'
        ];

        $params = array_merge($defaultParams, $params);
        
        return $this->glpiService->getItems($this->itemType, $params);
    }

    /**
     * Obtener una computadora específica
     */
    public function getComputer(int $id, array $params = []): array
    {
        $defaultParams = [
            'expand_dropdowns' => true,
            'get_hateoas' => true,
            'with_devices' => true,
            'with_disks' => true,
            'with_softwares' => true,
            'with_connections' => true,
            'with_networkports' => true,
            'with_infocoms' => true
        ];

        $params = array_merge($defaultParams, $params);
        
        return $this->glpiService->getItem($this->itemType, $id, $params);
    }

    /**
     * Crear una nueva computadora
     */
    public function createComputer(array $data): array
    {
        // Validar datos requeridos
        if (!isset($data['name'])) {
            throw new Exception('El nombre de la computadora es requerido');
        }

        // Agregar campos por defecto si no están presentes
        $defaultData = [
            'entities_id' => 0,
            'is_recursive' => 0,
            'is_template' => 0,
            'is_deleted' => 0
        ];

        $data = array_merge($defaultData, $data);
        
        return $this->glpiService->createItem($this->itemType, $data);
    }

    /**
     * Actualizar una computadora
     */
    public function updateComputer(int $id, array $data): array
    {
        return $this->glpiService->updateItem($this->itemType, $id, $data);
    }

    /**
     * Eliminar una computadora
     */
    public function deleteComputer(int $id, bool $force = false): array
    {
        return $this->glpiService->deleteItem($this->itemType, $id, $force);
    }

    /**
     * Buscar computadoras
     */
    public function searchComputers(array $searchParams = []): array
    {
        return $this->glpiService->search($this->itemType, $searchParams);
    }

    /**
     * Obtener dispositivos de una computadora
     */
    public function getComputerDevices(int $computerId): array
    {
        $devices = [];
        
        // Tipos de dispositivos que puede tener una computadora
        $deviceTypes = [
            'Item_DeviceMotherboard',
            'Item_DeviceProcessor',
            'Item_DeviceMemory',
            'Item_DeviceHardDrive',
            'Item_DeviceNetworkCard',
            'Item_DeviceSoundCard',
            'Item_DeviceGraphicCard',
            'Item_DevicePowerSupply',
            'Item_DeviceControl',
            'Item_DeviceDrive',
            'Item_DevicePci',
            'Item_DeviceCase',
            'Item_DeviceGeneric'
        ];

        foreach ($deviceTypes as $deviceType) {
            try {
                $deviceData = $this->glpiService->get("/{$deviceType}", [
                    'searchText' => ['items_id' => $computerId, 'itemtype' => 'Computer'],
                    'expand_dropdowns' => true
                ]);
                
                if (!empty($deviceData)) {
                    $devices[$deviceType] = $deviceData;
                }
            } catch (Exception $e) {
                // Continuar si no se pueden obtener algunos dispositivos
                continue;
            }
        }

        return $devices;
    }

    /**
     * Obtener software instalado en una computadora
     */
    public function getComputerSoftware(int $computerId): array
    {
        try {
            return $this->glpiService->get('/Item_SoftwareVersion', [
                'searchText' => ['items_id' => $computerId, 'itemtype' => 'Computer'],
                'expand_dropdowns' => true,
                'range' => '0-1000'
            ]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Obtener puertos de red de una computadora
     */
    public function getComputerNetworkPorts(int $computerId): array
    {
        try {
            return $this->glpiService->get('/NetworkPort', [
                'searchText' => ['items_id' => $computerId, 'itemtype' => 'Computer'],
                'expand_dropdowns' => true
            ]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Obtener información financiera de una computadora
     */
    public function getComputerInfocom(int $computerId): array
    {
        try {
            return $this->glpiService->get('/Infocom', [
                'searchText' => ['items_id' => $computerId, 'itemtype' => 'Computer'],
                'expand_dropdowns' => true
            ]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Obtener contratos asociados a una computadora
     */
    public function getComputerContracts(int $computerId): array
    {
        try {
            return $this->glpiService->get('/Contract_Item', [
                'searchText' => ['items_id' => $computerId, 'itemtype' => 'Computer'],
                'expand_dropdowns' => true
            ]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Obtener tickets asociados a una computadora
     */
    public function getComputerTickets(int $computerId): array
    {
        try {
            return $this->glpiService->get('/Item_Ticket', [
                'searchText' => ['items_id' => $computerId, 'itemtype' => 'Computer'],
                'expand_dropdowns' => true,
                'range' => '0-100'
            ]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Obtener problemas asociados a una computadora
     */
    public function getComputerProblems(int $computerId): array
    {
        try {
            return $this->glpiService->get('/Item_Problem', [
                'searchText' => ['items_id' => $computerId, 'itemtype' => 'Computer'],
                'expand_dropdowns' => true
            ]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Obtener cambios asociados a una computadora
     */
    public function getComputerChanges(int $computerId): array
    {
        try {
            return $this->glpiService->get('/Change_Item', [
                'searchText' => ['items_id' => $computerId, 'itemtype' => 'Computer'],
                'expand_dropdowns' => true
            ]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Obtener documentos asociados a una computadora
     */
    public function getComputerDocuments(int $computerId): array
    {
        try {
            return $this->glpiService->get('/Document_Item', [
                'searchText' => ['items_id' => $computerId, 'itemtype' => 'Computer'],
                'expand_dropdowns' => true
            ]);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Obtener información completa de una computadora
     */
    public function getComputerFullInfo(int $computerId): array
    {
        $computer = $this->getComputer($computerId);
        
        return [
            'computer' => $computer,
            'devices' => $this->getComputerDevices($computerId),
            'software' => $this->getComputerSoftware($computerId),
            'networkports' => $this->getComputerNetworkPorts($computerId),
            'infocom' => $this->getComputerInfocom($computerId),
            'contracts' => $this->getComputerContracts($computerId),
            'tickets' => $this->getComputerTickets($computerId),
            'problems' => $this->getComputerProblems($computerId),
            'changes' => $this->getComputerChanges($computerId),
            'documents' => $this->getComputerDocuments($computerId)
        ];
    }

    /**
     * Buscar computadoras por criterios específicos para matriz de obsolescencia
     */
    public function searchComputersForObsolescence(): array
    {
        $criteria = [
            'criteria' => [
                [
                    'field' => 31, // Estado
                    'searchtype' => 'equals',
                    'value' => 1 // Activo
                ]
            ],
            'metacriteria' => [],
            'sort' => 1,
            'order' => 'ASC',
            'range' => '0-1000',
            'forcedisplay' => [
                1,  // Nombre
                31, // Estado
                23, // Fabricante
                40, // Modelo
                45, // Sistema Operativo
                46, // Versión SO
                5,  // Número de serie
                6,  // Número de inventario
                3,  // Ubicación
                70, // Usuario
                71, // Grupo
                19, // Fecha de última actualización
                121 // Fecha de creación
            ]
        ];

        return $this->searchComputers($criteria);
    }
}