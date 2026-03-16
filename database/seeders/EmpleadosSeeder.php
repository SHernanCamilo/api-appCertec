<?php

namespace Database\Seeders;

use App\Models\Cargo;
use App\Models\Empleado;
use App\Models\Empresa;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

class EmpleadosSeeder extends Seeder
{
    public function run(): void
    {
        $excelPath = env('EMPLEADOS_EXCEL_PATH', 'C:\\Users\\jscabreras\\Desarrollo\\Empleados - Personas y Tecero.xlsx');

        if (!file_exists($excelPath)) {
            throw new \RuntimeException("Archivo Excel no encontrado: {$excelPath}");
        }

        $spreadsheet = IOFactory::load($excelPath);
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, true);

        if (count($rows) === 0) {
            throw new \RuntimeException('El archivo Excel no contiene filas');
        }

        $headerRow = array_shift($rows);
        $headerMap = [];
        foreach ($headerRow as $col => $name) {
            $normalized = $this->normalizeHeader($name);
            if ($normalized !== '') {
                $headerMap[$col] = $normalized;
            }
        }

        $stats = [
            'total' => count($rows),
            'procesados' => 0,
            'insertados' => 0,
            'omitidos' => 0,
            'duplicados_documento' => 0,
            'duplicados_email' => 0,
            'empresas_creadas' => 0,
            'cargos_creados' => 0
        ];

        DB::transaction(function () use ($rows, $headerMap, &$stats) {
            $empresaCache = [];
            $cargoCache = [];
            $cargoResult = $this->ensureCargo('TECNICO DE SISTEMA', $cargoCache);
            if ($cargoResult['created']) {
                $stats['cargos_creados']++;
            }

            foreach ($rows as $row) {
                $stats['procesados']++;
                $data = $this->mapRow($row, $headerMap);

                $nombre = $this->getValue($data, ['nombre_completo', 'nombre']);
                $documento = $this->getValue($data, ['documento', 'numero_identificacion', 'identificacion']);
                $cargoNombre = $this->getValue($data, ['cargo', 'nombre_cargo']);
                $empresaNombre = $this->getValue($data, ['empresa', 'compania']);
                $email = $this->getValue($data, ['email', 'correo', 'correo_electronico']);

                if (!$nombre || !$documento || !$empresaNombre) {
                    $stats['omitidos']++;
                    continue;
                }

                $numeroIdentificacion = trim((string) $documento);
                $nombreEmpleado = trim((string) $nombre);
                $empresaClean = trim((string) $empresaNombre);
                $cargoClean = $cargoNombre ? trim((string) $cargoNombre) : null;
                $emailClean = $email ? trim((string) $email) : null;

                if (Empleado::where('numero_identificacion', $numeroIdentificacion)->exists()) {
                    $stats['duplicados_documento']++;
                    $stats['omitidos']++;
                    continue;
                }

                if ($emailClean && Empleado::where('email', $emailClean)->exists()) {
                    $stats['duplicados_email']++;
                    $stats['omitidos']++;
                    continue;
                }

                $empresaResult = $this->ensureEmpresa($empresaClean, $empresaCache);
                $idEmpresa = $empresaResult['id'];
                if ($empresaResult['created']) {
                    $stats['empresas_creadas']++;
                }

                $idCargo = null;
                if ($cargoClean) {
                    $cargoResult = $this->ensureCargo($cargoClean, $cargoCache);
                    $idCargo = $cargoResult['id'];
                    if ($cargoResult['created']) {
                        $stats['cargos_creados']++;
                    }
                }

                Empleado::create([
                    'id_empresa' => $idEmpresa,
                    'id_cargo' => $idCargo,
                    'numero_identificacion' => $numeroIdentificacion,
                    'nombre' => $nombreEmpleado,
                    'email' => $emailClean,
                    'tipo_identificacion' => 'CC',
                    'estado' => true
                ]);

                $stats['insertados']++;
            }
        });

        $logPath = storage_path('logs/empleados_seeder.log');
        $logData = [
            'fecha' => now()->toISOString(),
            'archivo' => $excelPath,
            'estadisticas' => $stats
        ];
        file_put_contents($logPath, json_encode($logData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function normalizeHeader($value): string
    {
        if ($value === null) {
            return '';
        }

        return preg_replace('/[^a-z0-9_]/', '', str_replace(' ', '_', strtolower(trim((string) $value)))) ?? '';
    }

    private function mapRow(array $row, array $headerMap): array
    {
        $mapped = [];
        foreach ($headerMap as $col => $name) {
            $mapped[$name] = $row[$col] ?? null;
        }
        return $mapped;
    }

    private function getValue(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }
        return null;
    }

    private function ensureEmpresa(string $nombre, array &$cache): array
    {
        if (array_key_exists($nombre, $cache)) {
            return ['id' => $cache[$nombre], 'created' => false];
        }

        $empresa = Empresa::where('nombre', $nombre)->first();
        if ($empresa) {
            $cache[$nombre] = $empresa->id;
            return ['id' => $empresa->id, 'created' => false];
        }

        $prefijo = $this->buildPrefijo($nombre);
        $empresa = Empresa::create([
            'nombre' => $nombre,
            'prefijo' => $prefijo,
            'rep_legal' => 'Temporal',
            'cc_rep_legal' => random_int(10000000, 99999999),
            'direccion' => 'Temporal',
            'telefono' => random_int(3000000000, 3999999999),
            'nit' => $this->generateNit(),
            'estado' => 1
        ]);

        $cache[$nombre] = $empresa->id;
        return ['id' => $empresa->id, 'created' => true];
    }

    private function ensureCargo(string $nombre, array &$cache): array
    {
        if (array_key_exists($nombre, $cache)) {
            return ['id' => $cache[$nombre], 'created' => false];
        }

        $cargo = Cargo::where('nombre_cargo', $nombre)->first();
        if ($cargo) {
            $cache[$nombre] = $cargo->id_cargo;
            return ['id' => $cargo->id_cargo, 'created' => false];
        }

        $cargo = Cargo::create([
            'nombre_cargo' => $nombre,
            'descripcion' => null,
            'estado' => true
        ]);

        $cache[$nombre] = $cargo->id_cargo;
        return ['id' => $cargo->id_cargo, 'created' => true];
    }

    private function buildPrefijo(string $nombre): string
    {
        $clean = preg_replace('/[^A-Za-z0-9 ]/', ' ', $nombre) ?? $nombre;
        $parts = array_values(array_filter(preg_split('/\s+/', trim($clean)) ?: []));
        $prefijo = strtoupper(implode('', array_map(fn ($p) => $p[0], $parts)));

        if (strlen($prefijo) < 3) {
            $prefijo = strtoupper(str_replace(' ', '', $clean));
        }

        return Str::limit($prefijo ?: 'EMP', 5, '');
    }

    private function generateNit(): int
    {
        do {
            $nit = random_int(100000000, 999999999);
        } while (Empresa::where('nit', $nit)->exists());

        return $nit;
    }
}
