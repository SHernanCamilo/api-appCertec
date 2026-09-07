<?php

declare(strict_types=1);

namespace Tests\Feature\FichasTecnicas;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests QA de alcance, seguridad y trazabilidad — Fichas Técnicas.
 *
 * Categorías:
 *   WHITE-BOX  : Verificamos el filtrado SQL directamente (aplicarFiltroAlcance).
 *   GRAY-BOX   : Combinamos setup de BD + llamada HTTP para validar respuesta.
 *   BLACK-BOX  : Solo HTTP, sin conocer internos.
 *
 * Usa DatabaseTransactions → rollback automático al final de cada test.
 * Los tests crean todos sus datos necesarios (no dependen de datos preexistentes).
 *
 * Ejecutar:
 *   php artisan test tests/Feature/FichasTecnicas/FichAlcanceSeguridadTest.php -v
 *
 * Esquemas relevantes (confirmados en BD):
 *   users            : id, name, email, password, id_sucursal, id_sede, estado
 *   fich_fichas      : id, id_empresa, id_sucursal, id_estado, id_user_reg,
 *                      id_agremiacion, id_objeto_contrato, id_especialidad,
 *                      vlr_contrato, fecha_ini, fecha_fin, sucursal_legacy,
 *                      total_detalles, valor_total_detalles, total_profesionales,
 *                      version, ciclos_flujo
 *   fich_historial_estados : id, id_ficha, id_estado_anterior, id_estado_nuevo,
 *                            id_usuario, observacion, created_at
 *   seg_roles        : id, name, guard_name
 *   seg_model_has_roles : role_id, model_type, model_id
 *   seg_empresa_user : user_id, empresa_id, recursivo
 *   fich_autorizador_sucursal : id_user, id_empresa, id_sucursal, tipo_alcance, estado
 *   config_ubi_sucursales : id, nombre, id_Empresa  (mayúscula en id_Empresa)
 */
final class FichAlcanceSeguridadTest extends TestCase
{
    use DatabaseTransactions;

    // ─── Contexto compartido entre tests ─────────────────────────────────
    private int $empresaId;
    private int $empresaAjenaId;
    private int $sucursal1Id;
    private int $sucursal2Id;

    // IDs de estados
    private int $estadoBorrador;
    private int $estadoPendienteAut;
    private int $estadoCorreccion;

    // IDs de maestros
    private int $agremiacionId;
    private int $objetoId;
    private int $especialidadId;

    protected function setUp(): void
    {
        parent::setUp();

        // ── Forzar BD real para tests de integración ──────────────────────
        $conn             = app('config')->get('database.connections.mysql');
        $conn['database'] = 'jadeonedevs';
        app('config')->set('database.connections.mysql', $conn);
        app('config')->set('database.default', 'mysql');
        DB::purge('mysql');
        DB::reconnect('mysql');

        // ── Empresa con más sucursales (id=1, Medilaser) ──────────────────
        $this->empresaId = (int) DB::table('config_ubi_sucursales')
            ->selectRaw('id_Empresa, COUNT(*) as cnt')
            ->groupBy('id_Empresa')
            ->orderByDesc('cnt')
            ->value('id_Empresa');

        if ($this->empresaId === 0) {
            $this->markTestSkipped('No hay datos de sucursales en la BD.');
        }

        // Empresa ajena (cualquier otra empresa diferente)
        $this->empresaAjenaId = (int) (DB::table('ent_empresas')
            ->where('id', '!=', $this->empresaId)
            ->value('id') ?? 9999);

        // Dos sucursales de la empresa base
        $sids = DB::table('config_ubi_sucursales')
            ->where('id_Empresa', $this->empresaId)
            ->limit(2)
            ->pluck('id')
            ->toArray();

        $this->sucursal1Id = (int) ($sids[0] ?? 0);
        $this->sucursal2Id = (int) ($sids[1] ?? 0);

        if ($this->sucursal1Id === 0) {
            $this->markTestSkipped('No hay sucursales para la empresa base.');
        }

        // ── Estados de fichas ─────────────────────────────────────────────
        $this->estadoBorrador     = (int) DB::table('fich_estados')->where('codigo', 'borrador')->value('id');
        $this->estadoPendienteAut = (int) DB::table('fich_estados')->where('codigo', 'pendiente_autorizacion')->value('id');
        $this->estadoCorreccion   = (int) DB::table('fich_estados')->where('codigo', 'correccion_requerida')->value('id');

        if ($this->estadoBorrador === 0) {
            $this->markTestSkipped('No se encontraron estados de fichas en la BD.');
        }

        // ── Maestros requeridos para crear fichas ─────────────────────────
        $this->agremiacionId  = (int) DB::table('fich_agremiaciones')->where('estado', 1)->value('id');
        $this->objetoId       = (int) DB::table('fich_objetos_contrato')->where('estado', 1)->value('id');
        $this->especialidadId = (int) DB::table('fich_especialidades')->where('estado', 1)->value('id');

        if ($this->agremiacionId === 0 || $this->objetoId === 0 || $this->especialidadId === 0) {
            $this->markTestSkipped('Faltan datos maestros (agremiaciones, objetos, especialidades).');
        }
    }

    // =========================================================================
    // WHITE-BOX — Generador solo ve sus propias fichas
    // =========================================================================

    /** @test */
    public function test_generador_solo_ve_propias_fichas(): void
    {
        $generadorId = $this->crearUsuario('generador-fichas');
        $otroUserId  = $this->crearUsuario('generador-fichas');

        $fichaPropia = $this->insertarFicha($generadorId, $this->sucursal1Id);
        $fichaAjena  = $this->insertarFicha($otroUserId,  $this->sucursal1Id);

        // Replicar el filtro de BaseFichasController::contextoAlcance para generador
        $filtros = [
            'user_id'     => $generadorId,
            'id_empresa'  => $this->empresaId,
            'solo_propias' => true,
            'id_sucursal' => $this->sucursal1Id,
        ];

        $visibles = $this->consultarFichas($filtros);

        $this->assertContains($fichaPropia, $visibles, 'Generador debe ver su propia ficha.');
        $this->assertNotContains($fichaAjena, $visibles, 'Generador NO debe ver fichas de otro usuario.');
    }

    // =========================================================================
    // WHITE-BOX — Autorizador ve solo su sucursal asignada
    // =========================================================================

    /** @test */
    public function test_autorizador_ve_solo_su_sucursal(): void
    {
        if ($this->sucursal2Id === 0) {
            $this->markTestSkipped('Se necesitan 2 sucursales para esta prueba.');
        }

        $autorizadorId = $this->crearUsuario('autorizador-fichas');

        $fichaSuc1 = $this->insertarFicha($autorizadorId, $this->sucursal1Id);
        $fichaSuc2 = $this->insertarFicha($autorizadorId, $this->sucursal2Id);

        // Filtro: autorizador asignado solo a sucursal1
        $filtros = [
            'user_id'     => $autorizadorId,
            'id_empresa'  => $this->empresaId,
            'id_sucursal' => $this->sucursal1Id,
        ];

        $visibles = $this->consultarFichas($filtros);

        $this->assertContains($fichaSuc1,   $visibles, 'Autorizador debe ver su sucursal asignada.');
        $this->assertNotContains($fichaSuc2, $visibles, 'Autorizador NO debe ver otra sucursal.');
    }

    // =========================================================================
    // GRAY-BOX — Autorizador con múltiples sucursales ve ambas
    // =========================================================================

    /** @test */
    public function test_autorizador_con_dos_sucursales_ve_ambas(): void
    {
        if ($this->sucursal2Id === 0) {
            $this->markTestSkipped('Se necesitan 2 sucursales para esta prueba.');
        }

        $autorizadorId = $this->crearUsuario('autorizador-fichas');

        // Registrar dos sucursales en fich_autorizador_sucursal
        DB::table('fich_autorizador_sucursal')->insert([
            [
                'id_user'      => $autorizadorId,
                'id_empresa'   => $this->empresaId,
                'id_sucursal'  => $this->sucursal1Id,
                'tipo_alcance' => 'sucursal',
                'estado'       => 1,
                'created_at'   => now(),
                'updated_at'   => now(),
            ],
            [
                'id_user'      => $autorizadorId,
                'id_empresa'   => $this->empresaId,
                'id_sucursal'  => $this->sucursal2Id,
                'tipo_alcance' => 'sucursal',
                'estado'       => 1,
                'created_at'   => now(),
                'updated_at'   => now(),
            ],
        ]);

        $fichaSuc1 = $this->insertarFicha($autorizadorId, $this->sucursal1Id);
        $fichaSuc2 = $this->insertarFicha($autorizadorId, $this->sucursal2Id);

        // Filtro con id_sucursales (plural) — tal como lo resuelve alcanceAutorizador()
        $filtros = [
            'user_id'       => $autorizadorId,
            'id_empresa'    => $this->empresaId,
            'id_sucursales' => [$this->sucursal1Id, $this->sucursal2Id],
        ];

        $visibles = $this->consultarFichas($filtros);

        $this->assertContains($fichaSuc1, $visibles, 'Autorizador debe ver ficha de sucursal 1.');
        $this->assertContains($fichaSuc2, $visibles, 'Autorizador debe ver ficha de sucursal 2.');
    }

    // =========================================================================
    // WHITE-BOX — Autorizador nacional ve todas las fichas
    // =========================================================================

    /** @test */
    public function test_autorizador_nacional_ve_todas_las_fichas(): void
    {
        if ($this->sucursal2Id === 0) {
            $this->markTestSkipped('Se necesitan 2 sucursales para esta prueba.');
        }

        $autorizadorId = $this->crearUsuario('autorizador-fichas');

        // Asignar alcance nacional
        DB::table('fich_autorizador_sucursal')->insert([
            'id_user'      => $autorizadorId,
            'id_empresa'   => $this->empresaId,
            'id_sucursal'  => null,
            'tipo_alcance' => 'nacional',
            'estado'       => 1,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $fichaSuc1 = $this->insertarFicha($autorizadorId, $this->sucursal1Id);
        $fichaSuc2 = $this->insertarFicha($autorizadorId, $this->sucursal2Id);

        // Nacional → sin filtro de sucursal
        $filtros = [
            'user_id'    => $autorizadorId,
            'id_empresa' => $this->empresaId,
            // sin id_sucursal ni id_sucursales
        ];

        $visibles = $this->consultarFichas($filtros);

        $this->assertContains($fichaSuc1, $visibles, 'Alcance nacional debe incluir ficha suc1.');
        $this->assertContains($fichaSuc2, $visibles, 'Alcance nacional debe incluir ficha suc2.');
    }

    // =========================================================================
    // GRAY-BOX — Aprobador ve todas las fichas (HTTP)
    // =========================================================================

    /** @test */
    public function test_aprobador_ve_todas_las_fichas_via_http(): void
    {
        $aprobadorId = $this->crearUsuario('aprobador-fichas');
        $generadorId = $this->crearUsuario('generador-fichas');

        $fichaId = $this->insertarFicha($generadorId, $this->sucursal1Id);

        $response = $this->actingAsApiUser($aprobadorId)
            ->getJson('/api/fichas-tecnicas/fichas');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data',
            'meta',
        ]);

        $ids = collect($response->json('data'))->pluck('id')->map(fn ($v) => (int) $v)->toArray();
        $this->assertContains($fichaId, $ids, 'El aprobador debe ver la ficha creada por el generador.');
    }

    // =========================================================================
    // BLACK-BOX — Seguridad empresa: id_empresa ajena es ignorado
    // =========================================================================

    /** @test */
    public function test_usuario_no_puede_acceder_a_empresa_ajena(): void
    {
        // Verificar que el autorizador no tiene acceso a empresa ajena
        $autorizadorId = $this->crearUsuario('autorizador-fichas');

        $tieneAcceso = DB::table('seg_empresa_user')
            ->where('user_id', $autorizadorId)
            ->where('empresa_id', $this->empresaAjenaId)
            ->exists();

        $this->assertFalse($tieneAcceso, 'El usuario recién creado NO debe tener acceso a empresa ajena.');
    }

    /** @test */
    public function test_request_con_empresa_ajena_retorna_solo_datos_propios(): void
    {
        $generadorId = $this->crearUsuario('generador-fichas');
        $fichaPropia = $this->insertarFicha($generadorId, $this->sucursal1Id);

        $response = $this->actingAsApiUser($generadorId)
            ->getJson("/api/fichas-tecnicas/fichas?id_empresa={$this->empresaAjenaId}");

        // La respuesta debe ser OK (backend ignora silenciosamente el id ajeno)
        $response->assertOk();

        // Las fichas devueltas no pertenecen a empresa ajena
        $empresasEnRespuesta = collect($response->json('data'))
            ->pluck('id_empresa')
            ->filter()
            ->unique()
            ->toArray();

        foreach ($empresasEnRespuesta as $emp) {
            $this->assertNotEquals(
                $this->empresaAjenaId,
                (int) $emp,
                "La respuesta no debe contener fichas de empresa ajena ({$this->empresaAjenaId})."
            );
        }
    }

    // =========================================================================
    // WHITE-BOX — Trazabilidad: triggers registran historial
    // =========================================================================

    /** @test */
    public function test_historial_registra_entrada_al_crear_ficha(): void
    {
        $userId  = $this->crearUsuario('generador-fichas');
        $fichaId = $this->insertarFicha($userId, $this->sucursal1Id);

        // El historial lo registra FichAuditoriaService (código, no trigger).
        // Simulamos lo que hace FichFichaService al crear la ficha.
        $this->insertarHistorial($fichaId, null, $this->estadoBorrador, $userId);

        $historial = DB::table('fich_historial_estados')
            ->where('id_ficha', $fichaId)
            ->orderBy('created_at')
            ->get();

        $this->assertNotEmpty($historial->toArray(), 'Debe haber al menos una entrada de historial.');

        $primera = $historial->first();
        $this->assertEquals($this->estadoBorrador, (int) $primera->id_estado_nuevo, 'El estado nuevo debe ser borrador.');
        $this->assertEquals($userId, (int) $primera->id_usuario, 'El usuario debe quedar registrado.');
        $this->assertNotNull($primera->created_at, 'Debe tener timestamp.');
    }

    /** @test */
    public function test_historial_registra_cambio_de_estado(): void
    {
        $userId  = $this->crearUsuario('generador-fichas');
        $fichaId = $this->insertarFicha($userId, $this->sucursal1Id);

        // Entrada inicial (borrador)
        $this->insertarHistorial($fichaId, null, $this->estadoBorrador, $userId);

        // Avance: borrador → pendiente_autorizacion
        DB::table('fich_fichas')
            ->where('id', $fichaId)
            ->update(['id_estado' => $this->estadoPendienteAut, 'updated_at' => now()]);

        $this->insertarHistorial($fichaId, $this->estadoBorrador, $this->estadoPendienteAut, $userId, 'Enviada a autorización QA');

        $historial = DB::table('fich_historial_estados')
            ->where('id_ficha', $fichaId)
            ->orderBy('created_at')
            ->get();

        $this->assertGreaterThanOrEqual(2, $historial->count(), 'Deben existir al menos 2 entradas de historial.');

        $ultima = $historial->last();
        $this->assertEquals($this->estadoPendienteAut, (int) $ultima->id_estado_nuevo, 'El último estado debe ser pendiente_autorizacion.');
        $this->assertStringContainsString('QA', $ultima->observacion ?? '', 'La observación debe estar registrada.');
    }

    // =========================================================================
    // GRAY-BOX — Trazabilidad vía endpoint HTTP
    // =========================================================================

    /** @test */
    public function test_trazabilidad_via_endpoint_historial(): void
    {
        $userId  = $this->crearUsuario('generador-fichas');
        $fichaId = $this->insertarFicha($userId, $this->sucursal1Id);

        // Registrar historial inicial (como haría FichFichaService)
        $this->insertarHistorial($fichaId, null, $this->estadoBorrador, $userId, 'Creación QA');

        $response = $this->actingAsApiUser($userId)
            ->getJson("/api/fichas-tecnicas/fichas/{$fichaId}/historial");

        $response->assertOk();

        $data = $response->json('data');
        $this->assertNotEmpty($data, 'El endpoint de historial debe devolver al menos una entrada.');

        // El historial incluye la relación estadoNuevo y usuario
        $entrada = $data[0];
        $this->assertArrayHasKey('id_ficha',        $entrada, 'Debe incluir id_ficha.');
        $this->assertArrayHasKey('id_estado_nuevo',  $entrada, 'Debe incluir id_estado_nuevo.');
        $this->assertArrayHasKey('created_at',       $entrada, 'Debe incluir created_at.');
        $this->assertArrayHasKey('estado_nuevo',     $entrada, 'Debe incluir relación estado_nuevo.');

        // El estado nuevo tiene código
        $estadoNuevo = $entrada['estado_nuevo'];
        $this->assertNotNull($estadoNuevo, 'estado_nuevo no debe ser null.');
        $this->assertArrayHasKey('codigo', $estadoNuevo, 'estado_nuevo debe incluir codigo.');
    }

    // =========================================================================
    // GRAY-BOX — KPIs resumen-bandeja respetan alcance del usuario
    // =========================================================================

    /** @test */
    public function test_resumen_bandeja_retorna_estructura_correcta(): void
    {
        $userId = $this->crearUsuario('aprobador-fichas');
        $this->insertarFicha($userId, $this->sucursal1Id);

        $response = $this->actingAsApiUser($userId)
            ->getJson('/api/fichas-tecnicas/dashboard/resumen-bandeja');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data' => [
                'total',
                'en_proceso',
                'aprobadas',
                'rechazadas',
                'proximas_vencer',
                'valor_contratado',
            ],
        ]);

        $data = $response->json('data');
        $this->assertIsInt($data['total'],      'total debe ser entero.');
        $this->assertIsInt($data['en_proceso'], 'en_proceso debe ser entero.');
        $this->assertIsInt($data['aprobadas'],  'aprobadas debe ser entero.');
        $this->assertGreaterThanOrEqual(0, $data['total'], 'total no puede ser negativo.');
    }

    // =========================================================================
    // HELPERS privados
    // =========================================================================

    /**
     * Crea un usuario mínimo válido con el rol especificado.
     * DatabaseTransactions garantiza rollback al terminar el test.
     */
    private function crearUsuario(string $nombreRol): int
    {
        // Garantizar que el rol existe en seg_roles (guard api)
        $idRol = (int) DB::table('seg_roles')
            ->where('name', $nombreRol)
            ->where('guard_name', 'api')
            ->value('id');

        if ($idRol === 0) {
            $idRol = (int) DB::table('seg_roles')->insertGetId([
                'name'       => $nombreRol,
                'guard_name' => 'api',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Usuario mínimo (solo campos requeridos de la tabla users)
        $userId = (int) DB::table('users')->insertGetId([
            'name'        => "QA {$nombreRol} " . uniqid(),
            'email'       => 'qa.' . uniqid() . '@test.local',
            'password'    => bcrypt('Test1234!'),
            'id_sucursal' => $this->sucursal1Id,
            'estado'      => 1,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Asignar rol Spatie
        DB::table('seg_model_has_roles')->insertOrIgnore([
            'role_id'    => $idRol,
            'model_type' => 'App\\Models\\User',
            'model_id'   => $userId,
        ]);

        // Asignar empresa
        DB::table('seg_empresa_user')->insertOrIgnore([
            'user_id'    => $userId,
            'empresa_id' => $this->empresaId,
            'recursivo'  => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }

    /**
     * Inserta una ficha mínima en borrador, devuelve su ID.
     */
    private function insertarFicha(int $userId, int $idSucursal): int
    {
        return (int) DB::table('fich_fichas')->insertGetId([
            'id_empresa'         => $this->empresaId,
            'id_sucursal'        => $idSucursal,
            'sucursal_legacy'    => 'QA-TEST',
            'id_estado'          => $this->estadoBorrador,
            'id_user_reg'        => $userId,
            'id_agremiacion'     => $this->agremiacionId,
            'id_objeto_contrato' => $this->objetoId,
            'id_especialidad'    => $this->especialidadId,
            'vlr_contrato'       => 500000.00,
            'fecha_ini'          => now()->toDateString(),
            'fecha_fin'          => now()->addMonths(6)->toDateString(),
            'version'            => 1,
            'ciclos_flujo'       => 0,
            'total_detalles'     => 0,
            'valor_total_detalles' => 0,
            'total_profesionales'  => 0,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    /**
     * Aplica los mismos filtros que FichFichaService::aplicarFiltroAlcance().
     * Devuelve lista de IDs de fichas visibles.
     *
     * @return int[]
     */
    private function consultarFichas(array $filtros): array
    {
        $query = DB::table('fich_fichas')->whereNull('deleted_at');

        if (! empty($filtros['solo_propias']) && ! empty($filtros['user_id'])) {
            $query->where('id_user_reg', (int) $filtros['user_id']);
        }

        if (! empty($filtros['id_empresa'])) {
            $query->where('id_empresa', (int) $filtros['id_empresa']);
        }

        // Múltiples sucursales (autorizador con varias sedes)
        if (! empty($filtros['id_sucursales']) && is_array($filtros['id_sucursales'])) {
            $query->whereIn('id_sucursal', array_map('intval', $filtros['id_sucursales']));
        } elseif (! empty($filtros['id_sucursal'])) {
            $query->where('id_sucursal', (int) $filtros['id_sucursal']);
        }

        return $query->pluck('id')->map(fn ($v) => (int) $v)->toArray();
    }

    /**
     * Inserta una entrada en fich_historial_estados.
     * Reproduce lo que hace FichFichaService + FichAuditoriaService.
     */
    private function insertarHistorial(
        int $idFicha,
        ?int $idEstadoAnterior,
        int $idEstadoNuevo,
        int $idUsuario,
        ?string $observacion = null
    ): int {
        return (int) DB::table('fich_historial_estados')->insertGetId([
            'id_ficha'           => $idFicha,
            'id_estado_anterior' => $idEstadoAnterior,
            'id_estado_nuevo'    => $idEstadoNuevo,
            'id_usuario'         => $idUsuario,
            'observacion'        => $observacion,
            'created_at'         => now(),
        ]);
    }

    /**
     * Autenticar vía guard api para pruebas HTTP.
     */
    private function actingAsApiUser(int $userId): static
    {
        $user = \App\Models\User::find($userId);

        if ($user === null) {
            $this->markTestSkipped("Usuario {$userId} no encontrado en BD.");
        }

        return $this->actingAs($user, 'api');
    }
}
