<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Workflow\WfModulo;
use App\Models\Workflow\WfDefinicion;
use App\Models\Workflow\WfPaso;
use App\Models\Workflow\WfRegla;
use App\Models\Workflow\WfAprobador;
use App\Models\Workflow\WfGrupo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seeder completo para el módulo de Anticipos.
 *
 * Crea:
 *   - Módulo "anticipos" en wf_modulos
 *   - Grupos Asistencial / Administrativo / Directivo con sus cargos
 *   - Flujos completos con paso de Tesorería según diagrama:
 *     1. Nacional Nivel 1-3 por Sucursal (3 pasos: Jefe → Financiero → Tesorería)
 *     2. Gerentes / VP Nivel 4+ (2 pasos: VP Financiero → Tesorería)
 *     3. Monto Alto > $5M (4 pasos: Jefe → Financiero → VP → Tesorería)
 *     4. Internacional (3 pasos: Jefe → Financiero → VP)
 *   - Aprobadores parametrizados en wf_aprobadores
 *   - Reglas de asignación con soporte para id_grupo
 *
 * Ejecutar: php artisan db:seed --class=AnticiposWorkflowSeeder
 */
class AnticiposWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $modulo = $this->crearModulo();
            $grupos = $this->crearGrupos();
            $this->crearFlujos($modulo, $grupos);

            $this->command->info('✅ Flujos de Anticipos configurados correctamente');
        });
    }

    private function crearModulo(): WfModulo
    {
        return WfModulo::updateOrCreate(
            ['codigo' => 'anticipos'],
            [
                'nombre' => 'Anticipos de Viaje',
                'descripcion' => 'Solicitudes de anticipo para viajes nacionales e internacionales',
                'estado' => true,
            ]
        );
    }

    private function crearGrupos(): array
    {
        // NOTA DE COMPATIBILIDAD DE ESQUEMA:
        // Existe drift entre producción (wf_grupos CON columna `codigo` UNIQUE) y el
        // estado de las migraciones tras fresh (SIN `codigo`, UNIQUE por id_empresa+nombre,
        // debido a la migración 2026_06_25_drop_codigo_from_wf_grupos_table).
        // Para que el seeder funcione en AMBOS entornos, detectamos la columna y usamos
        // la clave natural adecuada (codigo si existe, si no nombre).
        $grupoAsistencial = $this->upsertGrupo('ASISTENCIAL', 'Asistencial',
            'Personal asistencial: médicos, enfermeros, técnicos clínicos');

        $grupoAdministrativo = $this->upsertGrupo('ADMINISTRATIVO', 'Administrativo',
            'Personal administrativo: contadores, auxiliares, secretarias, analistas');

        $grupoDirectivo = $this->upsertGrupo('DIRECTIVO', 'Directivo',
            'Personal directivo: gerentes, vicepresidentes, directores');

        // Vincular cargos a grupos según nivel jerárquico
        // Los cargos con nivel_jerarquico = 1 (Estratégico) → Directivo
        // Los cargos con nivel_jerarquico = 2 (Táctico) → Administrativo
        // Los cargos con nivel_jerarquico = 3 (Operativo) → Asistencial / Administrativo
        $this->vincularCargosAGrupos($grupoAsistencial, $grupoAdministrativo, $grupoDirectivo);

        $this->command->info('  → Grupos Asistencial/Administrativo/Directivo creados');

        return [
            'asistencial' => $grupoAsistencial,
            'administrativo' => $grupoAdministrativo,
            'directivo' => $grupoDirectivo,
        ];
    }

    /**
     * Crea o actualiza un grupo de aprobación de forma compatible con ambos esquemas.
     *
     * Si la tabla tiene columna `codigo` (esquema de producción), la usa como clave
     * natural. Si no (esquema tras migración drop_codigo), usa `nombre`.
     */
    private function upsertGrupo(string $codigo, string $nombre, string $descripcion): WfGrupo
    {
        $tieneCodigo = \Illuminate\Support\Facades\Schema::hasColumn('wf_grupos', 'codigo');

        $clave = $tieneCodigo ? ['codigo' => $codigo] : ['nombre' => $nombre];

        $valores = [
            'nombre'      => $nombre,
            'descripcion' => $descripcion,
            'id_empresa'  => null, // aplica a todas las empresas
            'estado'      => true,
        ];

        if ($tieneCodigo) {
            $valores['codigo'] = $codigo;
        }

        return WfGrupo::updateOrCreate($clave, $valores);
    }

    private function vincularCargosAGrupos(WfGrupo $asistencial, WfGrupo $administrativo, WfGrupo $directivo): void
    {
        // Obtener todos los cargos con nivel jerárquico asignado
        $cargos = DB::table('config_cargo')
            ->whereNotNull('nivel_jerarquico')
            ->where('estado', true)
            ->get(['id_cargo', 'nombre_cargo', 'nivel_jerarquico']);

        $asistencialIds = [];
        $administrativoIds = [];
        $directivoIds = [];

        foreach ($cargos as $cargo) {
            // Nivel 1 (Estratégico) = Directivo
            if ($cargo->nivel_jerarquico == 1) {
                $directivoIds[] = $cargo->id_cargo;
            }
            // Nivel 2 (Táctico) = Administrativo
            elseif ($cargo->nivel_jerarquico == 2) {
                $administrativoIds[] = $cargo->id_cargo;
            }
            // Nivel 3 (Operativo) → clasificar por nombre del cargo
            else {
                $nombre = strtolower($cargo->nombre_cargo);
                $esAsistencial = str_contains($nombre, 'enferm')
                    || str_contains($nombre, 'medic')
                    || str_contains($nombre, 'terapeut')
                    || str_contains($nombre, 'auxiliar asistencial')
                    || str_contains($nombre, 'tecnologo')
                    || str_contains($nombre, 'instrumenta')
                    || str_contains($nombre, 'bacterio')
                    || str_contains($nombre, 'nutrici');

                if ($esAsistencial) {
                    $asistencialIds[] = $cargo->id_cargo;
                } else {
                    $administrativoIds[] = $cargo->id_cargo;
                }
            }
        }

        // Sync sin perder los existentes
        if (!empty($directivoIds)) {
            $directivo->cargos()->syncWithoutDetaching(
                collect($directivoIds)->mapWithKeys(fn($id) => [$id => []])->all()
            );
        }
        if (!empty($administrativoIds)) {
            $administrativo->cargos()->syncWithoutDetaching(
                collect($administrativoIds)->mapWithKeys(fn($id) => [$id => []])->all()
            );
        }
        if (!empty($asistencialIds)) {
            $asistencial->cargos()->syncWithoutDetaching(
                collect($asistencialIds)->mapWithKeys(fn($id) => [$id => []])->all()
            );
        }

        $this->command->info("    Cargos vinculados: Directivo={$directivo->cargos()->count()}, Administrativo={$administrativo->cargos()->count()}, Asistencial={$asistencial->cargos()->count()}");
    }

    private function crearFlujos(WfModulo $modulo, array $grupos): void
    {
        $prefijos = ['MA', 'NVA', 'EAL', 'TJA', 'FLA'];

        // =====================================================================
        // FLUJO 1: Nacional Nivel 1-3 (por sucursal)
        // Pasos: Jefe Inmediato → Dirección Financiera → Tesorería
        // =====================================================================
        $flujo1 = WfDefinicion::updateOrCreate(
            ['codigo' => 'FLUJO_ANTICIPO_NAL_SUCURSAL_N123'],
            [
                'nombre' => 'Anticipo Nacional Sucursales - Niveles 1-3',
                'descripcion' => 'Flujo para anticipos nacionales. 3 pasos: Jefe → Financiero → Tesorería',
                'id_modulo' => $modulo->id,
                'id_empresa' => null,
                'estado' => true,
            ]
        );

        $f1Paso1 = WfPaso::updateOrCreate(
            ['id_definicion' => $flujo1->id, 'orden' => 1],
            [
                'nombre_paso' => 'Aprobación Jefe Inmediato',
                'rol_aprobador' => 'jefe_inmediato',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => false,
                'descripcion_contexto' => 'Jefe inmediato revisa y aprueba la solicitud',
                'estado' => true,
            ]
        );

        $f1Paso2 = WfPaso::updateOrCreate(
            ['id_definicion' => $flujo1->id, 'orden' => 2],
            [
                'nombre_paso' => 'Aprobación Dirección Financiera',
                'rol_aprobador' => 'financiero',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => true,
                'descripcion_contexto' => 'Dirección Financiera valida presupuesto y autoriza monto',
                'estado' => true,
            ]
        );

        $f1Paso3 = WfPaso::updateOrCreate(
            ['id_definicion' => $flujo1->id, 'orden' => 3],
            [
                'nombre_paso' => 'Tesorería - Desembolso',
                'rol_aprobador' => 'tesoreria',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => false,
                'descripcion_contexto' => 'Tesorería ejecuta el desembolso del anticipo',
                'estado' => true,
            ]
        );

        // Aprobadores del flujo 1:
        // Paso 1: Responsable de UF (dinámico según la UF del solicitante)
        WfAprobador::updateOrCreate(
            ['id_paso' => $f1Paso1->id, 'tipo_aprobador' => 'RESPONSABLE_UF', 'es_suplente' => false],
            ['estado' => true, 'condiciones' => null]
        );

        // Paso 2: Financiero por permiso (permiso_codigo: apro-anticipo-financiero)
        WfAprobador::updateOrCreate(
            ['id_paso' => $f1Paso2->id, 'tipo_aprobador' => 'PERMISO', 'permiso_codigo' => 'apro-anticipo-financiero'],
            ['es_suplente' => false, 'alcance' => 'sucursal', 'estado' => true, 'condiciones' => null]
        );

        // Paso 3: Tesorería por permiso
        WfAprobador::updateOrCreate(
            ['id_paso' => $f1Paso3->id, 'tipo_aprobador' => 'PERMISO', 'permiso_codigo' => 'apro-anticipo-tesoreria'],
            ['es_suplente' => false, 'alcance' => 'empresa', 'estado' => true, 'condiciones' => null]
        );

        // Reglas: aplica para nivel 1-3, cualquier prefijo, cobertura nacional, monto <= 5M
        foreach ($prefijos as $index => $prefijo) {
            WfRegla::updateOrCreate(
                ['id_definicion' => $flujo1->id, 'prioridad' => 20 + $index],
                [
                    'condiciones' => [
                        'nivel_min' => 1,
                        'nivel_max' => 3,
                        'prefijo' => $prefijo,
                        'cobertura' => 'nacional',
                        'monto_max' => 5000000,
                    ],
                    'estado' => true,
                ]
            );
        }

        $this->command->info('  → Flujo 1 (Nacional Nivel 1-3): Jefe → Financiero → Tesorería');

        // =====================================================================
        // FLUJO 2: Gerentes / VP (Nivel 4+)
        // Pasos: VP Financiero → Tesorería
        // =====================================================================
        $flujo2 = WfDefinicion::updateOrCreate(
            ['codigo' => 'FLUJO_ANTICIPO_NAL_VP'],
            [
                'nombre' => 'Anticipo Nacional - Gerencia/VP',
                'descripcion' => 'Flujo para nivel jerárquico 4+. 2 pasos: VP Financiero → Tesorería',
                'id_modulo' => $modulo->id,
                'id_empresa' => null,
                'estado' => true,
            ]
        );

        $f2Paso1 = WfPaso::updateOrCreate(
            ['id_definicion' => $flujo2->id, 'orden' => 1],
            [
                'nombre_paso' => 'Aprobación Vicepresidente Financiero',
                'rol_aprobador' => 'vicepresidente',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => true,
                'descripcion_contexto' => 'VP Financiero aprueba anticipos de gerencia',
                'estado' => true,
            ]
        );

        $f2Paso2 = WfPaso::updateOrCreate(
            ['id_definicion' => $flujo2->id, 'orden' => 2],
            [
                'nombre_paso' => 'Tesorería - Desembolso',
                'rol_aprobador' => 'tesoreria',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => false,
                'descripcion_contexto' => 'Tesorería ejecuta el desembolso',
                'estado' => true,
            ]
        );

        // Aprobadores
        WfAprobador::updateOrCreate(
            ['id_paso' => $f2Paso1->id, 'tipo_aprobador' => 'PERMISO', 'permiso_codigo' => 'apro-anticipo-vp'],
            ['es_suplente' => false, 'alcance' => 'empresa', 'estado' => true, 'condiciones' => null]
        );

        WfAprobador::updateOrCreate(
            ['id_paso' => $f2Paso2->id, 'tipo_aprobador' => 'PERMISO', 'permiso_codigo' => 'apro-anticipo-tesoreria'],
            ['es_suplente' => false, 'alcance' => 'empresa', 'estado' => true, 'condiciones' => null]
        );

        // Regla: nivel >= 4, cobertura nacional
        WfRegla::updateOrCreate(
            ['id_definicion' => $flujo2->id, 'prioridad' => 5],
            [
                'condiciones' => [
                    'nivel_min' => 4,
                    'cobertura' => 'nacional',
                ],
                'estado' => true,
            ]
        );

        $this->command->info('  → Flujo 2 (VP/Gerencia): VP Financiero → Tesorería');

        // =====================================================================
        // FLUJO 3: Monto Alto (> $5M) - Nivel 1-3 con escalamiento a VP
        // Pasos: Jefe → Financiero → VP → Tesorería
        // =====================================================================
        $flujo3 = WfDefinicion::updateOrCreate(
            ['codigo' => 'FLUJO_ANTICIPO_MONTO_ALTO'],
            [
                'nombre' => 'Anticipo Nacional - Monto Alto (>$5M)',
                'descripcion' => 'Escala a VP cuando monto supera $5.000.000. 4 pasos.',
                'id_modulo' => $modulo->id,
                'id_empresa' => null,
                'estado' => true,
            ]
        );

        $f3Paso1 = WfPaso::updateOrCreate(
            ['id_definicion' => $flujo3->id, 'orden' => 1],
            [
                'nombre_paso' => 'Aprobación Jefe Inmediato',
                'rol_aprobador' => 'jefe_inmediato',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => false,
                'descripcion_contexto' => 'Jefe inmediato revisa solicitud de monto alto',
                'estado' => true,
            ]
        );

        $f3Paso2 = WfPaso::updateOrCreate(
            ['id_definicion' => $flujo3->id, 'orden' => 2],
            [
                'nombre_paso' => 'Aprobación Dirección Financiera',
                'rol_aprobador' => 'financiero',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => true,
                'descripcion_contexto' => 'Dir. Financiera valida monto alto',
                'estado' => true,
            ]
        );

        $f3Paso3 = WfPaso::updateOrCreate(
            ['id_definicion' => $flujo3->id, 'orden' => 3],
            [
                'nombre_paso' => 'Aprobación Vicepresidente Financiero',
                'rol_aprobador' => 'vicepresidente',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => true,
                'descripcion_contexto' => 'VP Financiero aprueba escalamiento por monto',
                'estado' => true,
            ]
        );

        $f3Paso4 = WfPaso::updateOrCreate(
            ['id_definicion' => $flujo3->id, 'orden' => 4],
            [
                'nombre_paso' => 'Tesorería - Desembolso',
                'rol_aprobador' => 'tesoreria',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => false,
                'descripcion_contexto' => 'Tesorería ejecuta desembolso de monto alto',
                'estado' => true,
            ]
        );

        // Aprobadores
        WfAprobador::updateOrCreate(
            ['id_paso' => $f3Paso1->id, 'tipo_aprobador' => 'RESPONSABLE_UF', 'es_suplente' => false],
            ['estado' => true, 'condiciones' => null]
        );
        WfAprobador::updateOrCreate(
            ['id_paso' => $f3Paso2->id, 'tipo_aprobador' => 'PERMISO', 'permiso_codigo' => 'apro-anticipo-financiero'],
            ['es_suplente' => false, 'alcance' => 'sucursal', 'estado' => true, 'condiciones' => null]
        );
        WfAprobador::updateOrCreate(
            ['id_paso' => $f3Paso3->id, 'tipo_aprobador' => 'PERMISO', 'permiso_codigo' => 'apro-anticipo-vp'],
            ['es_suplente' => false, 'alcance' => 'empresa', 'estado' => true, 'condiciones' => null]
        );
        WfAprobador::updateOrCreate(
            ['id_paso' => $f3Paso4->id, 'tipo_aprobador' => 'PERMISO', 'permiso_codigo' => 'apro-anticipo-tesoreria'],
            ['es_suplente' => false, 'alcance' => 'empresa', 'estado' => true, 'condiciones' => null]
        );

        // Regla: nivel 1-3, monto > 5M, nacional
        WfRegla::updateOrCreate(
            ['id_definicion' => $flujo3->id, 'prioridad' => 8],
            [
                'condiciones' => [
                    'nivel_min' => 1,
                    'nivel_max' => 3,
                    'monto_min' => 5000001,
                    'cobertura' => 'nacional',
                ],
                'estado' => true,
            ]
        );

        $this->command->info('  → Flujo 3 (Monto Alto >$5M): Jefe → Financiero → VP → Tesorería');

        // =====================================================================
        // FLUJO 4: Internacional (todos los niveles)
        // Pasos: Jefe → Financiero → VP
        // =====================================================================
        $flujo4 = WfDefinicion::updateOrCreate(
            ['codigo' => 'FLUJO_ANTICIPO_INTERNACIONAL'],
            [
                'nombre' => 'Anticipo Internacional',
                'descripcion' => 'Flujo para viajes internacionales. Siempre requiere VP.',
                'id_modulo' => $modulo->id,
                'id_empresa' => null,
                'estado' => true,
            ]
        );

        $f4Paso1 = WfPaso::updateOrCreate(
            ['id_definicion' => $flujo4->id, 'orden' => 1],
            [
                'nombre_paso' => 'Aprobación Jefe Inmediato',
                'rol_aprobador' => 'jefe_inmediato',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => false,
                'descripcion_contexto' => 'Jefe inmediato aprueba viaje internacional',
                'estado' => true,
            ]
        );

        $f4Paso2 = WfPaso::updateOrCreate(
            ['id_definicion' => $flujo4->id, 'orden' => 2],
            [
                'nombre_paso' => 'Aprobación Dirección Financiera',
                'rol_aprobador' => 'financiero',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => true,
                'descripcion_contexto' => 'Dir. Financiera valida presupuesto internacional',
                'estado' => true,
            ]
        );

        $f4Paso3 = WfPaso::updateOrCreate(
            ['id_definicion' => $flujo4->id, 'orden' => 3],
            [
                'nombre_paso' => 'Aprobación Vicepresidencia',
                'rol_aprobador' => 'vicepresidente',
                'es_opcional' => false,
                'permite_rechazo' => true,
                'requiere_monto' => true,
                'descripcion_contexto' => 'VP aprueba viajes internacionales',
                'estado' => true,
            ]
        );

        // Aprobadores
        WfAprobador::updateOrCreate(
            ['id_paso' => $f4Paso1->id, 'tipo_aprobador' => 'RESPONSABLE_UF', 'es_suplente' => false],
            ['estado' => true, 'condiciones' => null]
        );
        WfAprobador::updateOrCreate(
            ['id_paso' => $f4Paso2->id, 'tipo_aprobador' => 'PERMISO', 'permiso_codigo' => 'apro-anticipo-financiero'],
            ['es_suplente' => false, 'alcance' => 'empresa', 'estado' => true, 'condiciones' => null]
        );
        WfAprobador::updateOrCreate(
            ['id_paso' => $f4Paso3->id, 'tipo_aprobador' => 'PERMISO', 'permiso_codigo' => 'apro-anticipo-vp'],
            ['es_suplente' => false, 'alcance' => 'empresa', 'estado' => true, 'condiciones' => null]
        );

        // Regla: cobertura internacional (aplica a todos los niveles)
        WfRegla::updateOrCreate(
            ['id_definicion' => $flujo4->id, 'prioridad' => 3],
            [
                'condiciones' => [
                    'cobertura' => 'internacional',
                ],
                'estado' => true,
            ]
        );

        $this->command->info('  → Flujo 4 (Internacional): Jefe → Financiero → VP');

        // Eliminar el flujo viejo de MA que solo tenía 2 pasos
        $flujoViejo = WfDefinicion::where('codigo', 'FLUJO_ANTICIPO_NAL_MA_N123')->first();
        if ($flujoViejo) {
            $flujoViejo->update(['estado' => false]);
            $this->command->info('  → Flujo antiguo FLUJO_ANTICIPO_NAL_MA_N123 desactivado');
        }
    }
}
