<?php

declare(strict_types=1);

namespace App\Services\Accounting\FichasTecnicas;

use App\Models\Accounting\FichasTecnicas\FichFicha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Generación del PDF de la ficha técnica.
 *
 * Refactor respecto al legacy: existían CUATRO generadores
 * (`includes/pdf.php`, `pdf_os.php`, `ficha_pdf.php`, `ficha_os_pdf.php`) con
 * el mismo maquetado copiado y solo cambiando la tabla CUPS del JOIN
 * (2077 / 2336 / 2641). Aquí hay una única plantilla Blade y la resolución del
 * CUPS la hace la vista `v_fich_detalles_completo`.
 */
final class FichPdfService
{
    public function __construct(private readonly FichFichaService $fichas)
    {
    }

    /** Devuelve el PDF como cadena binaria. */
    public function generar(int $idFicha): string
    {
        return Pdf::loadView('fichas-tecnicas.pdf.ficha', $this->datos($idFicha))
            ->setPaper('letter', 'portrait')
            ->setOption('isRemoteEnabled', true)
            ->setOption('defaultFont', 'DejaVu Sans')
            ->output();
    }

    /** HTML de la ficha, útil para previsualizar sin generar el binario. */
    public function generarHtml(int $idFicha): string
    {
        return view('fichas-tecnicas.pdf.ficha', $this->datos($idFicha))->render();
    }

    public function nombreArchivo(int $idFicha): string
    {
        $ficha = FichFicha::query()->findOrFail($idFicha);

        $referencia = $ficha->consecutivo !== null && $ficha->consecutivo !== ''
            ? str_replace([' ', '/'], '-', $ficha->consecutivo)
            : "borrador-{$ficha->id}";

        return "FICHA TÉCNICA-{$referencia}.pdf";
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(int $idFicha): array
    {
        $ficha    = $this->fichas->obtener($idFicha);
        $detalles = $this->fichas->detallesEnriquecidos($idFicha);

        return [
            'ficha'         => $ficha,
            'detalles'      => $detalles,
            'polizaCuantia' => $this->cuantiaPoliza($ficha->especialidad->descripcion ?? ''),
            'logoEmpresa'   => $this->logoEmpresa($ficha),
            'generadoEn'    => now()->timezone('America/Bogota'),
        ];
    }

    /**
     * Logo de la empresa de la ficha como data-URI base64.
     *
     * El legacy incrustaba la imagen con un `switch` por nombre de empresa y
     * rutas fijas (`../images/medilaser.png`). Aquí se toma de
     * `ent_empresas.logo`, que puede ser una URL, una ruta relativa o ya un
     * data-URI. Se devuelve null si no se puede resolver, y la plantilla cae
     * al nombre/prefijo de la empresa como texto.
     */
    private function logoEmpresa(FichFicha $ficha): ?string
    {
        $logo = trim((string) ($ficha->empresa->logo ?? ''));

        if ($logo === '') {
            return null;
        }

        // Ya viene embebido.
        if (str_starts_with($logo, 'data:image')) {
            return $logo;
        }

        $contenido = null;
        $mime      = null;

        if (preg_match('#^https?://#i', $logo) === 1) {
            try {
                $respuesta = Http::timeout(8)
                    ->withOptions(['verify' => false])
                    ->get($logo);

                if ($respuesta->successful()) {
                    $contenido = $respuesta->body();
                    $mime      = $respuesta->header('Content-Type') ?: null;
                }
            } catch (Throwable) {
                return null;
            }
        } else {
            $relativa = ltrim($logo, '/');

            foreach ([
                public_path($relativa),
                storage_path('app/public/'.$relativa),
                storage_path('app/'.$relativa),
                base_path($relativa),
            ] as $ruta) {
                if (is_file($ruta)) {
                    $contenido = file_get_contents($ruta) ?: null;
                    $mime      = mime_content_type($ruta) ?: null;
                    break;
                }
            }
        }

        if ($contenido === null || $contenido === '') {
            return null;
        }

        if ($mime === null || ! str_starts_with($mime, 'image/')) {
            $mime = 'image/png';
        }

        return 'data:'.$mime.';base64,'.base64_encode($contenido);
    }

    /**
     * Cuantía de la póliza de responsabilidad civil según la especialidad.
     *
     * Réplica de la regla del legacy (ficha_pdf.php): las especialidades
     * quirúrgicas de alto riesgo exigen 500 SMLMV; el resto, 350 SMLMV.
     */
    private function cuantiaPoliza(string $especialidad): string
    {
        $altas = [
            'CIRUGIA CARDIOVASCULAR', 'MEDICINA INTERNA', 'CIRUGIA GENERAL', 'PEDIATRIA',
            'NEUROCIRUGIA', 'ORTOPEDIA Y TRAUMATOLOGIA', 'GINECOLOGIA Y OBSTETRICIA',
            'UROLOGIA', 'CIRUGIA PEDIATRICA',
        ];

        return in_array(mb_strtoupper(trim($especialidad)), $altas, true)
            ? 'CUANTÍA ESPECIALIDAD 500 SMLMV'
            : 'CUANTÍA ESPECIALIDAD 350 SMLMV';
    }
}
