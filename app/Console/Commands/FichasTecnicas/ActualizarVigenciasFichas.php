<?php

declare(strict_types=1);

namespace App\Console\Commands\FichasTecnicas;

use App\Models\Accounting\FichasTecnicas\FichFicha;
use App\Services\Accounting\FichasTecnicas\FichValidacionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Promueve a `vigente` las fichas aprobadas cuya vigencia ya arrancó.
 *
 * El sistema JADE legacy no distinguía "aprobada" de "vigente": una ficha
 * quedaba en estado 5 (FINALIZADA) desde la aprobación, y la vigencia se
 * calculaba comparando `fecha_fin` en cada consulta. Con el rediseño la
 * transición es explícita y auditable: queda registrada en
 * `fich_historial_estados` con su fecha real de entrada en vigencia.
 *
 * Programar en `routes/console.php` o en el scheduler:
 *   $schedule->command('fichas:actualizar-vigencias')->dailyAt('00:15');
 */
class ActualizarVigenciasFichas extends Command
{
    protected $signature = 'fichas:actualizar-vigencias
                            {--dry-run : Muestra las fichas afectadas sin modificarlas}';

    protected $description = 'Promueve a vigente las fichas técnicas aprobadas cuya fecha de inicio ya llegó';

    public function handle(FichValidacionService $validacion): int
    {
        $simulacion = (bool) $this->option('dry-run');

        $fichas = FichFicha::query()
            ->listasParaVigencia()
            ->orderBy('fecha_ini')
            ->get(['id', 'consecutivo', 'id_estado', 'fecha_ini', 'fecha_fin', 'user_aprueba_id']);

        if ($fichas->isEmpty()) {
            $this->info('No hay fichas pendientes de entrar en vigencia.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d ficha(s) que ya iniciaron vigencia:',
            $simulacion ? 'Se promoverían' : 'Promoviendo',
            $fichas->count()
        ));

        $promovidas = 0;
        $fallidas   = 0;

        foreach ($fichas as $ficha) {
            $referencia = $ficha->consecutivo ?? "borrador {$ficha->id}";

            if ($simulacion) {
                $this->line("  · {$referencia} — vigencia desde {$ficha->fecha_ini->toDateString()}");

                continue;
            }

            try {
                $validacion->promoverAVigencia($ficha);
                $this->line("  ✓ {$referencia}");
                $promovidas++;
            } catch (Throwable $e) {
                $this->error("  ✗ {$referencia}: {$e->getMessage()}");

                Log::channel('daily')->error('Fichas Técnicas: falló la promoción a vigente', [
                    'id_ficha' => $ficha->id,
                    'error'    => $e->getMessage(),
                ]);

                $fallidas++;
            }
        }

        if ($simulacion) {
            $this->comment('Simulación: no se modificó ningún registro.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("Fichas promovidas a vigente: {$promovidas}");

        if ($fallidas > 0) {
            $this->warn("Fichas con error: {$fallidas} (revise el log diario)");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
