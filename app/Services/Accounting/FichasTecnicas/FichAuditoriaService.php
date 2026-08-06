<?php

declare(strict_types=1);

namespace App\Services\Accounting\FichasTecnicas;

use App\Models\Accounting\FichasTecnicas\FichHistorialEstado;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puente entre el contexto de la aplicación y los triggers de base de datos.
 *
 * Los triggers `trg_fich_fichas_ai` / `trg_fich_fichas_au` escriben la bitácora
 * de estados, pero no tienen acceso al usuario autenticado. Este servicio fija
 * las variables de sesión de MySQL que los triggers consultan, de modo que la
 * auditoría queda completa sin duplicar inserciones desde PHP.
 *
 * Importante: las variables son por conexión, así que deben fijarse dentro de
 * la misma transacción/petición en la que se ejecuta la escritura.
 */
final class FichAuditoriaService
{
    public function marcarUsuario(int $usuarioId, ?string $observacion = null): void
    {
        DB::statement('SET @fich_usuario_actual = ?', [$usuarioId]);
        DB::statement('SET @fich_observacion_actual = ?', [$observacion ?? '']);
    }

    public function limpiar(): void
    {
        DB::statement('SET @fich_usuario_actual = NULL');
        DB::statement('SET @fich_observacion_actual = NULL');
    }

    /**
     * Bitácora completa de una ficha.
     *
     * @return Collection<int, FichHistorialEstado>
     */
    public function historial(int $idFicha): Collection
    {
        return FichHistorialEstado::query()
            ->with(['estadoAnterior:id,codigo,descripcion', 'estadoNuevo:id,codigo,descripcion', 'usuario:id,name,email'])
            ->where('id_ficha', $idFicha)
            ->orderByDesc('id')
            ->get();
    }

    /** Fuerza el recálculo de los contadores denormalizados de una ficha. */
    public function recalcularTotales(int $idFicha): void
    {
        DB::statement('CALL sp_fich_recalcular_totales(?)', [$idFicha]);
    }
}
