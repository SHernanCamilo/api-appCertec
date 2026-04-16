<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza empleados de config_person_tercero con datos del tenant Microsoft.
 *
 * Campos que actualiza:
 *   - email       ← mail (Graph API)
 *   - unidad      ← department (Graph API)
 *   - id_cargo    ← jobTitle (Graph API) → busca/crea en config_cargo
 *
 * Busca empleados por officeLocation (cédula) en el tenant.
 */
class SincronizarEmpleadosTenant extends Command
{
    protected $signature = 'empleados:sync-tenant
                            {--empresa=1 : ID de la empresa a sincronizar}
                            {--cedula= : Sincronizar solo una cédula específica}
                            {--dry-run : Solo mostrar cambios sin aplicar}';

    protected $description = 'Sincroniza empleados con datos del tenant Microsoft (email, unidad, cargo)';

    private string $accessToken = '';

    public function handle(): int
    {
        $empresaId = (int) $this->option('empresa');
        $cedulaEspecifica = $this->option('cedula');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('MODO DRY-RUN: No se aplicarán cambios');
        }

        // Obtener token
        $this->info('Obteniendo token del tenant...');
        if (!$this->obtenerToken()) {
            $this->error('No se pudo obtener el token');
            return 1;
        }
        $this->info('Token obtenido OK');

        // Obtener empleados a sincronizar
        $query = DB::table('config_person_tercero')
            ->where('id_empresa', $empresaId)
            ->where('estado', 1);

        if ($cedulaEspecifica) {
            $query->where('numero_identificacion', $cedulaEspecifica);
        }

        $empleados = $query->get();
        $total = $empleados->count();
        $this->info("Empleados a sincronizar: {$total}");

        $actualizados = 0;
        $errores = 0;
        $sinCambios = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($empleados as $empleado) {
            $resultado = $this->sincronizarEmpleado($empleado, $empresaId, $dryRun);

            match ($resultado) {
                'actualizado' => $actualizados++,
                'sin_cambios' => $sinCambios++,
                'error' => $errores++,
            };

            $bar->advance();
            usleep(100000); // 100ms entre requests para no saturar la API
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Resultados:");
        $this->info("  Actualizados: {$actualizados}");
        $this->info("  Sin cambios:  {$sinCambios}");
        $this->warn("  Errores:      {$errores}");

        return 0;
    }

    private function obtenerToken(): bool
    {
        $tenantId = config('services.microsoft.medilaser_tenant_id', env('MICROSOFT_MEDILASER_TENANT_ID'));
        $clientId = config('services.microsoft.client_id', env('MICROSOFT_CLIENT_ID'));
        $clientSecret = config('services.microsoft.client_secret', env('MICROSOFT_CLIENT_SECRET'));

        $ch = curl_init("https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if (isset($response['access_token'])) {
            $this->accessToken = $response['access_token'];
            return true;
        }

        return false;
    }

    private function sincronizarEmpleado(object $empleado, int $empresaId, bool $dryRun): string
    {
        $cedula = $empleado->numero_identificacion;

        // Buscar en Graph API por officeLocation (cédula)
        $tenantUser = $this->buscarEnTenant($cedula);

        if (!$tenantUser) {
            return 'error';
        }

        $jobTitle = $tenantUser['jobTitle'] ?? null;
        $department = $tenantUser['department'] ?? null;
        $email = $tenantUser['mail'] ?? null;

        // Verificar si hay cambios
        $cambios = [];

        if ($email && $email !== $empleado->email) {
            $cambios['email'] = $email;
        }

        if ($department && $department !== $empleado->unidad) {
            $cambios['unidad'] = $department;
        }

        if ($jobTitle) {
            $cargoId = $this->resolverCargo($jobTitle, $empresaId);
            if ($cargoId && $cargoId !== $empleado->id_cargo) {
                $cambios['id_cargo'] = $cargoId;
            }
        }

        if (empty($cambios)) {
            return 'sin_cambios';
        }

        if ($dryRun) {
            $this->newLine();
            $this->line("  [{$cedula}] {$empleado->nombre}:");
            foreach ($cambios as $campo => $valor) {
                $anterior = $empleado->$campo ?? 'NULL';
                $this->line("    {$campo}: {$anterior} → {$valor}");
            }
            return 'actualizado';
        }

        // Aplicar cambios
        $cambios['updated_at'] = now();
        DB::table('config_person_tercero')
            ->where('id', $empleado->id)
            ->update($cambios);

        return 'actualizado';
    }

    private function buscarEnTenant(string $cedula): ?array
    {
        // Graph API no soporta filtro por officeLocation, usamos $search con ConsistencyLevel
        $select = "displayName,mail,jobTitle,department,officeLocation";
        $search = urlencode("\"officeLocation:{$cedula}\"");
        $url = "https://graph.microsoft.com/v1.0/users?\$search={$search}&\$select={$select}&\$top=1";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->accessToken}",
                "Content-Type: application/json",
                "ConsistencyLevel: eventual",
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = json_decode(curl_exec($ch), true);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response['value'])) {
            return null;
        }

        // Verificar que el officeLocation coincida exactamente
        foreach ($response['value'] as $user) {
            if (($user['officeLocation'] ?? '') === $cedula) {
                return $user;
            }
        }

        return null;
    }

    private function resolverCargo(string $jobTitle, int $empresaId): ?int
    {
        $cargo = DB::table('config_cargo')
            ->where('nombre_cargo', $jobTitle)
            ->where('id_empresa', $empresaId)
            ->first();

        if ($cargo) {
            return $cargo->id_cargo;
        }

        // Crear cargo si no existe
        return DB::table('config_cargo')->insertGetId([
            'nombre_cargo' => $jobTitle,
            'nivel_jerarquico' => 3,
            'id_empresa' => $empresaId,
            'estado' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
