<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alcance parametrizable del autorizador de Fichas Técnicas.
 *
 * La tabla reemplaza la lectura directa de `users.id_sucursal` (un único valor)
 * por un registro por fila que permite tres niveles:
 *
 *   sucursal  → el autorizador solo ve las fichas de esa sucursal.
 *   regional  → ve todas las sucursales de la empresa (id_empresa completo).
 *   nacional  → ve todas las fichas sin filtro de empresa/sucursal.
 *
 * Un usuario puede tener varias filas (varias sucursales asignadas) siempre que
 * tipo_alcance sea 'sucursal'. Si tiene al menos una fila 'nacional' se ignoran
 * las demás y ve todo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fich_autorizador_sucursal', static function (Blueprint $table): void {
            $table->id();

            $table->unsignedBigInteger('id_user')
                ->comment('FK a users.id — el autorizador');

            $table->unsignedBigInteger('id_empresa')->nullable()
                ->comment('FK a seg_empresas.id — requerida cuando tipo_alcance ≠ nacional');

            $table->unsignedBigInteger('id_sucursal')->nullable()
                ->comment('FK a config_ubi_sucursales.id — requerida cuando tipo_alcance = sucursal');

            $table->enum('tipo_alcance', ['sucursal', 'regional', 'nacional'])
                ->default('sucursal')
                ->comment('sucursal → solo esa sede; regional → toda la empresa; nacional → sin filtro');

            $table->boolean('estado')->default(true)
                ->comment('false = deshabilitado sin borrar el registro');

            $table->timestamps();

            // Índices
            $table->index('id_user');
            $table->index(['id_user', 'estado']);
            $table->index(['id_empresa', 'tipo_alcance']);

            // Un autorizador no puede tener la misma sucursal asignada dos veces
            $table->unique(['id_user', 'id_sucursal', 'tipo_alcance'], 'ux_autorizador_suc_tipo');
        });

        // ── Seed inicial: poblar desde seg_empresa_user ────────────────────
        // Para cada user que tenga rol autorizador-fichas, insertar una fila
        // 'sucursal' usando su id_sucursal de la tabla users.
        DB::statement("
            INSERT INTO fich_autorizador_sucursal (id_user, id_empresa, id_sucursal, tipo_alcance, estado, created_at, updated_at)
            SELECT
                u.id,
                seu.empresa_id,
                u.id_sucursal,
                'sucursal',
                1,
                NOW(),
                NOW()
            FROM users u
            INNER JOIN seg_model_has_roles mhr ON mhr.model_id = u.id AND mhr.model_type = 'App\\\\Models\\\\User'
            INNER JOIN seg_roles r ON r.id = mhr.role_id AND r.name = 'autorizador-fichas'
            LEFT  JOIN seg_empresa_user seu ON seu.user_id = u.id AND seu.empresa_id IS NOT NULL
            WHERE u.id_sucursal IS NOT NULL
            ON DUPLICATE KEY UPDATE updated_at = NOW()
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('fich_autorizador_sucursal');
    }
};
