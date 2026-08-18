<?php

namespace App\Services\Inventory\Pharmacy;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Servicio para consultar registros sanitarios INVIMA
 * desde datos abiertos de datos.gov.co
 */
class InvimaService
{
    private string $deviceDatasetUrl = 'https://www.datos.gov.co/resource/y4qt-w6tk.json';
    private string $medicineDatasetUrl = 'https://www.datos.gov.co/resource/i7cb-raxc.json';

    /**
     * Buscar productos en INVIMA (dispositivos médicos y/o medicamentos)
     */
    public function searchProduct(string $query, string $type = 'auto', int $limit = 50, int $offset = 0): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['success' => false, 'message' => 'Parámetro de búsqueda requerido'];
        }

        $type = strtolower($type);
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        if ($type === 'medical_device') {
            return $this->buildSearchResponse($this->deviceDatasetUrl, $query, $limit, $offset, 'medical_device', $type);
        }

        if ($type === 'medicine' || $type === 'product') {
            return $this->buildSearchResponse($this->medicineDatasetUrl, $query, $limit, $offset, 'medicine', $type);
        }

        // Auto: buscar en ambos datasets
        $devices = $this->buildSearchResponse($this->deviceDatasetUrl, $query, $limit, $offset, 'medical_device', $type);
        $medicines = $this->buildSearchResponse($this->medicineDatasetUrl, $query, $limit, $offset, 'medicine', $type);

        $merged = array_merge($devices['data'] ?? [], $medicines['data'] ?? []);
        $unique = $this->uniqueResults($merged);

        return [
            'success' => true,
            'message' => 'Búsqueda realizada',
            'data'    => $unique,
            'meta'    => [
                'query'  => $query,
                'type'   => $type,
                'limit'  => $limit,
                'offset' => $offset,
                'totals' => [
                    'medical_device' => $devices['meta']['total'] ?? 0,
                    'medicine'       => $medicines['meta']['total'] ?? 0,
                ],
                'has_more' => [
                    'medical_device' => $devices['meta']['has_more'] ?? false,
                    'medicine'       => $medicines['meta']['has_more'] ?? false,
                ],
            ],
        ];
    }

    /**
     * Validar un código INVIMA contra datos.gov.co
     */
    public function validateProduct(string $invimaCode, string $type = 'auto'): array
    {
        if (empty($invimaCode)) {
            return ['success' => false, 'message' => 'Código INVIMA requerido'];
        }

        $type = strtolower($type);

        if ($type === 'medical_device') {
            return $this->validateAgainstDataset($invimaCode, $this->deviceDatasetUrl, 'medical_device');
        }

        if ($type === 'medicine' || $type === 'product') {
            return $this->validateAgainstDataset($invimaCode, $this->medicineDatasetUrl, 'medicine');
        }

        // Auto: probar dispositivo médico primero, luego medicamento
        $result = $this->validateAgainstDataset($invimaCode, $this->deviceDatasetUrl, 'medical_device');
        if (!$result['data']['valid'] && $result['data']['status'] === 'not_found') {
            $result = $this->validateAgainstDataset($invimaCode, $this->medicineDatasetUrl, 'medicine');
        }

        return $result;
    }

    /**
     * Buscar Medicamentos Vitales No Disponibles (MVD)
     * Dataset: https://www.datos.gov.co/resource/sdmr-tfmf.json
     */
    public function searchMvd(string $ium): array
    {
        $ium = trim($ium);
        if ($ium === '') {
            return ['success' => false, 'message' => 'IUM requerido'];
        }

        $url = 'https://www.datos.gov.co/resource/sdmr-tfmf.json'
            . '?$where=' . urlencode("ium='" . str_replace("'", "''", $ium) . "'")
            . '&$limit=5';

        $rows = $this->getJson($url);

        // Fallback: búsqueda por texto libre
        if (empty($rows)) {
            $url2 = 'https://www.datos.gov.co/resource/sdmr-tfmf.json'
                . '?$q=' . urlencode($ium) . '&$limit=5';
            $rows = $this->getJson($url2);
        }

        if (empty($rows)) {
            return [
                'success' => true,
                'found'   => false,
                'message' => 'IUM no encontrado en el registro de MVD',
                'data'    => null,
            ];
        }

        // Buscar coincidencia exacta
        $match = null;
        foreach ($rows as $row) {
            if (strtoupper(trim($row['ium'] ?? '')) === strtoupper($ium)) {
                $match = $row;
                break;
            }
        }

        return [
            'success' => true,
            'found'   => true,
            'message' => 'MVD encontrado',
            'data'    => $this->normalizeMvdRow($match ?? $rows[0]),
        ];
    }

    // =========================================================================
    //  MÉTODOS PRIVADOS
    // =========================================================================

    private function validateAgainstDataset(string $code, string $url, string $category): array
    {
        $rows = $this->fetchDataset($url, $code, 5, 0);
        $match = $rows ? $this->findMatch($rows, $code) : null;

        if (!$match) {
            return [
                'success' => true,
                'message' => 'Código no encontrado',
                'data'    => [
                    'valid'       => false,
                    'status'      => 'not_found',
                    'category'    => $category,
                    'source'      => 'datos.gov.co',
                    'verified_at' => now()->toDateTimeString(),
                ],
            ];
        }

        $status       = $this->extractStatus($match);
        $registryCode = $this->extractRegistryCode($match);
        $name         = $this->extractName($match);
        $expiresAt    = $this->extractExpirationDate($match);
        $laboratory   = $this->extractLaboratory($match);

        return [
            'success' => true,
            'message' => 'Código validado',
            'data'    => [
                'valid'         => $status === 'active',
                'status'        => $status,
                'category'      => $category,
                'source'        => 'datos.gov.co',
                'registry_code' => $registryCode,
                'name'          => $name,
                'laboratory'    => $laboratory,
                'expires_at'    => $expiresAt,
                'vida_util'     => $this->extractVidaUtil($match),
                'verified_at'   => now()->toDateTimeString(),
            ],
        ];
    }

    private function buildSearchResponse(string $url, string $query, int $limit, int $offset, string $category, string $type): array
    {
        $rows   = $this->fetchDataset($url, $query, $limit, $offset);
        $total  = $this->fetchDatasetCount($url, $query);
        $mapped = array_map(fn($row) => $this->normalizeRow($row, $category), $rows);

        return [
            'success' => true,
            'message' => 'Búsqueda realizada',
            'data'    => $this->uniqueResults($mapped),
            'meta'    => [
                'query'    => $query,
                'type'     => $type,
                'category' => $category,
                'limit'    => $limit,
                'offset'   => $offset,
                'total'    => $total,
                'has_more' => $total > ($offset + $limit),
            ],
        ];
    }

    private function fetchDataset(string $url, string $code, int $limit = 25, int $offset = 0): array
    {
        $query = $url . '?$limit=' . $limit . '&$offset=' . $offset . '&$q=' . urlencode($code);
        return $this->getJson($query) ?? [];
    }

    private function fetchDatasetCount(string $url, string $query): int
    {
        $countUrl = $url . '?$select=count(*) as total&$q=' . urlencode($query);
        $response = $this->getJson($countUrl);

        if (!is_array($response) || empty($response[0])) {
            return 0;
        }

        return (int) ($response[0]['total'] ?? $response[0]['count'] ?? 0);
    }

    /**
     * Petición HTTP GET a datos.gov.co usando Http facade de Laravel
     */
    private function getJson(string $url): ?array
    {
        try {
            $response = Http::timeout(12)
                ->connectTimeout(6)
                ->accept('application/json')
                ->get($url);

            if ($response->failed()) {
                return null;
            }

            $data = $response->json();
            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            Log::warning('InvimaService: Error al consultar datos.gov.co', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    // =========================================================================
    //  NORMALIZACIÓN Y EXTRACCIÓN
    // =========================================================================

    private function normalizeRow(array $row, string $category): array
    {
        return [
            'invima_code'      => $this->extractRegistryCode($row),
            'name'             => $this->extractName($row),
            'laboratory'       => $this->extractLaboratory($row),
            'active_principle' => $this->extractActivePrinciple($row),
            'presentation'     => $this->extractPresentation($row),
            'category'         => $category,
            'source'           => 'datos.gov.co',
        ];
    }

    private function findMatch(array $rows, string $code): ?array
    {
        $needle = strtolower($code);
        foreach ($rows as $row) {
            foreach ($row as $value) {
                if (is_string($value) && str_contains(strtolower($value), $needle)) {
                    return $row;
                }
            }
        }
        return $rows[0] ?? null;
    }

    private function extractStatus(array $row): string
    {
        // Buscar 'estadoregistro' primero
        $estadoRegistro = $this->findValueByKeyContains($row, ['estadoregistro']);
        if ($estadoRegistro) {
            $text = strtolower($estadoRegistro);
            if (str_contains($text, 'vigente') || str_contains($text, 'activo')) return 'active';
            if (str_contains($text, 'vencid') || str_contains($text, 'expir') || str_contains($text, 'inactivo')) return 'expired';
            if (str_contains($text, 'cancel') || str_contains($text, 'suspend')) return 'cancelled';
        }

        // Fallback
        $statusField = $this->findValueByKeyContains($row, ['estado_registro', 'estado', 'vigencia']);
        $statusText  = $statusField ? strtolower($statusField) : '';

        foreach ($row as $value) {
            if (is_string($value)) {
                $statusText .= ' ' . strtolower($value);
            }
        }

        if (str_contains($statusText, 'vigente') || str_contains($statusText, 'activo')) return 'active';
        if (str_contains($statusText, 'vencid') || str_contains($statusText, 'expir')) return 'expired';
        if (str_contains($statusText, 'cancel') || str_contains($statusText, 'suspend')) return 'cancelled';

        return 'unknown';
    }

    private function extractRegistryCode(array $row): ?string
    {
        $registroSanitario = $this->findValueByKeyContains($row, ['registrosanitario']);
        if ($registroSanitario) return $registroSanitario;

        foreach ($row as $key => $value) {
            $keyLower = strtolower($key);
            if (str_contains($keyLower, 'registro') && str_contains($keyLower, 'sanitario')) {
                return $value;
            }
        }

        return $this->findValueByKeyContains($row, ['registro']);
    }

    private function extractName(array $row): ?string
    {
        return $this->findValueByKeyContains($row, ['producto', 'product', 'nombre', 'nombre_comercial']);
    }

    private function extractLaboratory(array $row): ?string
    {
        $titular = $this->findValueByKeyContains($row, ['titular']);
        if ($titular) return $titular;

        return $this->findValueByKeyContains($row, ['laboratorio', 'laboratory', 'fabricante', 'manufacturer', 'nombrerol']);
    }

    private function extractActivePrinciple(array $row): ?string
    {
        return $this->findValueByKeyContains($row, ['principio', 'ingrediente', 'composicion', 'composición', 'activo']);
    }

    private function extractPresentation(array $row): ?string
    {
        return $this->findValueByKeyContains($row, ['presentacion', 'presentación', 'forma', 'forma_farmaceutica', 'concentracion', 'concentración']);
    }

    private function extractExpirationDate(array $row): ?string
    {
        return $this->findValueByKeyContains($row, ['vencimiento', 'fecha_vencimiento', 'fecha de vencimiento', 'expir']);
    }

    private function extractVidaUtil(array $row): ?string
    {
        return $this->findValueByKeyContains($row, ['vida_util', 'vida util', 'vidautil', 'vida_útil', 'vida útil']);
    }

    private function findValueByKeyContains(array $row, array $keywords): ?string
    {
        foreach ($row as $key => $value) {
            $keyLower = strtolower($key);
            foreach ($keywords as $keyword) {
                if (str_contains($keyLower, strtolower($keyword))) {
                    return $value;
                }
            }
        }
        return null;
    }

    private function uniqueResults(array $items): array
    {
        $seen   = [];
        $unique = [];
        foreach ($items as $item) {
            $key = strtolower(($item['invima_code'] ?? '') . '|' . ($item['name'] ?? '') . '|' . ($item['category'] ?? ''));
            if ($key === '||' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $unique[]   = $item;
        }
        return $unique;
    }

    private function normalizeMvdRow(array $row): array
    {
        $fechaRaw = $row['fecha_de_autorizaci_n'] ?? null;
        $fecha    = $fechaRaw ? substr($fechaRaw, 0, 10) : null;

        $principio = trim($row['principio_activo1'] ?? '');
        $conc1     = trim($row['concentraci_n_delmedicamento1'] ?? '');
        $unidad1   = trim($row['unidad_medida1'] ?? '');

        if ($conc1 !== '' && strtoupper($conc1) !== 'NO APLICA') {
            $principio .= ' ' . $conc1;
            if ($unidad1 !== '' && strtoupper($unidad1) !== 'NO APLICA') {
                $principio .= ' ' . $unidad1;
            }
        }

        return [
            'ium'                    => trim($row['ium'] ?? ''),
            'solicitante'            => trim($row['solicitante_importador'] ?? ''),
            'principio_activo'       => trim($principio),
            'forma_farmaceutica'     => trim($row['forma_farmac_utica'] ?? ''),
            'presentacion_comercial' => trim($row['presentaci_n_comercial'] ?? ''),
            'nombre_comercial'       => trim($row['nombre_comercial_'] ?? ''),
            'tipo_solicitud'         => trim($row['tipo_de_solicitud'] ?? ''),
            'fecha_autorizacion'     => $fecha,
            'diagnostico'            => trim($row['diagnostico_cie_1no_reporta'] ?? ''),
        ];
    }
}
