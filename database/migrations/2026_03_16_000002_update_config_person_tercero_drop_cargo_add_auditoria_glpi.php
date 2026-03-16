<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('config_person_tercero')) {
            return;
        }

        if (!Schema::hasColumn('config_person_tercero', 'caso_glpi')) {
            Schema::table('config_person_tercero', function (Blueprint $table) {
                $table->string('caso_glpi', 100)->nullable();
            });
        }

        if (!Schema::hasColumn('config_person_tercero', 'usuario_crea_id')) {
            Schema::table('config_person_tercero', function (Blueprint $table) {
                $table->unsignedBigInteger('usuario_crea_id')->nullable();
                $table->foreign('usuario_crea_id')->references('id')->on('users')->onDelete('set null');
                $table->index('usuario_crea_id');
            });
        }

        if (!Schema::hasColumn('config_person_tercero', 'usuario_actualiza_id')) {
            Schema::table('config_person_tercero', function (Blueprint $table) {
                $table->unsignedBigInteger('usuario_actualiza_id')->nullable();
                $table->foreign('usuario_actualiza_id')->references('id')->on('users')->onDelete('set null');
                $table->index('usuario_actualiza_id');
            });
        }

        if (Schema::hasColumn('config_person_tercero', 'cargo') && Schema::hasColumn('config_person_tercero', 'id_cargo') && Schema::hasTable('config_cargo')) {
            $cargos = DB::table('config_person_tercero')
                ->select('cargo')
                ->whereNotNull('cargo')
                ->where('cargo', '!=', '')
                ->distinct()
                ->pluck('cargo')
                ->toArray();

            foreach ($cargos as $cargoNombre) {
                $existing = DB::table('config_cargo')->where('nombre_cargo', $cargoNombre)->first();
                if (!$existing) {
                    $idCargo = DB::table('config_cargo')->insertGetId([
                        'nombre_cargo' => $cargoNombre,
                        'descripcion' => null,
                        'estado' => 1,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                } else {
                    $idCargo = $existing->id_cargo;
                }

                DB::table('config_person_tercero')
                    ->whereNull('id_cargo')
                    ->where('cargo', $cargoNombre)
                    ->update(['id_cargo' => $idCargo]);
            }
        }

        if (Schema::hasColumn('config_person_tercero', 'cargo')) {
            Schema::table('config_person_tercero', function (Blueprint $table) {
                $table->dropColumn('cargo');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('config_person_tercero')) {
            return;
        }

        if (!Schema::hasColumn('config_person_tercero', 'cargo')) {
            Schema::table('config_person_tercero', function (Blueprint $table) {
                $table->string('cargo', 100)->nullable();
            });
        }

        if (Schema::hasColumn('config_person_tercero', 'usuario_crea_id')) {
            Schema::table('config_person_tercero', function (Blueprint $table) {
                $table->dropForeign(['usuario_crea_id']);
                $table->dropIndex(['usuario_crea_id']);
                $table->dropColumn('usuario_crea_id');
            });
        }

        if (Schema::hasColumn('config_person_tercero', 'usuario_actualiza_id')) {
            Schema::table('config_person_tercero', function (Blueprint $table) {
                $table->dropForeign(['usuario_actualiza_id']);
                $table->dropIndex(['usuario_actualiza_id']);
                $table->dropColumn('usuario_actualiza_id');
            });
        }

        if (Schema::hasColumn('config_person_tercero', 'caso_glpi')) {
            Schema::table('config_person_tercero', function (Blueprint $table) {
                $table->dropColumn('caso_glpi');
            });
        }
    }
};
