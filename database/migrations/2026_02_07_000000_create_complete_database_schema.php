<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración consolidada del esquema completo de la base de datos
 * Generada el: 2026-02-07
 * 
 * Esta migración crea todas las tablas del sistema en el orden correcto
 * respetando las dependencias de foreign keys.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ============================================
        // 1. TABLAS BASE (sin dependencias)
        // ============================================
        
        // 1.1 Tabla de usuarios
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('tipo_identificacion', 10)->nullable();
                $table->string('numero_identificacion', 50)->nullable();
                $table->string('direccion', 255)->nullable();
                $table->string('telefono', 20)->nullable();
                $table->string('cargo', 255)->nullable();
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->string('microsoft_id')->nullable()->unique();
                $table->enum('auth_type', ['local', 'microsoft'])->default('local');
                $table->boolean('estado')->default(true);
                $table->unsignedBigInteger('id_sucursal')->nullable();
                $table->unsignedBigInteger('id_sede')->nullable();
                $table->rememberToken();
                $table->timestamps();
                
                $table->index('email');
                $table->index('microsoft_id');
                $table->index('estado');
            });
        }

        // 1.2 Tabla de tokens de reseteo de contraseña
        if (!Schema::hasTable('password_reset_tokens')) {
            Schema::create('password_reset_tokens', function (Blueprint $table) {
                $table->string('email')->primary();
                $table->string('token');
                $table->timestamp('created_at')->nullable();
            });
        }

        // 1.3 Tabla de trabajos fallidos
        if (!Schema::hasTable('failed_jobs')) {
            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }

        // 1.4 Tabla de tokens de acceso personal
        if (!Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        // ============================================
        // 2. TABLAS DE EMPRESAS Y UBICACIONES
        // ============================================
        
        // 2.1 Tabla de empresas
        if (!Schema::hasTable('ent_empresas')) {
            Schema::create('ent_empresas', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 50)->charset('utf8')->collation('utf8_general_ci');
                $table->string('prefijo', 5)->charset('utf8')->collation('utf8_general_ci');
                $table->string('rep_legal', 50)->charset('utf8')->collation('utf8_general_ci');
                $table->integer('cc_rep_legal');
                $table->string('direccion', 50)->charset('utf8')->collation('utf8_general_ci');
                $table->bigInteger('telefono');
                $table->bigInteger('nit');
                $table->string('logo', 255)->charset('utf8')->collation('utf8_general_ci')->nullable();
                $table->tinyInteger('estado')->default(1);
                $table->timestamps();
                
                $table->index('nombre');
                $table->index('rep_legal');
                $table->index('direccion');
                $table->index('telefono');
                $table->index('nit');
            });
        }

        // 2.2 Tabla de sucursales
        if (!Schema::hasTable('config_ubi_sucursales')) {
            Schema::create('config_ubi_sucursales', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 50)->charset('utf8')->collation('utf8_general_ci');
                $table->unsignedBigInteger('id_Empresa');
                $table->timestamps();
                
                $table->foreign('id_Empresa')->references('id')->on('ent_empresas')->onDelete('cascade');
                $table->index('nombre');
                $table->index('id_Empresa');
            });
        }

        // 2.3 Tabla de sedes
        if (!Schema::hasTable('config_ubi_sede')) {
            Schema::create('config_ubi_sede', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 50)->charset('utf8')->collation('utf8_general_ci');
                $table->unsignedBigInteger('id_Sucursal');
                $table->timestamps();
                
                $table->foreign('id_Sucursal')->references('id')->on('config_ubi_sucursales')->onDelete('cascade');
                $table->index('nombre');
                $table->index('id_Sucursal');
            });
        }

        // 2.4 Tabla de dominios permitidos
        if (!Schema::hasTable('allowed_domains')) {
            Schema::create('allowed_domains', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('empresa_id');
                $table->string('domain', 255);
                $table->boolean('activo')->default(true);
                $table->timestamps();
                
                $table->foreign('empresa_id')->references('id')->on('ent_empresas')->onDelete('cascade');
                $table->unique(['empresa_id', 'domain']);
                $table->index('domain');
                $table->index('activo');
            });
        }

        // ============================================
        // 3. SISTEMA DE PERMISOS Y ROLES
        // ============================================
        
        // 3.1 Tabla de módulos (con jerarquía)
        if (!Schema::hasTable('seg_modulos')) {
            Schema::create('seg_modulos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_modulo_padre')->nullable();
                $table->string('nombre', 50)->charset('utf8')->collation('utf8_general_ci');
                $table->string('codigo', 20)->unique()->charset('utf8')->collation('utf8_general_ci');
                $table->string('descripcion', 255)->nullable()->charset('utf8')->collation('utf8_general_ci');
                $table->string('icono', 50)->nullable();
                $table->string('ruta', 100)->nullable();
                $table->integer('orden')->default(0);
                $table->integer('nivel')->default(0);
                $table->tinyInteger('estado')->default(1);
                $table->timestamps();
                
                $table->foreign('id_modulo_padre')->references('id')->on('seg_modulos')->onDelete('cascade');
                $table->index('codigo');
                $table->index('estado');
                $table->index('id_modulo_padre');
                $table->index('nivel');
            });
        }

        // 3.2 Tabla de permisos por módulo
        if (!Schema::hasTable('seg_permisos')) {
            Schema::create('seg_permisos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_modulo');
                $table->string('nombre', 100);
                $table->string('codigo', 50)->unique();
                $table->text('descripcion')->nullable();
                $table->enum('tipo', ['boton', 'accion', 'menu'])->default('boton');
                $table->string('icono', 50)->nullable();
                $table->integer('orden')->default(0);
                $table->boolean('estado')->default(true);
                $table->timestamps();

                $table->foreign('id_modulo')->references('id')->on('seg_modulos')->onDelete('cascade');
                $table->index('id_modulo');
                $table->index('codigo');
                $table->index('tipo');
                $table->index('estado');
            });
        }

        // 3.3 Tabla pivote: módulos por empresa
        if (!Schema::hasTable('seg_modulo_empresa')) {
            Schema::create('seg_modulo_empresa', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_modulo');
                $table->unsignedBigInteger('id_empresa');
                $table->tinyInteger('activo')->default(1);
                $table->tinyInteger('hereda_hijos')->default(1);
                $table->timestamps();
                
                $table->foreign('id_modulo')->references('id')->on('seg_modulos')->onDelete('cascade');
                $table->foreign('id_empresa')->references('id')->on('ent_empresas')->onDelete('cascade');
                $table->unique(['id_modulo', 'id_empresa']);
                $table->index('id_modulo');
                $table->index('id_empresa');
                $table->index('activo');
            });
        }

        // 3.4 Tabla de perfiles
        if (!Schema::hasTable('seg_perfiles')) {
            Schema::create('seg_perfiles', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 50)->charset('utf8')->collation('utf8_general_ci');
                $table->string('codigo', 20)->unique()->charset('utf8')->collation('utf8_general_ci');
                $table->unsignedBigInteger('id_modulo');
                $table->string('descripcion', 255)->nullable()->charset('utf8')->collation('utf8_general_ci');
                $table->tinyInteger('puede_crear')->default(0);
                $table->tinyInteger('puede_leer')->default(1);
                $table->tinyInteger('puede_editar')->default(0);
                $table->tinyInteger('puede_eliminar')->default(0);
                $table->json('permisos_extra')->nullable();
                $table->tinyInteger('estado')->default(1);
                $table->timestamps();
                
                $table->foreign('id_modulo')->references('id')->on('seg_modulos')->onDelete('cascade');
                $table->index('codigo');
                $table->index('id_modulo');
                $table->index('estado');
            });
        }

        // 3.5 Tabla pivote: perfil - permiso
        if (!Schema::hasTable('seg_perfil_permiso')) {
            Schema::create('seg_perfil_permiso', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('perfil_id');
                $table->unsignedBigInteger('permiso_id');
                $table->timestamps();
                
                $table->foreign('perfil_id')->references('id')->on('seg_perfiles')->onDelete('cascade');
                $table->foreign('permiso_id')->references('id')->on('seg_permisos')->onDelete('cascade');
                $table->unique(['perfil_id', 'permiso_id']);
            });
        }

        // 3.6 Tabla de roles
        if (!Schema::hasTable('seg_roles_custom')) {
            Schema::create('seg_roles_custom', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 50)->charset('utf8')->collation('utf8_general_ci');
                $table->string('codigo', 20)->unique()->charset('utf8')->collation('utf8_general_ci');
                $table->unsignedBigInteger('id_empresa')->nullable();
                $table->string('descripcion', 255)->nullable()->charset('utf8')->collation('utf8_general_ci');
                $table->tinyInteger('es_admin')->default(0);
                $table->tinyInteger('estado')->default(1);
                $table->timestamps();
                
                $table->foreign('id_empresa')->references('id')->on('ent_empresas')->onDelete('cascade');
                $table->index('codigo');
                $table->index('id_empresa');
                $table->index('estado');
            });
        }

        // 3.7 Tabla pivote: rol - perfil
        if (!Schema::hasTable('seg_rol_perfil')) {
            Schema::create('seg_rol_perfil', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_rol');
                $table->unsignedBigInteger('id_perfil');
                $table->timestamps();
                
                $table->foreign('id_rol')->references('id')->on('seg_roles_custom')->onDelete('cascade');
                $table->foreign('id_perfil')->references('id')->on('seg_perfiles')->onDelete('cascade');
                $table->unique(['id_rol', 'id_perfil']);
                $table->index('id_rol');
                $table->index('id_perfil');
            });
        }

        // 3.8 Tabla pivote: usuario - rol
        if (!Schema::hasTable('seg_usuario_rol')) {
            Schema::create('seg_usuario_rol', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('id_rol');
                $table->unsignedBigInteger('id_empresa')->nullable();
                $table->timestamps();
                
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('id_rol')->references('id')->on('seg_roles_custom')->onDelete('cascade');
                $table->foreign('id_empresa')->references('id')->on('ent_empresas')->onDelete('cascade');
                $table->unique(['user_id', 'id_rol', 'id_empresa']);
                $table->index('user_id');
                $table->index('id_rol');
                $table->index('id_empresa');
            });
        }

        // 3.9 Tabla pivote: empresa - usuario
        if (!Schema::hasTable('seg_empresa_user')) {
            Schema::create('seg_empresa_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('empresa_id')->constrained('ent_empresas')->onDelete('cascade');
                $table->unsignedBigInteger('id_sucursal')->nullable();
                $table->unsignedBigInteger('id_sede')->nullable();
                $table->boolean('recursivo')->default(false);
                $table->timestamps();
                
                $table->unique(['user_id', 'empresa_id']);
                $table->index('id_sucursal');
                $table->index('id_sede');
            });
        }

        // ============================================
        // 4. MATRIZ DE OBSOLESCENCIA
        // ============================================
        
        // 4.1 Tabla de grupos de parámetros
        if (!Schema::hasTable('matzobs_grupo_parametros')) {
            Schema::create('matzobs_grupo_parametros', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 100);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            });
        }

        // 4.2 Tabla de parámetros
        if (!Schema::hasTable('matzobs_parametros')) {
            Schema::create('matzobs_parametros', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_grupo');
                $table->string('nombre', 100);
                $table->string('tipo_dato', 20)->default('texto');
                $table->text('descripcion')->nullable();
                $table->integer('orden')->default(0);
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                
                $table->foreign('id_grupo')->references('id')->on('matzobs_grupo_parametros')->onDelete('cascade');
                $table->index('id_grupo');
            });
        }

        // 4.3 Tabla de activos cabecera
        if (!Schema::hasTable('matzobs_activos_c')) {
            Schema::create('matzobs_activos_c', function (Blueprint $table) {
                $table->id();
                $table->string('nombre_activo', 255);
                $table->string('tipo_activo', 100)->nullable();
                $table->string('marca', 100)->nullable();
                $table->string('modelo', 100)->nullable();
                $table->string('serial', 100)->nullable()->unique();
                $table->date('fecha_adquisicion')->nullable();
                $table->decimal('valor_adquisicion', 15, 2)->nullable();
                $table->integer('vida_util_anos')->nullable();
                $table->text('observaciones')->nullable();
                $table->enum('estado', ['activo', 'inactivo', 'en_mantenimiento', 'dado_de_baja'])->default('activo');
                
                // Campos de sincronización con GLPI
                $table->integer('glpi_computer_id')->nullable()->unique();
                $table->timestamp('ultima_sincronizacion')->nullable();
                $table->enum('estado_sincronizacion', ['pendiente', 'sincronizado', 'error'])->default('pendiente');
                $table->text('error_sincronizacion')->nullable();
                
                $table->timestamps();
                
                $table->index('nombre_activo');
                $table->index('tipo_activo');
                $table->index('serial');
                $table->index('estado');
                $table->index('glpi_computer_id');
                $table->index('estado_sincronizacion');
            });
        }

        // 4.4 Tabla de activos detalle
        if (!Schema::hasTable('matzobs_activos_d')) {
            Schema::create('matzobs_activos_d', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_activo_c');
                $table->unsignedBigInteger('id_parametro');
                $table->text('valor')->nullable();
                $table->decimal('valor_numerico', 15, 2)->nullable();
                $table->date('valor_fecha')->nullable();
                $table->timestamps();
                
                $table->foreign('id_activo_c')->references('id')->on('matzobs_activos_c')->onDelete('cascade');
                $table->foreign('id_parametro')->references('id')->on('matzobs_parametros')->onDelete('cascade');
                $table->unique(['id_activo_c', 'id_parametro']);
                $table->index('id_activo_c');
                $table->index('id_parametro');
            });
        }

        // 4.5 Tabla de agentes
        if (!Schema::hasTable('matzobs_agentes')) {
            Schema::create('matzobs_agentes', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 100);
                $table->string('tipo', 50);
                $table->text('descripcion')->nullable();
                $table->json('configuracion')->nullable();
                $table->boolean('activo')->default(true);
                $table->timestamp('ultima_ejecucion')->nullable();
                $table->timestamps();
                
                $table->index('tipo');
                $table->index('activo');
            });
        }

        // 4.6 Tabla de contexto de usuario
        if (!Schema::hasTable('usuario_contexto')) {
            Schema::create('usuario_contexto', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('empresa_id')->nullable();
                $table->unsignedBigInteger('sucursal_id')->nullable();
                $table->unsignedBigInteger('sede_id')->nullable();
                $table->timestamp('ultima_actualizacion')->useCurrent();
                $table->timestamps();
                
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('empresa_id')->references('id')->on('ent_empresas')->onDelete('set null');
                $table->foreign('sucursal_id')->references('id')->on('config_ubi_sucursales')->onDelete('set null');
                $table->foreign('sede_id')->references('id')->on('config_ubi_sede')->onDelete('set null');
                $table->unique('user_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Eliminar en orden inverso para respetar foreign keys
        Schema::dropIfExists('usuario_contexto');
        Schema::dropIfExists('matzobs_agentes');
        Schema::dropIfExists('matzobs_activos_d');
        Schema::dropIfExists('matzobs_activos_c');
        Schema::dropIfExists('matzobs_parametros');
        Schema::dropIfExists('matzobs_grupo_parametros');
        Schema::dropIfExists('seg_empresa_user');
        Schema::dropIfExists('seg_usuario_rol');
        Schema::dropIfExists('seg_rol_perfil');
        Schema::dropIfExists('seg_roles_custom');
        Schema::dropIfExists('seg_perfil_permiso');
        Schema::dropIfExists('seg_perfiles');
        Schema::dropIfExists('seg_modulo_empresa');
        Schema::dropIfExists('seg_permisos');
        Schema::dropIfExists('seg_modulos');
        Schema::dropIfExists('allowed_domains');
        Schema::dropIfExists('config_ubi_sede');
        Schema::dropIfExists('config_ubi_sucursales');
        Schema::dropIfExists('ent_empresas');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
