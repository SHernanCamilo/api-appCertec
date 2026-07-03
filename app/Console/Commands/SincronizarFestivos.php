<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\FestivosComCoProvider;
use App\Models\Turnos\CtFestivo;
use Carbon\Carbon;

class SincronizarFestivos extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'festivos:sincronizar {--next : También sincronizar el próximo año}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Sincroniza festivos desde API externa (festivos.com.co) hacia BD local';

    private FestivosComCoProvider $festivosProvider;

    public function __construct(FestivosComCoProvider $festivosProvider)
    {
        parent::__construct();
        $this->festivosProvider = $festivosProvider;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $anios = [$this->getCurrentYear()];
            
            // Si usa --next, también sincronizar próximo año
            if ($this->option('next')) {
                $anios[] = $this->getCurrentYear() + 1;
            }

            foreach ($anios as $anio) {
                $this->sincronizarAnio($anio);
            }

            $this->info('✅ Sincronización de festivos completada exitosamente.');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('❌ Error durante sincronización: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Sincronizar festivos de un año específico
     */
    private function sincronizarAnio(int $anio): void
    {
        $this->line("📅 Sincronizando festivos para {$anio}...");

        try {
            // Obtener festivos de la API externa
            $festivosExternos = $this->festivosProvider->obtenerFestivos($anio);

            if (empty($festivosExternos)) {
                $this->warn("⚠️  No se obtuvieron festivos para {$anio} desde la API");
                return;
            }

            $insertados = 0;
            $actualizados = 0;

            foreach ($festivosExternos as $festivoExterno) {
                $festivo = CtFestivo::updateOrCreate(
                    ['fecha' => $festivoExterno['fecha']],
                    [
                        'nombre'      => $festivoExterno['nombre'],
                        'tipo'        => $festivoExterno['tipo'] ?? 'festivo',
                        'origen'      => 'api_externa',
                        'estado'      => true,
                        'descripcion' => "Sincronizado desde API el " . Carbon::now()->format('Y-m-d H:i:s')
                    ]
                );

                if ($festivo->wasRecentlyCreated) {
                    $insertados++;
                } else {
                    $actualizados++;
                }
            }

            $this->info("  ✓ {$anio}: {$insertados} insertados, {$actualizados} actualizados");

        } catch (\Exception $e) {
            $this->error("  ✗ Error sincronizando {$anio}: " . $e->getMessage());
        }
    }

    /**
     * Obtener el año actual
     */
    private function getCurrentYear(): int
    {
        return Carbon::now()->year;
    }
}
