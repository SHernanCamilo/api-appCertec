<?php

declare(strict_types=1);

namespace App\Services\Accounting\FichasTecnicas;

use App\Models\Accounting\FichasTecnicas\FichFicha;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

        return "ficha-tecnica-{$referencia}.pdf";
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
            'porGrupo'      => $this->agruparPorGrupo($detalles),
            'observaciones' => $this->observacionesUsadas($idFicha),
            'homologos'     => $this->homologosUsados($idFicha),
            'generadoEn'    => now()->timezone('America/Bogota'),
        ];
    }

    /**
     * Agrupa los servicios por grupo/subgrupo CUPS, como hacían las consultas
     * `$sql9` / `$sql10` de cada generador de PDF legacy.
     *
     * @param  Collection<int, object>  $detalles
     * @return Collection<string, Collection<int, object>>
     */
    private function agruparPorGrupo(Collection $detalles): Collection
    {
        return $detalles->groupBy(static function (object $d): string {
            $grupo = trim((string) ($d->grupo_descripcion ?? ''));

            return $grupo !== '' ? $grupo : 'SERVICIOS CONTRATADOS';
        });
    }

    /**
     * Observaciones de ítem referenciadas por la ficha (legacy `$sql5`).
     *
     * @return Collection<int, object>
     */
    private function observacionesUsadas(int $idFicha): Collection
    {
        return DB::table('fich_detalles as d')
            ->join('fich_obs_items as o', 'o.id', '=', 'd.id_obs_item')
            ->where('d.id_ficha', $idFicha)
            ->select('o.id as codigo', 'o.descripcion')
            ->distinct()
            ->orderBy('o.id')
            ->get();
    }

    /**
     * Homologaciones usadas por la ficha (legacy `$sql8`).
     *
     * @return Collection<int, object>
     */
    private function homologosUsados(int $idFicha): Collection
    {
        return DB::table('fich_detalles as d')
            ->join('fich_homologos as h', 'h.code_manual', '=', 'd.homologo')
            ->where('d.id_ficha', $idFicha)
            ->select('d.cups as cod_cups', 'h.desc_cups', 'h.code_manual', 'h.desc_manual', 'h.tipo_manual')
            ->distinct()
            ->orderBy('d.cups')
            ->get();
    }
}
