<?php

namespace App\Http\Controllers\TalentoHumano\CuadroTurnos;

use App\Http\Controllers\Controller;
use App\Services\TalentoHumano\CuadroTurnos\CargaMasivaExportService;
use App\Services\TalentoHumano\CuadroTurnos\CargaMasivaImportService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CargaMasivaController extends Controller
{
    private CargaMasivaExportService $exportService;
    private CargaMasivaImportService $importService;

    public function __construct(CargaMasivaExportService $exportService, CargaMasivaImportService $importService)
    {
        $this->exportService = $exportService;
        $this->importService = $importService;
    }

    /**
     * GET /api/turnos/carga-masiva/formato
     *
     * Descarga el formato Excel pre-llenado con los empleados de la unidad,
     * los d+¡as del mes y los c+¦digos de plantilla v+ílidos para la empresa.
     *
     * Query params: id_unidad, anio, mes
     */
    public function descargarFormato(Request $request): StreamedResponse
    {
        $request->validate([
            'id_unidad' => 'required|integer|exists:config_unidades_funcionales,id',
            'anio'      => 'required|integer|min:2020|max:2100',
            'mes'       => 'required|integer|min:1|max:12',
        ]);

        $idUnidad = (int) $request->query('id_unidad');
        $anio = (int) $request->query('anio');
        $mes = (int) $request->query('mes');

        $spreadsheet = $this->exportService->generarFormato($idUnidad, $anio, $mes);

        $nombreArchivo = "turnos_formato_{$anio}_{$mes}.xlsx";

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$nombreArchivo}\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }

    /**
     * POST /api/turnos/carga-masiva/importar
     *
     * Recibe el archivo Excel diligenciado y genera las asignaciones de turno.
     *
     * Body: file (xlsx), id_unidad, anio, mes
     */
    public function importar(Request $request): JsonResponse
    {
        $request->validate([
            'file'      => 'required|file|mimes:xlsx,xls|max:10240',
            'id_unidad' => 'required|integer|exists:config_unidades_funcionales,id',
            'anio'      => 'required|integer|min:2020|max:2100',
            'mes'       => 'required|integer|min:1|max:12',
        ]);

        $idUnidad = (int) $request->input('id_unidad');
        $anio = (int) $request->input('anio');
        $mes = (int) $request->input('mes');

        // Guardar archivo temporal
        $file = $request->file('file');
        $tempPath = $file->store('temp/carga-masiva');
        $fullPath = storage_path('app/' . $tempPath);

        try {
            $resultado = $this->importService->importar($fullPath, $idUnidad, $anio, $mes);

            // Limpiar archivo temporal
            @unlink($fullPath);

            return response()->json([
                'success'  => true,
                'message'  => "Importaci+¦n completada: {$resultado['exitosas']} turnos asignados.",
                'data'     => [
                    'exitosas' => $resultado['exitosas'],
                    'errores'  => $resultado['errores'],
                    'total'    => $resultado['total'],
                ],
            ]);
        } catch (\Exception $e) {
            @unlink($fullPath);

            \Log::error('Carga masiva: error en importaci+¦n', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el archivo: ' . $e->getMessage(),
            ], 422);
        }
    }
}
