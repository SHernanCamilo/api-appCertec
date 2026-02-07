<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('allowed_domains', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 100)->unique(); // ej: @empresa.com
            $table->string('tenant_id', 100)->nullable(); // ID del tenant de Microsoft
            $table->string('tenant_name', 100)->nullable(); // Nombre del tenant
            $table->unsignedBigInteger('id_empresa')->nullable(); // Empresa asociada
            $table->tinyInteger('activo')->default(1); // 1=activo, 0=inactivo
            $table->text('descripcion')->nullable();
            $table->timestamps();
            
            $table->foreign('id_empresa')->references('id')->on('ent_empresas')->onDelete('set null');
            $table->index('domain');
            $table->index('tenant_id');
            $table->index('activo');
        });

        // Agregar campos a la tabla users para Microsoft
        Schema::table('users', function (Blueprint $table) {
            $table->string('microsoft_id')->nullable()->unique()->after('email');
            $table->string('tenant_id')->nullable()->after('microsoft_id');
            $table->string('avatar')->nullable()->after('tenant_id');
            $table->string('auth_type')->default('local')->after('avatar'); // 'local' o 'microsoft'
            
            $table->index('microsoft_id');
            $table->index('tenant_id');
            $table->index('auth_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['microsoft_id']);
            $table->dropIndex(['tenant_id']);
            $table->dropIndex(['auth_type']);
            $table->dropColumn(['microsoft_id', 'tenant_id', 'avatar', 'auth_type']);
        });
        
        Schema::dropIfExists('allowed_domains');
    }
};
