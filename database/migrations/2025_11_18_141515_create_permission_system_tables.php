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
        // 1. Tabla de Módulos (con jerarquía padre-hijo)
        Schema::create('seg_modulos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_modulo_padre')->nullable(); // NULL = módulo raíz
            $table->string('nombre', 50)->charset('utf8')->collation('utf8_general_ci');
            $table->string('codigo', 20)->unique()->charset('utf8')->collation('utf8_general_ci');
            $table->string('descripcion', 255)->nullable()->charset('utf8')->collation('utf8_general_ci');
            $table->string('icono', 50)->nullable(); // Para el icono en el menú
            $table->string('ruta', 100)->nullable(); // Ruta del módulo
            $table->integer('orden')->default(0); // Orden en el menú
            $table->integer('nivel')->default(0); // 0=raíz, 1=hijo, 2=nieto, etc.
            $table->tinyInteger('estado')->default(1); // 1=activo, 0=inactivo
            $table->timestamps();
            
            $table->foreign('id_modulo_padre')->references('id')->on('seg_modulos')->onDelete('cascade');
            
            $table->index('codigo');
            $table->index('estado');
            $table->index('id_modulo_padre');
            $table->index('nivel');
        });

        // 2. Tabla de Permisos por Módulo y Empresa
        // Solo se registran los módulos padre asignados a la empresa
        // Los hijos se heredan automáticamente
        Schema::create('seg_modulo_empresa', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_modulo');
            $table->unsignedBigInteger('id_empresa');
            $table->tinyInteger('activo')->default(1); // 1=empresa tiene acceso, 0=no tiene acceso
            $table->tinyInteger('hereda_hijos')->default(1); // 1=incluye submódulos, 0=solo este módulo
            $table->timestamps();
            
            $table->foreign('id_modulo')->references('id')->on('seg_modulos')->onDelete('cascade');
            $table->foreign('id_empresa')->references('id')->on('ent_empresas')->onDelete('cascade');
            
            $table->unique(['id_modulo', 'id_empresa']);
            $table->index('id_modulo');
            $table->index('id_empresa');
            $table->index('activo');
        });

        // 3. Tabla de Perfiles (Permisos específicos dentro de un módulo)
        Schema::create('seg_perfiles', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 50)->charset('utf8')->collation('utf8_general_ci');
            $table->string('codigo', 20)->unique()->charset('utf8')->collation('utf8_general_ci');
            $table->unsignedBigInteger('id_modulo');
            $table->string('descripcion', 255)->nullable()->charset('utf8')->collation('utf8_general_ci');
            
            // Permisos CRUD
            $table->tinyInteger('puede_crear')->default(0); // 1=sí, 0=no
            $table->tinyInteger('puede_leer')->default(1);  // 1=sí, 0=no
            $table->tinyInteger('puede_editar')->default(0); // 1=sí, 0=no
            $table->tinyInteger('puede_eliminar')->default(0); // 1=sí, 0=no
            
            // Permisos adicionales personalizables
            $table->json('permisos_extra')->nullable(); // Para permisos específicos del módulo
            
            $table->tinyInteger('estado')->default(1);
            $table->timestamps();
            
            $table->foreign('id_modulo')->references('id')->on('seg_modulos')->onDelete('cascade');
            
            $table->index('codigo');
            $table->index('id_modulo');
            $table->index('estado');
        });

        // 4. Tabla de Roles (Agrupan múltiples perfiles)
        Schema::create('seg_roles_custom', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 50)->charset('utf8')->collation('utf8_general_ci');
            $table->string('codigo', 20)->unique()->charset('utf8')->collation('utf8_general_ci');
            $table->unsignedBigInteger('id_empresa')->nullable(); // NULL = rol global, sino específico de empresa
            $table->string('descripcion', 255)->nullable()->charset('utf8')->collation('utf8_general_ci');
            $table->tinyInteger('es_admin')->default(0); // 1=admin total, 0=rol normal
            $table->tinyInteger('estado')->default(1);
            $table->timestamps();
            
            $table->foreign('id_empresa')->references('id')->on('ent_empresas')->onDelete('cascade');
            
            $table->index('codigo');
            $table->index('id_empresa');
            $table->index('estado');
        });

        // 5. Tabla Pivote: Roles - Perfiles (Un rol puede tener múltiples perfiles)
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

        // 6. Tabla Pivote: Usuarios - Roles (Un usuario puede tener múltiples roles)
        Schema::create('seg_usuario_rol', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('id_rol');
            $table->unsignedBigInteger('id_empresa')->nullable(); // Rol específico para una empresa
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seg_usuario_rol');
        Schema::dropIfExists('seg_rol_perfil');
        Schema::dropIfExists('seg_roles_custom');
        Schema::dropIfExists('seg_perfiles');
        Schema::dropIfExists('seg_modulo_empresa');
        Schema::dropIfExists('seg_modulos');
    }
};
