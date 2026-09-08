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
     * Estrategia (en orden), replicando y mejorando el legacy:
     *   1. Logo LOCAL empaquetado por empresa (public/images/fichas/*.png),
     *      elegido por prefijo/nombre — es lo más confiable y rápido para PDF.
     *   2. `ent_empresas.logo`: data-URI, ruta local o URL remota.
     *   3. null → la plantilla cae al nombre de la empresa como texto.
     */
    private function logoEmpresa(FichFicha $ficha): ?string
    {
        // 1) Logo local empaquetado, elegido por la empresa.
        $local = $this->logoLocalPorEmpresa($ficha);
        if ($local !== null) {
            return $local;
        }

        // 2) Logo configurado en ent_empresas.logo.
        $logo = trim((string) ($ficha->empresa->logo ?? ''));

        if ($logo === '') {
            return null;
        }

        if (str_starts_with($logo, 'data:image')) {
            return $logo;
        }

        $contenido = null;
        $mime      = null;

        if (preg_match('#^https?://#i', $logo) === 1) {
            try {
                $respuesta = Http::timeout(8)->withOptions(['verify' => false])->get($logo);
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
     * Elige un logo local empaquetado según la empresa de la ficha.
     *
     * Los archivos viven en public/images/fichas/. Como el módulo pertenece a
     * Medilaser, si no se identifica otra empresa se usa el logo Medilaser.
     */
    private function logoLocalPorEmpresa(FichFicha $ficha): ?string
    {
        $nombre  = mb_strtoupper((string) ($ficha->empresa->nombre ?? ''), 'UTF-8');
        $prefijo = mb_strtoupper((string) ($ficha->empresa->prefijo ?? ''), 'UTF-8');

        $archivo = match (true) {
            str_contains($nombre, 'MEDIFACA') || $prefijo === 'MF'  => 'medifaca.png',
            str_contains($nombre, 'MEGASALUD')                      => 'megasalud.png',
            default                                                  => 'medilaser.png',
        };

        $ruta = public_path('images/fichas/'.$archivo);

        if (! is_file($ruta)) {
            return null;
        }

        $contenido = file_get_contents($ruta);
        if ($contenido === false || $contenido === '') {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($contenido);
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
