<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Alinea el enum `anti_solicitudes.estado` con los estados que realmente genera
 * el motor de flujos (WorkflowExecutor produce 'pendiente_' . rol y 'rechazado_' . rol)
 * y con los que consume el frontend.
 *
 * Roles del flujo de anticipos: jefe_inmediato, financiero, tesoreria, vicepresidente.
 *
 * PROBLEMA QUE RESUELVE:
 *   El enum original solo incluía 'pendiente_jefe'/'rechazado_jefe', pero el flujo
 *   genera 'pendiente_jefe_inmediato', 'pendiente_tesoreria' y 'pendiente_vicepresidente'.
 *   Con strict mode desactivado en MySQL, un valor fuera del enum se guarda como cadena
 *   vacía (''), corrompiendo silenciosamente el estado de la solicitud.
 *
 * Se conservan los valores antiguos ('pendiente_jefe'/'rechazado_jefe') por
 * compatibilidad con datos existentes.
 */
return new class extends Migration
{
    private array $valores = [
        'borrador',
        // Jefe inmediato (nuevo + legacy)
        'pendiente_jefe_inmediato',
        'rechazado_jefe_inmediato',
        'pendiente_jefe',
        'rechazado_jefe',
        // Financiero
        'pendiente_financiero',
        'rechazado_financiero',
        // Tesorería
        'pendiente_tesoreria',
        'rechazado_tesoreria',
        // Vicepresidente
        'pendiente_vicepresidente',
        'rechazado_vicepresidente',
        // Post-autorización / ciclo de vida
        'autorizado',
        'en_viaje',
        'pendiente_legalizacion',
        'legalizado',
        'pendiente_reintegro',
        'reintegrado',
        'pendiente_excedente',
        'aprobado_excedente',
        'rechazado_excedente',
        'cerrado',
    ];

    public function up(): void
    {
        $lista = collect($this->valores)->map(fn ($v) => "'{$v}'")->implode(',');
        DB::statement("ALTER TABLE `anti_solicitudes` MODIFY `estado` ENUM({$lista}) NOT NULL DEFAULT 'borrador'");
    }

    public function down(): void
    {
        // Volver al conjunto original de estados.
        $original = [
            'borrador', 'pendiente_jefe', 'rechazado_jefe',
            'pendiente_financiero', 'rechazado_financiero',
            'autorizado', 'en_viaje', 'pendiente_legalizacion', 'legalizado',
            'pendiente_reintegro', 'reintegrado',
            'pendiente_excedente', 'aprobado_excedente', 'rechazado_excedente',
            'cerrado',
        ];
        $lista = collect($original)->map(fn ($v) => "'{$v}'")->implode(',');
        DB::statement("ALTER TABLE `anti_solicitudes` MODIFY `estado` ENUM({$lista}) NOT NULL DEFAULT 'borrador'");
    }
};
