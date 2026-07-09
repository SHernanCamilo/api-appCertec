<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabla de links OData con niveles de seguridad
        Schema::create('odata_links', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique()->comment('Código único del link (UUID o hash)');
            $table->string('name', 150)->comment('Nombre descriptivo del link');
            $table->enum('visibility', ['private', 'organizational', 'public'])
                ->default('private')
                ->comment('private=solo creador, organizational=@medilaser, public=cualquiera con token');
            $table->unsignedBigInteger('created_by')->comment('Usuario que creó el link');
            $table->string('created_by_email', 100);

            // Configuración de la vista
            $table->string('schema_name', 20);
            $table->string('view_name', 150);
            $table->json('columns')->nullable()->comment('Columnas permitidas (null=todas)');
            $table->json('filters')->nullable()->comment('Filtros pre-aplicados fijos');
            $table->string('sort_col', 100)->nullable();
            $table->string('sort_dir', 4)->default('asc');
            $table->integer('max_rows')->default(100000)->comment('Límite máximo de filas');

            // Seguridad
            $table->string('token_hash', 128)->nullable()->comment('Hash HMAC del token público');
            $table->timestamp('expires_at')->nullable()->comment('Expiración del link (null=sin expiración)');
            $table->json('allowed_ips')->nullable()->comment('IPs permitidas (null=cualquiera)');
            $table->json('allowed_users')->nullable()->comment('Emails específicos permitidos (para organizational)');
            $table->boolean('active')->default(true);

            // Estadísticas
            $table->integer('access_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['schema_name', 'view_name']);
            $table->index('visibility');
            $table->index('active');
        });

        // Tabla de auditoría de accesos OData
        Schema::create('odata_access_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('odata_link_id')->nullable();
            $table->string('user_email', 100)->nullable();
            $table->string('user_name', 150)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('schema_name', 50);
            $table->string('view_name', 200);
            $table->string('visibility', 20)->default('odata');
            $table->text('filter_applied')->nullable();
            $table->integer('top')->default(1000);
            $table->integer('skip')->default(0);
            $table->integer('rows_returned')->default(0);
            $table->integer('elapsed_ms')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->enum('auth_method', ['azure_ad', 'token_public', 'signed_url'])
                ->default('azure_ad');
            $table->timestamp('accessed_at')->useCurrent();

            $table->foreign('odata_link_id')->references('id')->on('odata_links')->nullOnDelete();
            $table->index('user_email');
            $table->index(['schema_name', 'view_name']);
            $table->index('accessed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odata_access_logs');
        Schema::dropIfExists('odata_links');
    }
};
