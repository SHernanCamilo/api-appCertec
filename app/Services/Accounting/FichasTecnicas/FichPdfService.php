<?php

declare(strict_types=1);

namespace App\Services\Accounting\FichasTecnicas;

use App\Models\Accounting\FichasTecnicas\FichFicha;
use Barryvdh\DomPDF\Facade\Pdf;

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
            'generadoEn'    => now()->timezone('America/Bogota'),
        ];
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
