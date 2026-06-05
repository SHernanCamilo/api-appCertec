<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ConfigPersonTercero;
use App\Models\Turnos\ConfigUnidadFuncional;
use Illuminate\Support\Str;

/**
 * Sincroniza las unidades funcionales desde los valores únicos del campo `unidad`
 * de los empleados (config_person_tercero).
 *
 * Cada valor único como "MA-TALENTO HUMANO NAL", "NVA-MEDICA", etc. se convierte
 * en un registro de config_unidades_funcionales con:
 *   - codigo: el mismo texto en mayúsculas (truncado a 50 chars)
 *   - nombre: el mismo texto
 *   - id_empresa: la empresa más frecuente entre los empleados con esa unidad
 *   - id_sede: NULL (las sedes pueden asignarse después)
 *
 * Uso:
 *   php artisan turnos:sync-unidades
 *   php artisan turnos:sync-unidades --dry-run  (solo muestra qué crearía)
 *   php artisan turnos:sync-unidades --force    (no pide confirmación)
 */
class SincronizarUnidadesFuncionales extends Command
{
    protected $signature = 'turnos:sync-unidades
                            {--dry-run : Solo muestra qué unidades se crearían sin guardar}
                            {--force : No pide confirmación}';

    protected $description = 'Crea las unidades funcionales a partir de los valores únicos del campo `unidad` de los empleados';

    public function handle(): int
    {
        $this->info('🔍 Buscando valores únicos en config_person_tercero.unidad ...');

        // Valores únicos no vacíos
        $valores = ConfigPersonTercero::whereNotNull('unidad')
            ->where('unidad', '!=', '')
            ->select('unidad')
            ->distinct()
            ->pluck('unidad')
            ->map(fn($u) => trim((string) $u))
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $this->info('📊 Encontrados ' . count($valores) . ' valores únicos.');

        if (empty($valores)) {
            $this->warn('No hay valores en el campo `unidad`. Nada por sincronizar.');
            return self::SUCCESS;
        }

        // Empresa por defecto: la primera empresa activa (fallback)
        $empresaDefault = \DB::table('ent_empresas')->orderBy('id')->value('id');

        $aCrear = [];
        $existentes = [];

        foreach ($valores as $valor) {
            $codigo = $this->generarCodigo($valor);

            // Determinar empresa más frecuente para esa unidad
            $idEmpresa = ConfigPersonTercero::where('unidad', $valor)
                ->whereNotNull('id_empresa')
                ->select('id_empresa', \DB::raw('COUNT(*) as total'))
                ->groupBy('id_empresa')
                ->orderByDesc('total')
                ->value('id_empresa') ?? $empresaDefault;

            // ¿Ya existe?
            $existe = ConfigUnidadFuncional::where('codigo', $codigo)
                ->orWhere('nombre', $valor)
                ->exists();

            if ($existe) {
                $existentes[] = $valor;
                continue;
            }

            $aCrear[] = [
                'codigo'     => $codigo,
                'nombre'     => $valor,
                'id_empresa' => $idEmpresa,
                'estado'     => true,
            ];
        }

        // Resumen
        $this->newLine();
        $this->info("✓ Por crear:    " . count($aCrear));
        $this->info("✓ Ya existían:  " . count($existentes));
        $this->newLine();

        if (count($aCrear) > 0) {
            $this->table(
                ['Código', 'Nombre', 'Empresa'],
                collect($aCrear)->take(15)->map(fn($u) => [
                    $u['codigo'],
                    Str::limit($u['nombre'], 50),
                    $u['id_empresa'],
                ])->toArray()
            );
            if (count($aCrear) > 15) {
                $this->line('  ... y ' . (count($aCrear) - 15) . ' más');
            }
        }

        if ($this->option('dry-run')) {
            $this->warn('🟡 Modo DRY-RUN: no se guardó nada.');
            return self::SUCCESS;
        }

        if (empty($aCrear)) {
            $this->info('Nada por crear. Salir.');
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm("¿Crear " . count($aCrear) . " unidades funcionales?", true)) {
            $this->warn('Cancelado.');
            return self::SUCCESS;
        }

        // Insertar uno por uno con manejo de duplicados de código
        $bar = $this->output->createProgressBar(count($aCrear));
        $bar->start();

        $codigosUsados = ConfigUnidadFuncional::pluck('codigo')->toArray();
        $creadas = 0;
        $omitidas = 0;

        foreach ($aCrear as $u) {
            // Si el código ya está usado, generar uno único agregando sufijo numérico
            $codigoFinal = $u['codigo'];
            $i = 2;
            while (in_array($codigoFinal, $codigosUsados, true)) {
                $base = Str::limit($u['codigo'], 47, '');
                $codigoFinal = "{$base}_{$i}";
                $i++;
            }

            // Si el nombre ya existe (case-insensitive), saltar
            $existeNombre = ConfigUnidadFuncional::whereRaw('LOWER(nombre) = ?', [strtolower($u['nombre'])])
                ->exists();
            if ($existeNombre) {
                $omitidas++;
                $bar->advance();
                continue;
            }

            try {
                ConfigUnidadFuncional::create([
                    'codigo'     => $codigoFinal,
                    'nombre'     => $u['nombre'],
                    'id_empresa' => $u['id_empresa'],
                    'estado'     => $u['estado'],
                ]);
                $codigosUsados[] = $codigoFinal;
                $creadas++;
            } catch (\Throwable $e) {
                $omitidas++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ Sincronización completada.");
        $this->info("   Creadas:  {$creadas}");
        $this->info("   Omitidas: {$omitidas}");

        return self::SUCCESS;
    }

    /**
     * Genera un código corto a partir del nombre.
     * Limita a 50 caracteres y reemplaza espacios por guiones bajos.
     */
    private function generarCodigo(string $nombre): string
    {
        $codigo = strtoupper(trim($nombre));
        $codigo = preg_replace('/\s+/', '_', $codigo);
        $codigo = preg_replace('/[^A-Z0-9_\-]/', '', $codigo);

        return Str::limit($codigo, 50, '');
    }
}
