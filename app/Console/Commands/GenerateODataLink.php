<?php

namespace App\Console\Commands;

use App\Models\BiGrupo;
use App\Models\BiVista;
use App\Models\OdataApiKey;
use App\Models\OdataLink;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateODataLink extends Command
{
    protected $signature = 'fabric:odata-link
        {email : Correo del usuario a autorizar}
        {schema : Esquema (ej: pt, in)}
        {view : Nombre de la vista}
        {--visibility=private : Visibilidad del link (private, organizational, public)}';

    protected $description = 'Genera un link OData, asigna permisos al usuario y le genera una API Key si no tiene una.';

    public function handle(): int
    {
        $email = $this->argument('email');
        $schema = strtolower($this->argument('schema'));
        $viewName = $this->argument('view');
        $visibility = $this->option('visibility');

        if (!in_array($visibility, ['private', 'organizational', 'public'])) {
            $this->error('Visibilidad inválida. Debe ser private, organizational o public.');
            return 1;
        }

        // 1. Validar Usuario
        $user = User::where('email', $email)->first();
        if (!$user) {
            $this->error("Usuario con email {$email} no encontrado.");
            return 1;
        }

        // 2. Validar Esquema y Vista
        $grupo = BiGrupo::where('codigo', strtoupper($schema))->first();
        if (!$grupo) {
            $this->error("Esquema {$schema} no encontrado.");
            return 1;
        }

        $vista = BiVista::where('id_bi_grupos', $grupo->id)->where('nombre', $viewName)->first();
        if (!$vista) {
            $this->error("Vista {$viewName} no encontrada en el esquema {$schema}.");
            return 1;
        }

        // 3. Asignar permiso explícito para actualizar desde Excel
        DB::table('bi_vista_user_permissions')->updateOrInsert(
            ['bi_vista_id' => $vista->id, 'user_id' => $user->id],
            ['created_at' => now(), 'updated_at' => now()]
        );
        $this->info("✓ Permiso asignado al usuario {$email} para la vista {$viewName}.");

        // 4. Generar API Key (si no tiene una activa)
        $apiKeyStr = null;
        if ($visibility !== 'public') {
            $existingKey = OdataApiKey::where('user_id', $user->id)->where('active', true)->first();
            if (!$existingKey) {
                $keyData = OdataApiKey::generateKey();
                OdataApiKey::create([
                    'user_id'    => $user->id,
                    'name'       => 'Consola - Acceso Excel',
                    'key_hash'   => $keyData['hash'],
                    'key_prefix' => $keyData['prefix'],
                ]);
                $apiKeyStr = $keyData['key'];
                $this->info("✓ Nueva API Key generada para el usuario.");
            } else {
                $this->info("ℹ El usuario ya tiene una API Key activa (prefix: {$existingKey->key_prefix}). Se utilizará esa.");
            }
        }

        // 5. Crear el Link OData
        $code = OdataLink::generateCode();
        $tokenData = null;

        if ($visibility === 'public') {
            $tokenData = OdataLink::generatePublicToken();
        }

        $link = OdataLink::create([
            'code' => $code,
            'name' => "Generado desde consola ({$schema}.{$viewName})",
            'visibility' => $visibility,
            'created_by' => $user->id,
            'created_by_email' => $user->email,
            'schema_name' => $schema,
            'view_name' => $viewName,
            'token_hash' => $tokenData['hash'] ?? null,
            'allowed_users' => $visibility === 'organizational' ? [$email] : null,
        ]);

        $this->info("✓ Link OData creado exitosamente.");
        $this->newLine();

        // 6. Mostrar resultados
        $this->line("==================================================");
        $this->line("🔗 URL OData:");
        $url = url("/api/fabric/odata/link/{$link->code}");
        if ($visibility === 'public' && $tokenData) {
            $this->line($url . "?token=" . $tokenData['token']);
            $this->warn("⚠️  Este link es PÚBLICO. El token va incluido en la URL.");
        } else {
            $this->line($url);
        }

        if ($visibility !== 'public') {
            $this->line("==================================================");
            $this->line("🔑 Credenciales para Excel (Fuente OData):");
            $this->line("Usuario:    " . $user->email);
            if ($apiKeyStr) {
                $this->line("Contraseña: " . $apiKeyStr);
                $this->warn("⚠️  Guarda la contraseña (API Key), solo se muestra esta vez.");
            } else {
                $this->line("Contraseña: [Utiliza la API Key que ya tenías generada]");
            }
        }
        $this->line("==================================================");

        return 0;
    }
}
