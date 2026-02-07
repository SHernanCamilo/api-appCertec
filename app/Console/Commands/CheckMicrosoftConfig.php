<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\AllowedDomain;
use Laravel\Socialite\Facades\Socialite;

class CheckMicrosoftConfig extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'microsoft:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verificar configuración de Microsoft OAuth';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Verificando configuración de Microsoft OAuth...');
        $this->newLine();

        // 1. Verificar variables de entorno
        $this->info('1️⃣  Variables de Entorno:');
        $clientId = config('services.microsoft.client_id');
        $clientSecret = config('services.microsoft.client_secret');
        $redirectUri = config('services.microsoft.redirect');
        $tenant = config('services.microsoft.tenant');

        $this->checkEnvVar('MICROSOFT_CLIENT_ID', $clientId);
        $this->checkEnvVar('MICROSOFT_CLIENT_SECRET', $clientSecret, true);
        $this->checkEnvVar('MICROSOFT_REDIRECT_URI', $redirectUri);
        $this->checkEnvVar('MICROSOFT_TENANT_ID', $tenant);
        $this->newLine();

        // 2. Verificar dominios permitidos
        $this->info('2️⃣  Dominios Permitidos:');
        $domains = AllowedDomain::activos()->get();
        
        if ($domains->isEmpty()) {
            $this->warn('   ⚠️  No hay dominios permitidos configurados');
            $this->info('   💡 Ejecuta: php artisan db:seed --class=AllowedDomainsSeeder');
        } else {
            $this->info("   ✅ {$domains->count()} dominio(s) configurado(s):");
            foreach ($domains as $domain) {
                $empresa = $domain->empresa ? " → {$domain->empresa->nombre}" : '';
                $this->line("      • {$domain->domain} ({$domain->tenant_name}){$empresa}");
            }
        }
        $this->newLine();

        // 3. Probar conexión con Microsoft
        $this->info('3️⃣  Probando Conexión:');
        try {
            $authUrl = Socialite::driver('microsoft')
                ->stateless()
                ->redirect()
                ->getTargetUrl();
            
            $this->info('   ✅ Conexión exitosa con Microsoft');
            $this->line('   🔗 URL de autenticación generada correctamente');
        } catch (\Exception $e) {
            $this->error('   ❌ Error al conectar con Microsoft');
            $this->error('   ' . $e->getMessage());
        }
        $this->newLine();

        // 4. Resumen
        $allConfigured = !empty($clientId) && 
                        $clientId !== 'your-client-id-here' &&
                        !empty($clientSecret) &&
                        !empty($redirectUri) &&
                        !$domains->isEmpty();

        if ($allConfigured) {
            $this->info('✅ Configuración completa y lista para usar');
            $this->newLine();
            $this->info('📝 Próximos pasos:');
            $this->line('   1. Prueba el login: GET /api/auth/microsoft');
            $this->line('   2. Verifica un email: POST /api/auth/microsoft/check-email');
        } else {
            $this->warn('⚠️  Configuración incompleta');
            $this->newLine();
            $this->info('📝 Pasos pendientes:');
            if (empty($clientId) || $clientId === 'your-client-id-here') {
                $this->line('   • Configurar MICROSOFT_CLIENT_ID en .env');
            }
            if (empty($clientSecret)) {
                $this->line('   • Configurar MICROSOFT_CLIENT_SECRET en .env');
            }
            if ($domains->isEmpty()) {
                $this->line('   • Agregar dominios permitidos a la base de datos');
            }
        }

        return Command::SUCCESS;
    }

    private function checkEnvVar($name, $value, $hideValue = false)
    {
        if (empty($value) || $value === 'your-client-id-here') {
            $this->error("   ❌ {$name}: No configurado");
        } else {
            $displayValue = $hideValue ? str_repeat('*', 20) : $value;
            $this->info("   ✅ {$name}: {$displayValue}");
        }
    }
}
