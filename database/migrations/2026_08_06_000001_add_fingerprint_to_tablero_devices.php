<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega device_id y fingerprint al dispositivo para reconexión automática.
 *
 * device_id: UUID generado por la TV la primera vez que carga la app.
 *   Se guarda en 3 capas (localStorage + IndexedDB + cookie de 10 años).
 *   Permite reconectar la TV sin código incluso si una capa se pierde.
 *   Es ÚNICO por TV (UUID aleatorio), incluso entre TV del mismo modelo
 *   y la misma red.
 *
 * fingerprint: hash del hardware (para compatibilidad/auditoría).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tablero_devices') && !Schema::hasColumn('tablero_devices', 'device_id')) {
            Schema::table('tablero_devices', function (Blueprint $table): void {
                $table->string('device_id', 60)
                    ->nullable()
                    ->after('device_secret')
                    ->comment('UUID de la TV (generado en el browser, guardado en IndexedDB+cookie)');

                $table->index(['device_id', 'active'], 'idx_td_device_id');
            });
        }

        if (Schema::hasTable('tablero_devices') && !Schema::hasColumn('tablero_devices', 'fingerprint')) {
            Schema::table('tablero_devices', function (Blueprint $table): void {
                $table->string('fingerprint', 50)
                    ->nullable()
                    ->after('device_id')
                    ->comment('Hash del hardware de la TV (para auditoría)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tablero_devices', 'device_id')) {
            Schema::table('tablero_devices', function (Blueprint $table): void {
                $table->dropIndex('idx_td_device_id');
                $table->dropColumn('device_id');
            });
        }

        if (Schema::hasColumn('tablero_devices', 'fingerprint')) {
            Schema::table('tablero_devices', function (Blueprint $table): void {
                $table->dropColumn('fingerprint');
            });
        }
    }
};
