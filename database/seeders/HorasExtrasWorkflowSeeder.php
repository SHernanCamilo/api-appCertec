<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HorasExtrasWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            // 1. Crear módulo de Horas Extras
            $moduloId = DB::table('wf_modulos')->insertGetId([
                'codigo' => 'horas_extras',
                'nombre' => 'Horas Extras',
                'descripcion' => 'Solicitudes de horas extras',
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 2. Crear grupos de aprobación
            $grupoDirectivoId = DB::table('wf_grupos')->insertGetId([
                'codigo' => 'directivo',
                'nombre' => 'Directivo',
                'descripcion' => 'Cargos directivos',
                'id_empresa' => null,
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $grupoAdministrativoId = DB::table('wf_grupos')->insertGetId([
                'codigo' => 'administrativo',
                'nombre' => 'Administrativo',
                'descripcion' => 'Cargos administrativos',
                'id_empresa' => null,
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 3. Crear definición del flujo de Horas Extras
            $flujoId = DB::table('wf_definiciones')->insertGetId([
                'id_modulo' => $moduloId,
                'codigo' => 'flujo_horas_extras',
                'nombre' => 'Flujo de Aprobación de Horas Extras',
                'descripcion' => 'Solicita → Autoriza (Jefe) → Aprueba (Gerencia) → Nomina',
                'id_empresa' => null,
                'estado' => true,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 4. Crear pasos del flujo
            $paso1Id = DB::table('wf_pasos')->insertGetId([
                'id_definicion' => $flujoId,
                'orden' => 1,
                'nombre_paso' => 'Solicita',
                'rol_aprobador' => 'solicitante',
                'es_opcional' => false,
                'permite_rechazo' => false,
                'requiere_monto' => false,
                'reglas' => null,
                'descripcion_contexto' => 'El empleado crea la solicitud',
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $paso2Id = DB::table('wf_pasos')->insertGetId([
                'id_definicion' => $flujoId,
                'orden' => 2,
                'nombre_paso' => 'Autoriza Jefe',
                'rol_aprobador' => 'jefe_inmediato',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => false,
                'reglas' => null,
                'descripcion_contexto' => 'El jefe inmediato autoriza o rechaza la solicitud',
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $paso3Id = DB::table('wf_pasos')->insertGetId([
                'id_definicion' => $flujoId,
                'orden' => 3,
                'nombre_paso' => 'Aprueba Gerencia',
                'rol_aprobador' => 'gerente',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => true,
                'reglas' => json_encode(['horas_min' => 8]),
                'descripcion_contexto' => 'La gerencia aprueba solicitudes de más de 8 horas',
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $paso4Id = DB::table('wf_pasos')->insertGetId([
                'id_definicion' => $flujoId,
                'orden' => 4,
                'nombre_paso' => 'Nomina',
                'rol_aprobador' => 'nomina',
                'es_opcional' => false,
                'permite_rechazo' => false,
                'requiere_monto' => true,
                'reglas' => null,
                'descripcion_contexto' => 'Nómina procesa la solicitud',
                'estado' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 5. Crear reglas para el flujo (opcional)
            DB::table('wf_reglas')->insert([
                [
                    'id_definicion' => $flujoId,
                    'prioridad' => 1,
                    'condiciones' => json_encode(['horas_min' => 0]),
                    'estado' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            $this->command->info('✅ Flujo de Horas Extras configurado correctamente.');
        });
    }
}
