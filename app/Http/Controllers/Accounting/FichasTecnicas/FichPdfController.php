<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting\FichasTecnicas;

use App\Services\Accounting\FichasTecnicas\FichPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * Generación del PDF de la ficha técnica.
 *
 * Reemplaza `includes/ficha_pdf.php`, `ficha_os_pdf.php`, `pdf.php` y
 * `pdf_os.php` con una sola plantilla Blade.
 */
class FichPdfController extends BaseFichasController
{
    public function __construct(private readonly FichPdfService $pdf)
    {
    }

    /**
     * Descarga o muestra el PDF en línea (`?descargar=1` fuerza la descarga).
     */
    public function generar(Request $request, int $id): Response|JsonResponse
    {
        try {
            $contenido = $this->pdf->generar($id);
            $nombre    = $this->pdf->nombreArchivo($id);
            $inline    = ! $request->boolean('descargar');

            return response($contenido, 200, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename=\"{$nombre}\"",
                'Content-Length'      => (string) strlen($contenido),
                'Cache-Control'       => 'private, max-age=0, must-revalidate',
            ]);
        } catch (Throwable $e) {
            return $this->ejecutar(static function () use ($e): void {
                throw $e;
            }, 'Error al generar el PDF de la ficha técnica');
        }
    }

    /** PDF en base64, útil para previsualizar en un visor embebido. */
    public function base64(int $id): JsonResponse
    {
        return $this->ejecutar(fn (): array => [
            'filename' => $this->pdf->nombreArchivo($id),
            'mime'     => 'application/pdf',
            'base64'   => base64_encode($this->pdf->generar($id)),
        ], 'Error al generar el PDF de la ficha técnica');
    }

    /** HTML de la ficha, para previsualización rápida sin renderizar el PDF. */
    public function preview(int $id): Response|JsonResponse
    {
        try {
            return response($this->pdf->generarHtml($id), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        } catch (Throwable $e) {
            return $this->ejecutar(static function () use ($e): void {
                throw $e;
            }, 'Error al generar la previsualización de la ficha');
        }
    }
}
