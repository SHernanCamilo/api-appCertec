<?php

declare(strict_types=1);

namespace App\Services\Accounting\FichasTecnicas;

use App\Models\Accounting\FichasTecnicas\FichFicha;
use App\Models\Empresa;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Generación de consecutivos de ficha técnica.
 *
 * Formato ficha nueva:  {PREFIJO_EMPRESA}-{AÑO}-{N}      → DMN-2026-15
 * Formato actualización: {CONSECUTIVO_PADRE}-{VERSION}   → DMN-2026-15-2
 *
 * Refactor respecto al legacy (`aprobador/insert_aprob.php`):
 *  - El legacy contaba filas con `COUNT(id) WHERE consecutivo LIKE '%X%'` y
 *    sumaba 1. Si alguna ficha se cancelaba o el conteo cambiaba, el número se
 *    reutilizaba y provocaba consecutivos duplicados. Aquí se obtiene el
 *    MÁXIMO sufijo numérico realmente asignado (`sp_fich_siguiente_consecutivo`).
 *  - El cálculo corre dentro de un bloqueo pesimista sobre las filas del
 *    prefijo/año para evitar que dos aprobadores simultáneos obtengan el mismo
 *    número.
 */
final class FichConsecutivoService
{
    /**
     * Sugiere el siguiente consecutivo disponible para una ficha nueva.
     *
     * El aprobador puede sobrescribirlo (el legacy lo digitaba a mano), pero
     * ahora recibe una propuesta consistente.
     */
    public function siguienteParaFicha(string $prefijo, ?int $anio = null): string
    {
        $anio    = $anio ?? (int) now()->format('Y');
        $prefijo = strtoupper(trim($prefijo));

        if ($prefijo === '') {
            throw new RuntimeException('La empresa no tiene prefijo configurado para generar el consecutivo.');
        }

        DB::statement('CALL sp_fich_siguiente_consecutivo(?, ?, @fich_consecutivo)', [$prefijo, $anio]);

        $resultado = DB::selectOne('SELECT @fich_consecutivo AS consecutivo');

        return (string) ($resultado->consecutivo ?? "{$prefijo}-{$anio}-1");
    }

    /**
     * Consecutivo y versión de una actualización (OS) a partir de la ficha padre.
     *
     * @return array{consecutivo: string, version: int}
     */
    public function siguienteParaActualizacion(int $idFichaPadre): array
    {
        DB::statement(
            'CALL sp_fich_siguiente_version_os(?, @fich_os_consecutivo, @fich_os_version)',
            [$idFichaPadre]
        );

        $resultado = DB::selectOne('SELECT @fich_os_consecutivo AS consecutivo, @fich_os_version AS version');

        return [
            'consecutivo' => (string) ($resultado->consecutivo ?? ''),
            'version'     => (int) ($resultado->version ?? 1),
        ];
    }

    /**
     * Resuelve el consecutivo apropiado para la ficha indicada, bloqueando la
     * secuencia mientras se calcula.
     */
    public function resolverParaFicha(FichFicha $ficha): string
    {
        return DB::transaction(function () use ($ficha): string {
            if ($ficha->esActualizacion()) {
                // Bloquea las versiones existentes del mismo padre.
                FichFicha::query()
                    ->where('id_padre', $ficha->id_padre)
                    ->lockForUpdate()
                    ->get(['id']);

                return $this->siguienteParaActualizacion((int) $ficha->id_padre)['consecutivo'];
            }

            $prefijo = $this->prefijoDeEmpresa($ficha);
            $anio    = (int) now()->format('Y');

            // Bloquea las fichas del mismo prefijo/año antes de calcular.
            FichFicha::query()
                ->where('consecutivo', 'like', "{$prefijo}-{$anio}-%")
                ->lockForUpdate()
                ->get(['id']);

            return $this->siguienteParaFicha($prefijo, $anio);
        });
    }

    /** Verifica que un consecutivo digitado manualmente no esté en uso. */
    public function estaDisponible(string $consecutivo, ?int $excluirFichaId = null): bool
    {
        return ! FichFicha::query()
            ->where('consecutivo', $consecutivo)
            ->when($excluirFichaId !== null, fn ($q) => $q->where('id', '!=', $excluirFichaId))
            ->exists();
    }

    private function prefijoDeEmpresa(FichFicha $ficha): string
    {
        if ($ficha->id_empresa === null) {
            throw new RuntimeException('La ficha no tiene empresa asignada; no se puede generar el consecutivo.');
        }

        $prefijo = Empresa::query()->whereKey($ficha->id_empresa)->value('prefijo');

        if (! is_string($prefijo) || trim($prefijo) === '') {
            throw new RuntimeException("La empresa {$ficha->id_empresa} no tiene prefijo configurado.");
        }

        return strtoupper(trim($prefijo));
    }
}
