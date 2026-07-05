<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Tenant\TenantPersonaSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importa usuarios masivamente desde Excel.
 *
 * Columnas esperadas (flexibles):
 *   Correo, Nombre, Empresa, Cargo
 * Opcionales: Documento / Identificación / Cédula, Unidad, Estado
 *
 * - No crea usuarios que ya existan (por email).
 * - Vincula todos a empresa 1 (Medilaser) por defecto.
 * - Crea o vincula config_person_tercero según cédula o email.
 */
class SincronizarUsuariosExcel extends Command
{
    protected $signature = 'usuarios:sync-excel
                            {file : Ruta al archivo Excel (.xlsx)}
                            {--empresa=1 : ID de empresa (Medilaser = 1)}
                            {--sheet=0 : Índice de hoja (0 = primera)}
                            {--dry-run : Simular sin escribir en BD}';

    protected $description = 'Importa usuarios desde Excel (~800 filas), evita duplicados y vincula empresa Medilaser';

    public function __construct(
        private TenantPersonaSyncService $tenantPersonaSyncService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $filePath = $this->argument('file');
        $empresaId = (int) $this->option('empresa');
        $dryRun = (bool) $this->option('dry-run');
        $sheetIndex = (int) $this->option('sheet');

        if (!is_file($filePath)) {
            $this->error("Archivo no encontrado: {$filePath}");
            return 1;
        }

        if ($dryRun) {
            $this->warn('MODO DRY-RUN: no se aplicarán cambios en BD');
        }

        $this->info("Leyendo Excel: {$filePath}");
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheet($sheetIndex);
        $rows = $sheet->toArray(null, true, true, true);

        if (count($rows) < 2) {
            $this->error('El Excel no tiene filas de datos.');
            return 1;
        }

        $headerRow = array_shift($rows);
        $headerMap = $this->buildHeaderMap($headerRow);

        $this->info('Columnas detectadas: ' . implode(', ', array_values($headerMap)));

        if (!$this->hasAnyHeader($headerMap, ['correo', 'email'])) {
            $this->error('Falta columna Correo/Email en el Excel.');
            return 1;
        }

        if (!$this->hasAnyHeader($headerMap, ['nombre'])) {
            $this->error('Falta columna Nombre en el Excel.');
            return 1;
        }

        $stats = [
            'total_filas'        => count($rows),
            'omitidas_vacias'    => 0,
            'usuarios_creados'   => 0,
            'usuarios_existentes'=> 0,
            'empresa_vinculada'  => 0,
            'terceros_creados'   => 0,
            'terceros_vinculados'=> 0,
            'terceros_omitidos'  => 0,
            'errores'            => 0,
        ];

        $erroresDetalle = [];
        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        foreach ($rows as $rowNum => $row) {
            $bar->advance();
            $lineNumber = (int) $rowNum + 2;
            $data = $this->mapRow($row, $headerMap);

            $email = $this->normalizeEmail($this->pick($data, ['correo', 'email']));
            $nombre = trim((string) ($this->pick($data, ['nombre', 'nombre_completo']) ?? ''));
            $cargo = trim((string) ($this->pick($data, ['cargo', 'nombre_cargo']) ?? ''));
            $cedula = $this->normalizeCedula($this->pick($data, [
                'documento',
                'identificacion',
                'numero_identificacion',
                'cedula',
                'cedula_de_ciudadania',
            ]));
            $unidad = trim((string) ($this->pick($data, ['unidad', 'departamento', 'area']) ?? ''));
            $estadoRaw = $this->pick($data, ['estado', 'activo']);

            if (!$email || !$nombre) {
                $stats['omitidas_vacias']++;
                continue;
            }

            try {
                $result = $dryRun
                    ? $this->simularFila($email, $nombre, $empresaId)
                    : $this->procesarFila(
                        email: $email,
                        nombre: $nombre,
                        cargo: $cargo ?: 'Usuario',
                        cedula: $cedula,
                        unidad: $unidad ?: null,
                        estado: $this->parseEstado($estadoRaw),
                        empresaId: $empresaId,
                    );

                match ($result['usuario']) {
                    'creado'    => $stats['usuarios_creados']++,
                    'existente' => $stats['usuarios_existentes']++,
                    default     => null,
                };

                if ($result['empresa_vinculada']) {
                    $stats['empresa_vinculada']++;
                }

                match ($result['tercero']['accion'] ?? '') {
                    'creado'    => $stats['terceros_creados']++,
                    'vinculado' => $stats['terceros_vinculados']++,
                    'omitido'   => $stats['terceros_omitidos']++,
                    default     => null,
                };
            } catch (\Throwable $e) {
                $stats['errores']++;
                $erroresDetalle[] = [
                    'fila'  => $lineNumber,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Métrica', 'Cantidad'],
            collect($stats)->map(fn ($v, $k) => [str_replace('_', ' ', $k), $v])->values()->all()
        );

        if (!empty($erroresDetalle)) {
            $this->warn('Errores (' . count($erroresDetalle) . '):');
            foreach (array_slice($erroresDetalle, 0, 20) as $err) {
                $this->line("  Fila {$err['fila']} ({$err['email']}): {$err['error']}");
            }
            if (count($erroresDetalle) > 20) {
                $this->line('  ... y ' . (count($erroresDetalle) - 20) . ' más');
            }
        }

        $logPath = storage_path('logs/usuarios_sync_excel.log');
        file_put_contents($logPath, json_encode([
            'fecha'     => now()->toIso8601String(),
            'archivo'   => $filePath,
            'empresa'   => $empresaId,
            'dry_run'   => $dryRun,
            'stats'     => $stats,
            'errores'   => $erroresDetalle,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info("Log guardado en: {$logPath}");

        return $stats['errores'] > 0 ? 1 : 0;
    }

    private function procesarFila(
        string $email,
        string $nombre,
        string $cargo,
        ?string $cedula,
        ?string $unidad,
        bool $estado,
        int $empresaId,
    ): array {
        return DB::transaction(function () use ($email, $nombre, $cargo, $cedula, $unidad, $estado, $empresaId) {
            $user = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();
            $usuarioAccion = 'creado';

            if ($user) {
                $usuarioAccion = 'existente';
                $updates = [];

                if (!$user->cargo && $cargo) {
                    $updates['cargo'] = $cargo;
                }
                if (!$user->numero_identificacion && $cedula) {
                    $updates['numero_identificacion'] = $cedula;
                }
                if (!empty($updates)) {
                    $user->update($updates);
                }
            } else {
                $user = User::create([
                    'name'                  => $nombre,
                    'email'                 => $email,
                    'password'              => Hash::make(Str::random(16)),
                    'auth_type'             => 'local',
                    'cargo'                 => $cargo,
                    'numero_identificacion' => $cedula,
                    'estado'                => $estado,
                    'email_verified_at'     => now(),
                ]);
            }

            $empresaVinculada = $this->vincularEmpresa($user->id, $empresaId);

            $terceroResult = $this->tenantPersonaSyncService->syncFromUser(
                $user,
                [
                    'department' => $unidad,
                    'job_title'  => $cargo,
                ],
                $empresaId,
                null
            );

            return [
                'usuario'           => $usuarioAccion,
                'empresa_vinculada' => $empresaVinculada,
                'tercero'           => $terceroResult,
            ];
        });
    }

    private function simularFila(string $email, string $nombre, int $empresaId): array
    {
        $user = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->first();

        $empresaVinculada = false;
        if ($user) {
            $empresaVinculada = !DB::table('seg_empresa_user')
                ->where('user_id', $user->id)
                ->where('empresa_id', $empresaId)
                ->exists();
        } else {
            $empresaVinculada = true;
        }

        return [
            'usuario'           => $user ? 'existente' : 'creado',
            'empresa_vinculada' => $empresaVinculada,
            'tercero'           => ['accion' => 'omitido', 'motivo' => 'dry_run'],
        ];
    }

    private function vincularEmpresa(int $userId, int $empresaId): bool
    {
        $exists = DB::table('seg_empresa_user')
            ->where('user_id', $userId)
            ->where('empresa_id', $empresaId)
            ->whereNull('id_sucursal')
            ->whereNull('id_sede')
            ->exists();

        if ($exists) {
            return false;
        }

        DB::table('seg_empresa_user')->insert([
            'user_id'     => $userId,
            'empresa_id'  => $empresaId,
            'id_sucursal' => null,
            'id_sede'     => null,
            'recursivo'   => 0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return true;
    }

    private function buildHeaderMap(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $col => $name) {
            $normalized = $this->normalizeHeader($name);
            if ($normalized !== '') {
                $map[$col] = $normalized;
            }
        }

        return $map;
    }

    private function normalizeHeader(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = mb_strtolower(trim((string) $value), 'UTF-8');
        $text = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'n'], $text);

        return preg_replace('/[^a-z0-9_]/', '', str_replace(' ', '_', $text)) ?? '';
    }

    private function mapRow(array $row, array $headerMap): array
    {
        $mapped = [];
        foreach ($headerMap as $col => $name) {
            $mapped[$name] = $row[$col] ?? null;
        }

        return $mapped;
    }

    private function pick(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if ($value !== null && trim((string) $value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function hasAnyHeader(array $headerMap, array $keys): bool
    {
        $headers = array_values($headerMap);

        foreach ($keys as $key) {
            if (in_array($key, $headers, true)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeEmail(?string $email): ?string
    {
        if (!$email) {
            return null;
        }

        $email = strtolower(trim($email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function normalizeCedula(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $cedula = preg_replace('/\D+/', '', (string) $value);

        return $cedula !== '' ? $cedula : null;
    }

    private function parseEstado(mixed $value): bool
    {
        if ($value === null || trim((string) $value) === '') {
            return true;
        }

        $text = mb_strtolower(trim((string) $value), 'UTF-8');

        return !in_array($text, ['0', 'false', 'inactivo', 'no', 'n'], true);
    }
}
