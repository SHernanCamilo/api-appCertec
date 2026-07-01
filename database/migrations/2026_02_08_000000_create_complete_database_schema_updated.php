<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migración consolidada del esquema completo de la base de datos
 * Actualizada el: 2026-02-08
 * Basada en: api-crm (4).sql
 * 
 * Esta migración crea todas las tablas del sistema exactamente como están en la BD actual
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ============================================
        // 1. TABLAS BASE
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
                $table->boolean('estado')->default(true)->comment('1=Activo, 0=Inactivo');
                $table->unsignedBigInteger('id_sucursal')->nullable();
                $table->unsignedBigInteger('id_sede')->nullable();
                $table->string('email')->unique();
                $table->string('microsoft_id')->nullable()->unique();
                $table->string('tenant_id')->nullable();
                $table->string('avatar')->nullable();
                $table->string('auth_type')->default('local');
                $table->string('cargo')->nullable();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->rememberToken();
                $table->timestamps();
                
                $table->index('microsoft_id');
                $table->index('tenant_id');
                $table->index('auth_type');
                $table->index('estado');
                $table->index('created_at');
                $table->index(['estado', 'created_at']);
            });
        }

        // 1.2 Tabla de tokens de reseteo
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

        // 1.5 Tabla de migraciones
        if (!Schema::hasTable('migrations')) {
            Schema::create('migrations', function (Blueprint $table) {
                $table->increments('id');
                $table->string('migration');
                $table->integer('batch');
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

        // 2.4 Tabla de dominios permitidos (ACTUALIZADA)
        if (!Schema::hasTable('allowed_domains')) {
            Schema::create('allowed_domains', function (Blueprint $table) {
                $table->id();
                $table->string('domain', 100)->unique();
                $table->string('tenant_id', 100)->nullable();
                $table->string('tenant_name', 100)->nullable();
                $table->unsignedBigInteger('id_empresa')->nullable();
                $table->tinyInteger('activo')->default(1);
                $table->text('descripcion')->nullable();
                $table->timestamps();
                
                $table->foreign('id_empresa')->references('id')->on('ent_empresas')->onDelete('set null');
                $table->index('domain');
                $table->index('tenant_id');
                $table->index('activo');
            });
        }

        // ============================================
        // 3. SISTEMA DE PERMISOS Y ROLES
        // ============================================
        
        // 3.1 Tabla de módulos
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

        // 3.2 Tabla de permisos
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

        // 3.3 Tabla de perfiles
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

        // 3.4 Tabla pivote: perfil - permiso
        if (!Schema::hasTable('seg_perfil_permiso')) {
            Schema::create('seg_perfil_permiso', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_perfil');
                $table->unsignedBigInteger('id_permiso');
                $table->timestamps();
                
                $table->foreign('id_perfil')->references('id')->on('seg_perfiles')->onDelete('cascade');
                $table->foreign('id_permiso')->references('id')->on('seg_permisos')->onDelete('cascade');
                $table->unique(['id_perfil', 'id_permiso']);
            });
        }

        // 3.5 Tabla de roles custom
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

        // 3.6 Tabla pivote: rol - perfil
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

        // 3.7 Tabla pivote: rol - user (NOMBRE CORRECTO: rol_id)
        if (!Schema::hasTable('seg_rol_user')) {
            Schema::create('seg_rol_user', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('rol_id'); // Nombre correcto según SQL
                $table->timestamps();
                
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('rol_id')->references('id')->on('seg_roles_custom')->onDelete('cascade');
                $table->unique(['user_id', 'rol_id']);
            });
        }

        // 3.8 Tabla pivote: empresa - usuario
        if (!Schema::hasTable('seg_empresa_user')) {
            Schema::create('seg_empresa_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
                $table->foreignId('empresa_id')->constrained('ent_empresas')->onDelete('cascade');
                $table->unsignedBigInteger('id_sucursal')->nullable();
                $table->unsignedBigInteger('id_sede')->nullable();
                $table->boolean('recursivo')->default(false);
                $table->timestamps();
                
                $table->unique(['user_id', 'empresa_id', 'id_sucursal', 'id_sede'], 'seg_empresa_user_user_empresa_sucursal_sede_unique');
            });
        }

        // 3.9 Tabla de contexto de usuario
        if (!Schema::hasTable('seg_usuario_contexto')) {
            Schema::create('seg_usuario_contexto', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('empresa_id')->nullable();
                $table->unsignedBigInteger('sucursal_id')->nullable();
                $table->unsignedBigInteger('sede_id')->nullable();
                $table->timestamp('ultima_actualizacion')->useCurrent();
                $table->timestamps();
                
                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('empresa_id')->references('id')->on('ent_empresas')->onDelete('set null');
            });
        }

        // 3.10 Tablas de Spatie (si se usan)
        if (!Schema::hasTable('seg_permissions')) {
            Schema::create('seg_permissions', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                
                $table->unique(['name', 'guard_name']);
            });
        }

        if (!Schema::hasTable('seg_roles')) {
            Schema::create('seg_roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                
                $table->unique(['name', 'guard_name']);
            });
        }

        if (!Schema::hasTable('seg_model_has_permissions')) {
            Schema::create('seg_model_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                
                $table->foreign('permission_id')->references('id')->on('seg_permissions')->onDelete('cascade');
                $table->primary(['permission_id', 'model_id', 'model_type']);
                $table->index(['model_id', 'model_type']);
            });
        }

        if (!Schema::hasTable('seg_model_has_roles')) {
            Schema::create('seg_model_has_roles', function (Blueprint $table) {
                $table->unsignedBigInteger('role_id');
                $table->string('model_type');
                $table->unsignedBigInteger('model_id');
                
                $table->foreign('role_id')->references('id')->on('seg_roles')->onDelete('cascade');
                $table->primary(['role_id', 'model_id', 'model_type']);
                $table->index(['model_id', 'model_type']);
            });
        }

        if (!Schema::hasTable('seg_role_has_permissions')) {
            Schema::create('seg_role_has_permissions', function (Blueprint $table) {
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id');
                
                $table->foreign('permission_id')->references('id')->on('seg_permissions')->onDelete('cascade');
                $table->foreign('role_id')->references('id')->on('seg_roles')->onDelete('cascade');
                $table->primary(['permission_id', 'role_id']);
            });
        }

        // ============================================
        // 4. MATRIZ DE OBSOLESCENCIA (ACTUALIZADA)
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

        // 4.2 Tabla de parámetros (ACTUALIZADA)
        if (!Schema::hasTable('matzobs_parametros')) {
            Schema::create('matzobs_parametros', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('id_grupo');
                $table->string('nombre', 150);
                $table->string('valor', 100)->nullable();
                $table->string('frecuencia', 100)->nullable();
                $table->decimal('rango_i', 10, 2)->nullable()->comment('Rango inicial');
                $table->decimal('rango_f', 10, 2)->nullable()->comment('Rango final');
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                
                $table->foreign('id_grupo')->references('id')->on('matzobs_grupo_parametros')->onDelete('cascade')->onUpdate('cascade');
                $table->index('id_grupo');
            });
        }

        // 4.3 Tabla de activos cabecera (ACTUALIZADA)
        if (!Schema::hasTable('matzobs_activos_c')) {
            Schema::create('matzobs_activos_c', function (Blueprint $table) {
                $table->id();
                $table->integer('id_activo_glpi')->unique()->comment('ID del activo en GLPI');
                $table->string('nombre_equipo', 255)->comment('Nombre del equipo');
                $table->unsignedBigInteger('id_empresa')->nullable();
                $table->unsignedBigInteger('id_sede')->nullable();
                $table->unsignedBigInteger('id_sucursal')->nullable()->comment('ID de la sucursal donde se encuentra el equipo');
                $table->string('agente', 100)->nullable()->comment('Tag del agente GLPI');
                $table->string('placa', 100)->nullable()->comment('Placa o tag de inventario');
                $table->string('serial', 100)->nullable()->comment('Número de serie del equipo');
                $table->string('ubicacion', 255)->nullable()->comment('Ubicación física del equipo');
                $table->decimal('puntaje', 5, 2)->default(0)->comment('Puntaje de obsolescencia (0-100)');
                $table->string('usuario_modificacion', 100)->nullable()->comment('Usuario que realizó la última modificación');
                $table->timestamp('date_u_sincronizacion')->nullable()->comment('Fecha de última sincronización con GLPI');
                $table->timestamps();
                $table->boolean('estado')->default(true)->comment('Estado del activo (1=activo, 0=eliminado)');
                $table->timestamp('fecha_sincronizacion')->nullable()->comment('Fecha de última sincronización con GLPI');
                $table->timestamp('fecha_eliminacion')->nullable()->comment('Fecha de eliminación del activo');
                
                $table->foreign('id_empresa')->references('id')->on('ent_empresas')->onDelete('cascade');
                $table->foreign('id_sede')->references('id')->on('config_ubi_sede')->onDelete('cascade');
                $table->foreign('id_sucursal')->references('id')->on('config_ubi_sucursales')->onDelete('set null');
                
                $table->index('id_activo_glpi');
                $table->index('nombre_equipo');
                $table->index('id_empresa');
                $table->index('id_sede');
                $table->index('id_sucursal');
                $table->index('agente');
                $table->index('puntaje');
                $table->index('date_u_sincronizacion');
            });
        }

        // 4.4 Tabla de activos detalle (ACTUALIZADA)
        if (!Schema::hasTable('matzobs_activos_d')) {
            Schema::create('matzobs_activos_d', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('activo_c_id')->comment('FK a matzobs_activos_c');
                $table->string('marca', 100)->nullable()->comment('Marca del equipo');
                $table->string('tipo', 100)->nullable()->comment('Tipo de equipo');
                $table->string('referencia', 255)->nullable()->comment('Referencia o modelo del equipo');
                $table->string('tipo_unidad', 100)->nullable()->comment('Tipo de unidad');
                $table->date('fecha_compra')->nullable()->comment('Fecha de compra del equipo');
                $table->string('modalidad', 100)->nullable()->comment('Modalidad de adquisición');
                $table->string('proveedor', 255)->nullable()->comment('Proveedor del equipo');
                $table->integer('edad')->nullable()->comment('Edad del equipo en años');
                $table->float('edad_v_util')->nullable()->comment('Vida útil esperada en años');
                $table->decimal('valoracion_edad', 5, 2)->nullable()->comment('Valoración de edad (0-100)');
                $table->decimal('tamano_ram', 8, 2)->nullable()->comment('Tamaño de RAM en GB');
                $table->string('generacion_ram', 50)->nullable()->comment('Generación de RAM (DDR3, DDR4, DDR5, etc.)');
                $table->decimal('valoracion_ram', 5, 2)->nullable()->comment('Valoración de RAM (0-100)');
                $table->string('procesador', 255)->nullable()->comment('Modelo del procesador');
                $table->integer('numero_procesador')->nullable()->comment('Número de núcleos del procesador');
                $table->decimal('valoracion_procesador', 5, 2)->nullable()->comment('Valoración del procesador (0-100)');
                $table->string('tipo_disco', 100)->nullable()->comment('Tipo de disco (HDD, SSD, etc.)');
                $table->decimal('tamano_disco', 10, 2)->nullable()->comment('Tamaño del disco en GB');
                $table->string('interfaz_conexion', 100)->nullable()->comment('Interfaz de conexión del disco');
                $table->decimal('valoracion_disco', 5, 2)->nullable()->comment('Valoración del disco (0-100)');
                $table->integer('incidencias_6_meses')->default(0)->comment('Número de incidencias en los últimos 6 meses');
                $table->timestamps();
                $table->unsignedBigInteger('id_activo_c')->nullable()->comment('FK a matzobs_activos_c');
                
                $table->foreign('activo_c_id')->references('id')->on('matzobs_activos_c')->onDelete('cascade');
                
                $table->index('activo_c_id');
                $table->index('marca');
                $table->index('tipo');
                $table->index('fecha_compra');
                $table->index('edad');
                $table->index('tamano_ram');
                $table->index('generacion_ram');
                $table->index('valoracion_edad');
                $table->index('valoracion_ram');
                $table->index('valoracion_procesador');
                $table->index('valoracion_disco');
            });
        }

        // 4.5 Tabla de agentes (ACTUALIZADA)
        if (!Schema::hasTable('matzobs_agentes')) {
            Schema::create('matzobs_agentes', function (Blueprint $table) {
                $table->id();
                $table->string('tag', 100)->comment('Tag del agente GLPI');
                $table->unsignedBigInteger('id_empresa')->comment('ID de la empresa');
                $table->unsignedBigInteger('id_sucursal')->comment('ID de la sucursal');
                $table->unsignedBigInteger('id_sede')->nullable()->comment('ID de la sede (opcional)');
                $table->timestamps();
                
                $table->index('tag');
                $table->index('id_empresa');
                $table->index('id_sucursal');
                $table->index('id_sede');
            });
        }

        // 4.6 Tabla de fórmulas (NUEVA)
        if (!Schema::hasTable('matzobs_formulas')) {
            Schema::create('matzobs_formulas', function (Blueprint $table) {
                $table->id();
                $table->string('nombre', 150)->comment('Nombre descriptivo de la fórmula');
                $table->string('columna_destino', 100)->comment('Columna en matzobs_activos_d donde se aplicará');
                $table->text('formula')->comment('Fórmula con variables entre llaves {variable}');
                $table->text('descripcion')->nullable()->comment('Descripción de qué calcula la fórmula');
                $table->boolean('activa')->default(true)->comment('Si la fórmula está activa');
                $table->timestamps();
                
                $table->index('columna_destino');
                $table->index('activa');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Eliminar en orden inverso
        Schema::dropIfExists('matzobs_formulas');
        Schema::dropIfExists('matzobs_agentes');
        Schema::dropIfExists('matzobs_activos_d');
        Schema::dropIfExists('matzobs_activos_c');
        Schema::dropIfExists('matzobs_parametros');
        Schema::dropIfExists('matzobs_grupo_parametros');
        Schema::dropIfExists('seg_role_has_permissions');
        Schema::dropIfExists('seg_model_has_roles');
        Schema::dropIfExists('seg_model_has_permissions');
        Schema::dropIfExists('seg_roles');
        Schema::dropIfExists('seg_permissions');
        Schema::dropIfExists('seg_usuario_contexto');
        Schema::dropIfExists('seg_empresa_user');
        Schema::dropIfExists('seg_rol_user');
        Schema::dropIfExists('seg_rol_perfil');
        Schema::dropIfExists('seg_roles_custom');
        Schema::dropIfExists('seg_perfil_permiso');
        Schema::dropIfExists('seg_perfiles');
        Schema::dropIfExists('seg_permisos');
        Schema::dropIfExists('seg_modulos');
        Schema::dropIfExists('allowed_domains');
        Schema::dropIfExists('config_ubi_sede');
        Schema::dropIfExists('config_ubi_sucursales');
        Schema::dropIfExists('ent_empresas');
        Schema::dropIfExists('migrations');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
