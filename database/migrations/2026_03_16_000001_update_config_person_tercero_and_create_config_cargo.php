<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('config_cargo')) {
            Schema::create('config_cargo', function (Blueprint $table) {
                $table->id('id_cargo');
                $table->string('nombre_cargo', 150);
                $table->string('descripcion', 255)->nullable();
                $table->boolean('estado')->default(true);
                $table->timestamps();
                $table->unique('nombre_cargo');
            });
        }

        if (Schema::hasTable('config_person_tercero')) {
            if (!Schema::hasColumn('config_person_tercero', 'email')) {
                Schema::table('config_person_tercero', function (Blueprint $table) {
                    $table->string('email', 255)->nullable()->unique()->after('numero_identificacion');
                });
            }

            if (!Schema::hasColumn('config_person_tercero', 'id_cargo')) {
                Schema::table('config_person_tercero', function (Blueprint $table) {
                    $table->unsignedBigInteger('id_cargo')->nullable()->after('id_empresa');
                    $table->foreign('id_cargo')->references('id_cargo')->on('config_cargo')->onDelete('set null');
                    $table->index('id_cargo');
                });
            }

            if (Schema::hasColumn('config_person_tercero', 'id_sucursal')) {
                Schema::table('config_person_tercero', function (Blueprint $table) {
                    $table->dropForeign(['id_sucursal']);
                    $table->dropIndex(['id_sucursal']);
                    $table->dropColumn('id_sucursal');
                });
            }

            if (Schema::hasColumn('config_person_tercero', 'id_sede')) {
                Schema::table('config_person_tercero', function (Blueprint $table) {
                    $table->dropForeign(['id_sede']);
                    $table->dropIndex(['id_sede']);
                    $table->dropColumn('id_sede');
                });
            }

            if (Schema::hasColumn('config_person_tercero', 'tipo_identificacion')) {
                DB::statement("ALTER TABLE config_person_tercero MODIFY tipo_identificacion ENUM('CC','CE','NIT','TI','PP','PEP') DEFAULT 'CC'");
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('config_person_tercero')) {
            if (Schema::hasColumn('config_person_tercero', 'id_cargo')) {
                Schema::table('config_person_tercero', function (Blueprint $table) {
                    $table->dropForeign(['id_cargo']);
                    $table->dropIndex(['id_cargo']);
                    $table->dropColumn('id_cargo');
                });
            }

            if (Schema::hasColumn('config_person_tercero', 'email')) {
                Schema::table('config_person_tercero', function (Blueprint $table) {
                    $table->dropUnique(['email']);
                    $table->dropColumn('email');
                });
            }

            if (!Schema::hasColumn('config_person_tercero', 'id_sucursal')) {
                Schema::table('config_person_tercero', function (Blueprint $table) {
                    $table->unsignedBigInteger('id_sucursal')->nullable();
                    $table->foreign('id_sucursal')->references('id')->on('config_ubi_sucursales')->onDelete('cascade');
                    $table->index('id_sucursal');
                });
            }

            if (!Schema::hasColumn('config_person_tercero', 'id_sede')) {
                Schema::table('config_person_tercero', function (Blueprint $table) {
                    $table->unsignedBigInteger('id_sede')->nullable();
                    $table->foreign('id_sede')->references('id')->on('config_ubi_sede')->onDelete('cascade');
                    $table->index('id_sede');
                });
            }
        }

        Schema::dropIfExists('config_cargo');
    }
};
