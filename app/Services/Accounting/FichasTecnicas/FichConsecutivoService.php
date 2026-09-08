<?php

declare(strict_types=1);

namespace App\Services\Accounting\FichasTecnicas;

use App\Models\Accounting\FichasTecnicas\FichFicha;
use App\Models\Config\SecSecuencia;
use App\Models\Empresa;
use App\Services\SecuenciaNumericaService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

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
    /** Módulo "Fichas Técnicas" en seg_modulos (para el sistema de secuencias). */
    private const MODULO_FICHAS = 95;

    public function __construct(
        private readonly SecuenciaNumericaService $secuencias,
    ) {
    }

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
        // Actualizaciones (OS): mantienen su regla de versión sobre el padre.
        if ($ficha->esActualizacion()) {
            return DB::transaction(function () use ($ficha): string {
                FichFicha::query()
                    ->where('id_padre', $ficha->id_padre)
                    ->lockForUpdate()
                    ->get(['id']);

                return $this->siguienteParaActualizacion((int) $ficha->id_padre)['consecutivo'];
            });
        }

        // Ficha nueva: usar el SISTEMA DE SECUENCIAS de la plataforma
        // (config_sec_*) por sucursal. Es el estándar y reemplaza el atajo
        // legacy de `config_ubi_sucursales.prefijo_fichas`.
        $consecutivo = $this->desdeSistemaSecuencias($ficha);
        if ($consecutivo !== null) {
            return $consecutivo;
        }

        // Respaldo (si aún no hay secuencia configurada): método legacy.
        return DB::transaction(function () use ($ficha): string {
            $prefijo = $this->prefijoDeFicha($ficha);
            $anio    = (int) now()->format('Y');

            FichFicha::query()
                ->where('consecutivo', 'like', "{$prefijo}-{$anio}-%")
                ->lockForUpdate()
                ->get(['id']);

            return $this->siguienteParaFicha($prefijo, $anio);
        });
    }

    /**
     * Genera el consecutivo con el sistema de secuencias (config_sec_*),
     * usando la sucursal de la ficha como unidad operativa.
     *
     * Devuelve null si no hay una secuencia configurada para el módulo/empresa
     * o para esa sucursal, para que el llamador use el respaldo legacy.
     */
    private function desdeSistemaSecuencias(FichFicha $ficha): ?string
    {
        $idEmpresa  = $ficha->id_empresa;
        $idSucursal = $this->sucursalDeFicha($ficha);

        if ($idEmpresa === null || $idSucursal === null) {
            return null;
        }

        // ¿Existe secuencia activa para (empresa, módulo Fichas)?
        $existe = SecSecuencia::query()
            ->where('empresa_id', $idEmpresa)
            ->where('modulo_id', self::MODULO_FICHAS)
            ->whereNull('proceso_id')
            ->where('estado', true)
            ->exists();

        if (! $existe) {
            return null;
        }

        try {
            return $this->secuencias->generar($idEmpresa, self::MODULO_FICHAS, null, $idSucursal);
        } catch (Throwable) {
            // Sin detalle para esa sucursal, etc. → respaldo legacy.
            return null;
        }
    }

    /**
     * Sucursal que rige el consecutivo de la ficha.
     *
     * Con el nuevo alcance, una ficha puede abarcar varias sucursales; el
     * consecutivo se ancla a UNA: se prioriza `id_sucursal` (la principal) y,
     * si no está, la primera sucursal del alcance.
     */
    private function sucursalDeFicha(FichFicha $ficha): ?int
    {
        if ($ficha->id_sucursal !== null) {
            return (int) $ficha->id_sucursal;
        }

        $idAlcance = DB::table('fich_ficha_sucursal')
            ->where('id_ficha', $ficha->id)
            ->orderBy('id_sucursal')
            ->value('id_sucursal');

        return $idAlcance !== null ? (int) $idAlcance : null;
    }

    /** Verifica que un consecutivo digitado manualmente no esté en uso. */
    public function estaDisponible(string $consecutivo, ?int $excluirFichaId = null): bool
    {
        return ! FichFicha::query()
            ->where('consecutivo', $consecutivo)
            ->when($excluirFichaId !== null, fn ($q) => $q->where('id', '!=', $excluirFichaId))
            ->exists();
    }

    /**
     * Prefijo del consecutivo de la ficha.
     *
     * El legacy numeraba por SUCURSAL (DMC, DMA, DMN…), cada una con su propia
     * secuencia. Se resuelve en cascada:
     *   1. `prefijo_fichas` de la sucursal (heredado del legacy).
     *   2. `prefijo` oficial de la sucursal.
     *   3. `prefijo` de la empresa (respaldo, para fichas sin sucursal).
     */
    private function prefijoDeFicha(FichFicha $ficha): string
    {
        if ($ficha->id_sucursal !== null) {
            $sucursal = DB::table('config_ubi_sucursales')
                ->whereKey($ficha->id_sucursal)
                ->first(['prefijo', 'prefijo_fichas']);

            $prefijo = $sucursal->prefijo_fichas ?? $sucursal->prefijo ?? null;

            if (is_string($prefijo) && trim($prefijo) !== '') {
                return strtoupper(trim($prefijo));
            }
        }

        // Respaldo: prefijo de la empresa (fichas sin sucursal asignada).
        if ($ficha->id_empresa !== null) {
            $prefijo = Empresa::query()->whereKey($ficha->id_empresa)->value('prefijo');

            if (is_string($prefijo) && trim($prefijo) !== '') {
                return strtoupper(trim($prefijo));
            }
        }

        throw new RuntimeException(
            'La ficha no tiene sucursal ni empresa con prefijo configurado; no se puede generar el consecutivo.'
        );
    }
}
