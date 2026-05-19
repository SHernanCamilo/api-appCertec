<?php

namespace App\Services\Fabric;

/**
 * Servicio para consultar la vista [DT].[VW_HC_ReportViewer]
 * en Microsoft Fabric / LH_MEDILASER_ANALYTICS.
 *
 * Filtros disponibles:
 *   - documento_paciente : número de documento del paciente
 *   - nombre_paciente    : nombre parcial del paciente (LIKE)
 *   - nombre_especialista: nombre parcial del especialista (LIKE)
 *   - fecha_desde        : fecha inicio (YYYY-MM-DD)
 *   - fecha_hasta        : fecha fin (YYYY-MM-DD)
 *   - per_page           : registros por página (default 50, max 500)
 *   - page               : página (default 1)
 */
class HcReportViewerService
{
    private const VIEW    = '[DT].[VW_HC_ReportViewer]';
    private const MAX_PER_PAGE = 500;
    private const DEF_PER_PAGE = 50;

    public function __construct(
        private FabricConnectionService $fabric
    ) {}

    /**
     * Consulta la vista con filtros y paginación.
     *
     * @param array $filtros
     * @return array ['data' => [...], 'total' => int, 'page' => int, 'per_page' => int, 'pages' => int]
     */
    public function consultar(array $filtros = []): array
    {
        $perPage = min((int)($filtros['per_page'] ?? self::DEF_PER_PAGE), self::MAX_PER_PAGE);
        $page    = max(1, (int)($filtros['page'] ?? 1));
        $offset  = ($page - 1) * $perPage;

        [$where, $params] = $this->buildWhere($filtros);

        // Contar total
        $sqlCount = "SELECT COUNT(*) AS total FROM " . self::VIEW . $where;
        $countResult = $this->fabric->query($sqlCount, $params);
        $total = (int)($countResult[0]['total'] ?? 0);

        // Datos paginados
        $sqlData = "
            SELECT *
            FROM " . self::VIEW . "
            {$where}
            ORDER BY (SELECT NULL)
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
        ";

        $dataParams = array_merge($params, [$offset, $perPage]);
        $data = $this->fabric->query($sqlData, $dataParams);

        return [
            'data'     => $data,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => $total > 0 ? (int)ceil($total / $perPage) : 0,
        ];
    }

    /**
     * Busca un registro específico por documento del paciente.
     */
    public function porDocumentoPaciente(string $documento): array
    {
        [$where, $params] = $this->buildWhere(['documento_paciente' => $documento]);

        $sql = "SELECT TOP 100 * FROM " . self::VIEW . $where . " ORDER BY (SELECT NULL)";
        return $this->fabric->query($sql, $params);
    }

    /**
     * Construye la cláusula WHERE y los parámetros.
     * Usa queries parametrizadas para evitar SQL injection.
     *
     * @return array [string $where, array $params]
     */
    private function buildWhere(array $filtros): array
    {
        $conditions = [];
        $params     = [];

        // Filtro por documento del paciente (exacto)
        if (!empty($filtros['documento_paciente'])) {
            $conditions[] = "DocumentoPaciente = ?";
            $params[]     = trim($filtros['documento_paciente']);
        }

        // Filtro por nombre del paciente (LIKE parcial)
        if (!empty($filtros['nombre_paciente'])) {
            $conditions[] = "NombrePaciente LIKE ?";
            $params[]     = '%' . trim($filtros['nombre_paciente']) . '%';
        }

        // Filtro por nombre del especialista (LIKE parcial)
        if (!empty($filtros['nombre_especialista'])) {
            $conditions[] = "NombreEspecialista LIKE ?";
            $params[]     = '%' . trim($filtros['nombre_especialista']) . '%';
        }

        // Filtro por fecha desde
        if (!empty($filtros['fecha_desde'])) {
            $conditions[] = "FechaAtencion >= ?";
            $params[]     = $filtros['fecha_desde'];
        }

        // Filtro por fecha hasta
        if (!empty($filtros['fecha_hasta'])) {
            $conditions[] = "FechaAtencion <= ?";
            $params[]     = $filtros['fecha_hasta'];
        }

        $where = empty($conditions)
            ? ''
            : ' WHERE ' . implode(' AND ', $conditions);

        return [$where, $params];
    }

    /**
     * Retorna las columnas disponibles en la vista.
     * Útil para el frontend al construir tablas dinámicas.
     */
    public function columnas(): array
    {
        $sql = "
            SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = 'DT' AND TABLE_NAME = 'VW_HC_ReportViewer'
            ORDER BY ORDINAL_POSITION
        ";

        return $this->fabric->query($sql);
    }
}
