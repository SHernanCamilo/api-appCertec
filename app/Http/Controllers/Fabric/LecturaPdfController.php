<?php

namespace App\Http\Controllers\Fabric;

use App\Http\Controllers\Controller;
use App\Services\Fabric\AzureFileShareService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Sirve PDFs de lecturas de imagenología desde Azure File Share.
 *
 * Endpoint: GET /api/fabric/lecturas/pdf?path=documentosadjuntos/Centro Atencion - 001/...pdf
 *
 * El frontend abre este URL en nueva pestaña con el JWT en header (o como query param temporal).
 */
class LecturaPdfController extends Controller
{
    private AzureFileShareService $fileShareService;

    public function __construct(AzureFileShareService $fileShareService)
    {
        $this->fileShareService = $fileShareService;
    }

    /**
     * GET /api/fabric/lecturas/pdf
     *
     * Parámetros:
     *   - path (string, required): Ruta relativa del PDF dentro del File Share
     *   - token (string, optional): JWT para autenticación cuando se abre en nueva pestaña
     *
     * Responde con el PDF como stream (Content-Type: application/pdf).
     */
    public function show(Request $request): Response
    {
        // Soportar JWT en query param (para window.open desde frontend)
        if (!$request->bearerToken() && $request->query('token')) {
            $request->headers->set('Authorization', 'Bearer ' . $request->query('token'));
        }

        $path = $request->query('path');

        if (empty($path)) {
            return response('Parámetro "path" requerido.', 400);
        }

        // Validar que sea un archivo PDF
        if (!str_ends_with(strtolower($path), '.pdf')) {
            return response('Solo se permiten archivos PDF.', 400);
        }

        try {
            $file = $this->fileShareService->getFile($path);

            Log::info('LecturaPdfController: PDF servido', [
                'path'  => $path,
                'size'  => $file['size'],
                'user'  => auth()->user()?->id ?? 'N/A',
            ]);

            return response($file['content'], 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Length'      => $file['size'],
                'Content-Disposition' => 'inline; filename="' . basename($path) . '"',
                'Cache-Control'       => 'private, max-age=300', // cache 5 min en browser
                'X-Content-Type-Options' => 'nosniff',
            ]);

        } catch (\RuntimeException $e) {
            $message = $e->getMessage();

            Log::warning('LecturaPdfController: Error sirviendo PDF', [
                'path'    => $path,
                'error'   => $message,
                'user'    => auth()->user()?->id ?? 'N/A',
            ]);

            // Determinar código HTTP apropiado
            $status = match (true) {
                str_contains($message, 'no encontrado')   => 404,
                str_contains($message, 'Acceso denegado') => 403,
                str_contains($message, 'traversal')       => 400,
                default                                    => 500,
            };

            return response(json_encode([
                'error'   => true,
                'message' => $message,
            ]), $status, ['Content-Type' => 'application/json']);
        }
    }

    /**
     * GET /api/fabric/lecturas/pdf/check
     *
     * Verifica si un archivo existe sin descargarlo (HEAD ligero).
     */
    public function check(Request $request): Response
    {
        $path = $request->query('path');

        if (empty($path)) {
            return response(json_encode(['exists' => false, 'error' => 'path requerido']), 400, [
                'Content-Type' => 'application/json',
            ]);
        }

        $exists = $this->fileShareService->fileExists($path);

        return response(json_encode(['exists' => $exists]), 200, [
            'Content-Type' => 'application/json',
        ]);
    }
}
