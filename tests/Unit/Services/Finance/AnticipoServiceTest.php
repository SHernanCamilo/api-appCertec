<?php

namespace Tests\Unit\Services\Finance;

use Tests\TestCase;
use App\Services\Finance\AnticipoService;
use App\Services\Workflow\WorkflowResolver;
use App\Services\Workflow\WorkflowExecutor;
use App\Services\Workflow\WorkflowNotifier;
use App\Models\Finance\AntiSolicitud;
use App\Models\Finance\AntiSolicitudItem;
use App\Models\Finance\AntiSolicitudDocumento;
use App\Models\Finance\AntiCiudad;
use App\Models\Finance\AntiRegla;
use App\Models\Empleado;
use App\Models\Workflow\WfInstancia;
use App\Models\Workflow\WfDefinicion;
use App\Models\Workflow\WfPaso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class AnticipoServiceTest extends TestCase
{
    use RefreshDatabase;

    private AnticipoService $service;
    private $mockResolver;
    private $mockExecutor;
    private $mockNotifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockResolver = Mockery::mock(WorkflowResolver::class);
        $this->mockExecutor = Mockery::mock(WorkflowExecutor::class);
        $this->mockNotifier = Mockery::mock(WorkflowNotifier::class);

        $this->service = new AnticipoService(
            $this->mockResolver,
            $this->mockExecutor,
            $this->mockNotifier
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // =========================================================================
    // TESTS: Cálculo de Topes
    // =========================================================================

    /** @test */
    public function calcula_topes_alimentacion_nivel_1_ciudad_tipo_a(): void
    {
        // Arrange: crear empleado con cargo nivel 1 y ciudad tipo A
        $empleado = $this->crearEmpleadoConNivel(1);
        $ciudad = AntiCiudad::factory()->create(['tipo_ciudad' => 'A']);

        // Reglas de alimentación nivel 1
        AntiRegla::factory()->create(['id_concepto' => 1, 'nivel_jerarquico' => 1, 'descripcion' => 'Desayuno', 'valor_tope' => 35000]);
        AntiRegla::factory()->create(['id_concepto' => 1, 'nivel_jerarquico' => 1, 'descripcion' => 'Almuerzo', 'valor_tope' => 45000]);
        AntiRegla::factory()->create(['id_concepto' => 1, 'nivel_jerarquico' => 1, 'descripcion' => 'Cena', 'valor_tope' => 45000]);

        // Regla de transporte tipo A (nivel 0 = todos)
        AntiRegla::factory()->create(['id_concepto' => 2, 'nivel_jerarquico' => 0, 'descripcion' => 'Transporte Tipo A', 'valor_tope' => 70000]);

        // Act
        $topes = $this->service->calcularTopes(
            $empleado->id,
            $ciudad->id,
            '2026-08-10',
            '2026-08-12'
        );

        // Assert: 3 días, alimentación = (35000+45000+45000)*3 = 375000, transporte = 70000*3 = 210000
        $this->assertEquals(3, $topes['dias']);
        $this->assertEquals(1, $topes['nivel_jerarquico']);
        $this->assertEquals('A', $topes['tipo_ciudad']);
        $this->assertEquals(125000, $topes['alimentacion_diario']); // 35k+45k+45k
        $this->assertEquals(375000, $topes['alimentacion_total']);
        $this->assertEquals(70000, $topes['transporte_diario']);
        $this->assertEquals(210000, $topes['transporte_total']);
        $this->assertEquals(585000, $topes['total']); // 375k + 210k
        $this->assertCount(4, $topes['items']); // 3 alimentación + 1 transporte
    }

    /** @test */
    public function calcula_topes_nivel_3_ciudad_tipo_c(): void
    {
        $empleado = $this->crearEmpleadoConNivel(3);
        $ciudad = AntiCiudad::factory()->create(['tipo_ciudad' => 'C']);

        AntiRegla::factory()->create(['id_concepto' => 1, 'nivel_jerarquico' => 3, 'descripcion' => 'Desayuno', 'valor_tope' => 30000]);
        AntiRegla::factory()->create(['id_concepto' => 1, 'nivel_jerarquico' => 3, 'descripcion' => 'Almuerzo', 'valor_tope' => 40000]);
        AntiRegla::factory()->create(['id_concepto' => 1, 'nivel_jerarquico' => 3, 'descripcion' => 'Cena', 'valor_tope' => 40000]);
        AntiRegla::factory()->create(['id_concepto' => 2, 'nivel_jerarquico' => 0, 'descripcion' => 'Transporte Tipo C', 'valor_tope' => 40000]);

        $topes = $this->service->calcularTopes($empleado->id, $ciudad->id, '2026-08-10', '2026-08-11');

        // 2 días
        $this->assertEquals(2, $topes['dias']);
        $this->assertEquals(3, $topes['nivel_jerarquico']);
        $this->assertEquals(110000, $topes['alimentacion_diario']); // 30k+40k+40k
        $this->assertEquals(40000, $topes['transporte_diario']);
        $this->assertEquals(300000, $topes['total']); // (110k+40k)*2
    }

    /** @test */
    public function minimo_un_dia_cuando_fechas_iguales(): void
    {
        $empleado = $this->crearEmpleadoConNivel(2);
        $ciudad = AntiCiudad::factory()->create(['tipo_ciudad' => 'B']);

        AntiRegla::factory()->create(['id_concepto' => 1, 'nivel_jerarquico' => 2, 'descripcion' => 'Desayuno', 'valor_tope' => 30000]);
        AntiRegla::factory()->create(['id_concepto' => 2, 'nivel_jerarquico' => 0, 'descripcion' => 'Transporte Tipo B', 'valor_tope' => 50000]);

        $topes = $this->service->calcularTopes($empleado->id, $ciudad->id, '2026-08-10', '2026-08-10');

        $this->assertEquals(1, $topes['dias']);
    }

    // =========================================================================
    // TESTS: Creación de Solicitud
    // =========================================================================

    /** @test */
    public function crea_solicitud_y_genera_numero_correlativo(): void
    {
        $empleado = $this->crearEmpleadoConNivel(2);
        $ciudad = AntiCiudad::factory()->create(['tipo_ciudad' => 'B']);
        AntiRegla::factory()->create(['id_concepto' => 1, 'nivel_jerarquico' => 2, 'descripcion' => 'Almuerzo', 'valor_tope' => 40000]);
        AntiRegla::factory()->create(['id_concepto' => 2, 'nivel_jerarquico' => 0, 'descripcion' => 'Transporte Tipo B', 'valor_tope' => 50000]);

        // Workflow resolver lanza excepción (sin flujo configurado → queda en borrador)
        $this->mockResolver->shouldReceive('resolverFlujo')
            ->andThrow(new \Exception('No hay flujo configurado'));

        $solicitud = $this->service->crearSolicitud([
            'id_empleado' => $empleado->id,
            'id_sede_origen' => 1,
            'id_ciudad_destino' => $ciudad->id,
            'fecha_salida' => '2026-08-15',
            'fecha_regreso' => '2026-08-17',
            'motivo' => 'Capacitación regional',
            'cobertura' => 'nacional',
            'radicado_por' => 1,
        ]);

        $this->assertNotNull($solicitud->id);
        $this->assertStringStartsWith('ANT-2026-', $solicitud->numero_solicitud);
        $this->assertEquals('borrador', $solicitud->estado);
        $this->assertEquals($empleado->id, $solicitud->id_empleado);
        $this->assertGreaterThan(0, $solicitud->monto_solicitado);
    }

    /** @test */
    public function crea_solicitud_con_flujo_asignado(): void
    {
        $empleado = $this->crearEmpleadoConNivel(2);
        $ciudad = AntiCiudad::factory()->create(['tipo_ciudad' => 'A']);
        AntiRegla::factory()->create(['id_concepto' => 1, 'nivel_jerarquico' => 2, 'descripcion' => 'Almuerzo', 'valor_tope' => 40000]);
        AntiRegla::factory()->create(['id_concepto' => 2, 'nivel_jerarquico' => 0, 'descripcion' => 'Transporte Tipo A', 'valor_tope' => 70000]);

        // Mock: workflow resuelve un flujo
        $flujo = Mockery::mock(WfDefinicion::class);
        $this->mockResolver->shouldReceive('resolverFlujo')
            ->once()
            ->andReturn($flujo);

        // Mock: executor inicia flujo y retorna instancia con pasoActual
        $paso = new WfPaso(['rol_aprobador' => 'jefe_inmediato']);
        $instancia = Mockery::mock(WfInstancia::class);
        $instancia->shouldReceive('getAttribute')->with('pasoActual')->andReturn($paso);

        $this->mockExecutor->shouldReceive('iniciarFlujo')
            ->once()
            ->andReturn($instancia);

        $solicitud = $this->service->crearSolicitud([
            'id_empleado' => $empleado->id,
            'id_sede_origen' => 1,
            'id_ciudad_destino' => $ciudad->id,
            'fecha_salida' => '2026-08-15',
            'fecha_regreso' => '2026-08-17',
            'motivo' => 'Reunión con proveedor',
            'cobertura' => 'nacional',
            'radicado_por' => 1,
        ]);

        $this->assertEquals('pendiente_jefe_inmediato', $solicitud->estado);
    }

    /** @test */
    public function crea_solicitud_con_items_personalizados_desde_frontend(): void
    {
        $empleado = $this->crearEmpleadoConNivel(2);
        $ciudad = AntiCiudad::factory()->create(['tipo_ciudad' => 'B']);
        AntiRegla::factory()->create(['id_concepto' => 1, 'nivel_jerarquico' => 2, 'descripcion' => 'Almuerzo', 'valor_tope' => 40000]);
        AntiRegla::factory()->create(['id_concepto' => 2, 'nivel_jerarquico' => 0, 'descripcion' => 'Transporte Tipo B', 'valor_tope' => 50000]);

        $this->mockResolver->shouldReceive('resolverFlujo')
            ->andThrow(new \Exception('Sin flujo'));

        $solicitud = $this->service->crearSolicitud([
            'id_empleado' => $empleado->id,
            'id_sede_origen' => 1,
            'id_ciudad_destino' => $ciudad->id,
            'fecha_salida' => '2026-08-15',
            'fecha_regreso' => '2026-08-16',
            'motivo' => 'Auditoría',
            'cobertura' => 'nacional',
            'radicado_por' => 1,
            'items' => [
                ['descripcion' => 'Hospedaje', 'cantidad' => 2, 'valor_unitario' => 100000, 'valor_total' => 200000],
                ['descripcion' => 'Alimentación', 'cantidad' => 2, 'valor_unitario' => 80000, 'valor_total' => 160000],
            ],
        ]);

        $this->assertEquals(360000, $solicitud->monto_solicitado); // 200k + 160k
        $this->assertCount(2, $solicitud->items);
    }

    // =========================================================================
    // TESTS: Aprobación y Rechazo
    // =========================================================================

    /** @test */
    public function aprobar_avanza_al_siguiente_paso(): void
    {
        $solicitud = AntiSolicitud::factory()->create(['estado' => 'pendiente_jefe_inmediato', 'monto_solicitado' => 500000]);

        $pasoFinanciero = new WfPaso(['rol_aprobador' => 'financiero']);
        $instanciaEnProgreso = Mockery::mock(WfInstancia::class);
        $instanciaEnProgreso->shouldReceive('getAttribute')->with('id')->andReturn(1);

        $instanciaAvanzada = Mockery::mock(WfInstancia::class);
        $instanciaAvanzada->shouldReceive('estaCompletado')->andReturn(false);
        $instanciaAvanzada->shouldReceive('getAttribute')->with('pasoActual')->andReturn($pasoFinanciero);

        // Mock del query de WfInstancia
        WfInstancia::shouldReceive('where')->andReturnSelf();
        WfInstancia::shouldReceive('enProgreso')->andReturnSelf();
        WfInstancia::shouldReceive('firstOrFail')->andReturn($instanciaEnProgreso);

        $this->mockExecutor->shouldReceive('aprobar')
            ->with(1, 45, 'Aprobado', null)
            ->andReturn($instanciaAvanzada);

        $result = $this->service->aprobar($solicitud->id, 45, 'Aprobado', null);

        $this->assertEquals('pendiente_financiero', $result->estado);
    }

    /** @test */
    public function aprobar_completa_flujo_y_autoriza(): void
    {
        $solicitud = AntiSolicitud::factory()->create([
            'estado' => 'pendiente_financiero',
            'monto_solicitado' => 500000,
        ]);

        $instanciaEnProgreso = Mockery::mock(WfInstancia::class);
        $instanciaEnProgreso->shouldReceive('getAttribute')->with('id')->andReturn(1);

        $instanciaCompletada = Mockery::mock(WfInstancia::class);
        $instanciaCompletada->shouldReceive('estaCompletado')->andReturn(true);

        WfInstancia::shouldReceive('where')->andReturnSelf();
        WfInstancia::shouldReceive('enProgreso')->andReturnSelf();
        WfInstancia::shouldReceive('firstOrFail')->andReturn($instanciaEnProgreso);

        $this->mockExecutor->shouldReceive('aprobar')
            ->with(1, 78, 'OK', 450000.0)
            ->andReturn($instanciaCompletada);

        $result = $this->service->aprobar($solicitud->id, 78, 'OK', 450000);

        $this->assertEquals('autorizado', $result->estado);
        $this->assertEquals(450000, $result->monto_autorizado);
    }

    /** @test */
    public function rechazar_solicitud_registra_estado_correcto(): void
    {
        $solicitud = AntiSolicitud::factory()->create(['estado' => 'pendiente_jefe_inmediato']);

        $paso = new WfPaso(['rol_aprobador' => 'jefe_inmediato']);
        $instancia = Mockery::mock(WfInstancia::class);
        $instancia->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $instancia->shouldReceive('getAttribute')->with('pasoActual')->andReturn($paso);

        WfInstancia::shouldReceive('where')->andReturnSelf();
        WfInstancia::shouldReceive('enProgreso')->andReturnSelf();
        WfInstancia::shouldReceive('firstOrFail')->andReturn($instancia);

        $this->mockExecutor->shouldReceive('rechazar')
            ->with(1, 45, 'No justificado')
            ->andReturn($instancia);

        $result = $this->service->rechazar($solicitud->id, 45, 'No justificado');

        $this->assertEquals('rechazado_jefe_inmediato', $result->estado);
    }

    // =========================================================================
    // TESTS: Fase Post-Viaje
    // =========================================================================

    /** @test */
    public function desembolsar_cambia_estado_autorizado_a_en_viaje(): void
    {
        $solicitud = AntiSolicitud::factory()->create([
            'estado' => AntiSolicitud::ESTADO_AUTORIZADO,
            'monto_autorizado' => 500000,
        ]);

        $result = $this->service->desembolsar($solicitud->id, 1);

        $this->assertEquals(AntiSolicitud::ESTADO_EN_VIAJE, $result->estado);
    }

    /** @test */
    public function desembolsar_falla_si_no_esta_autorizado(): void
    {
        $solicitud = AntiSolicitud::factory()->create(['estado' => 'borrador']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("estado 'autorizado'");

        $this->service->desembolsar($solicitud->id, 1);
    }

    /** @test */
    public function legalizar_con_sobrante_registra_monto_reintegro(): void
    {
        $solicitud = AntiSolicitud::factory()->create([
            'estado' => AntiSolicitud::ESTADO_PENDIENTE_LEGALIZACION,
            'monto_autorizado' => 500000,
        ]);

        $result = $this->service->legalizar($solicitud->id, 1, [
            'monto_legalizado' => 400000,
            'observaciones' => 'Gastó menos en transporte',
        ]);

        $this->assertEquals(AntiSolicitud::ESTADO_LEGALIZADO, $result->estado);
        $this->assertEquals(400000, $result->monto_legalizado);
        $this->assertEquals(100000, $result->monto_reintegro); // 500k - 400k
    }

    /** @test */
    public function legalizar_con_excedente_registra_monto_excedente(): void
    {
        $solicitud = AntiSolicitud::factory()->create([
            'estado' => AntiSolicitud::ESTADO_PENDIENTE_LEGALIZACION,
            'monto_autorizado' => 500000,
        ]);

        $result = $this->service->legalizar($solicitud->id, 1, [
            'monto_legalizado' => 600000,
        ]);

        $this->assertEquals(AntiSolicitud::ESTADO_LEGALIZADO, $result->estado);
        $this->assertEquals(600000, $result->monto_legalizado);
        $this->assertEquals(100000, $result->monto_excedente); // 600k - 500k
    }

    /** @test */
    public function decidir_contabilidad_aceptar_cierra_solicitud(): void
    {
        $solicitud = AntiSolicitud::factory()->create([
            'estado' => AntiSolicitud::ESTADO_LEGALIZADO,
            'monto_legalizado' => 500000,
            'monto_autorizado' => 500000,
        ]);

        $result = $this->service->decidirContabilidad($solicitud->id, 1, 'aceptar');

        $this->assertEquals(AntiSolicitud::ESTADO_CERRADO, $result->estado);
    }

    /** @test */
    public function decidir_contabilidad_sobrante_requiere_reintegro(): void
    {
        $solicitud = AntiSolicitud::factory()->create([
            'estado' => AntiSolicitud::ESTADO_LEGALIZADO,
            'monto_legalizado' => 400000,
            'monto_autorizado' => 500000,
            'monto_reintegro' => 100000,
        ]);

        $result = $this->service->decidirContabilidad($solicitud->id, 1, 'sobrante');

        $this->assertEquals(AntiSolicitud::ESTADO_PENDIENTE_REINTEGRO, $result->estado);
    }

    /** @test */
    public function ciclo_completo_post_viaje_sobrante(): void
    {
        // autorizado → en_viaje → pendiente_legalizacion → legalizado → pendiente_reintegro → reintegrado → cerrado
        $solicitud = AntiSolicitud::factory()->create([
            'estado' => AntiSolicitud::ESTADO_AUTORIZADO,
            'monto_autorizado' => 500000,
        ]);

        // Desembolsar
        $solicitud = $this->service->desembolsar($solicitud->id, 1);
        $this->assertEquals(AntiSolicitud::ESTADO_EN_VIAJE, $solicitud->estado);

        // Habilitar legalización
        $solicitud = $this->service->habilitarLegalizacion($solicitud->id);
        $this->assertEquals(AntiSolicitud::ESTADO_PENDIENTE_LEGALIZACION, $solicitud->estado);

        // Legalizar con sobrante
        $solicitud = $this->service->legalizar($solicitud->id, 1, ['monto_legalizado' => 400000]);
        $this->assertEquals(AntiSolicitud::ESTADO_LEGALIZADO, $solicitud->estado);
        $this->assertEquals(100000, $solicitud->monto_reintegro);

        // Contabilidad decide sobrante
        $solicitud = $this->service->decidirContabilidad($solicitud->id, 1, 'sobrante');
        $this->assertEquals(AntiSolicitud::ESTADO_PENDIENTE_REINTEGRO, $solicitud->estado);

        // Registrar devolución
        $solicitud = $this->service->registrarDevolucion($solicitud->id, 1);
        $this->assertEquals(AntiSolicitud::ESTADO_REINTEGRADO, $solicitud->estado);

        // Cerrar
        $solicitud = $this->service->cerrar($solicitud->id, 1);
        $this->assertEquals(AntiSolicitud::ESTADO_CERRADO, $solicitud->estado);
    }

    // =========================================================================
    // TESTS: Documentos
    // =========================================================================

    /** @test */
    public function subir_documento_guarda_metadata(): void
    {
        $solicitud = AntiSolicitud::factory()->create();

        $archivo = \Illuminate\Http\UploadedFile::fake()->create('soporte.pdf', 1024, 'application/pdf');

        // Fake el disco onedrive
        \Illuminate\Support\Facades\Storage::fake('onedrive');

        $documento = $this->service->subirDocumento(
            $solicitud->id,
            $archivo,
            'soporte_viaje',
            1
        );

        $this->assertNotNull($documento->id);
        $this->assertEquals('soporte_viaje', $documento->tipo_documento);
        $this->assertEquals('soporte.pdf', $documento->nombre_archivo);
        $this->assertEquals('onedrive', $documento->disco);
        $this->assertEquals('application/pdf', $documento->mime_type);
        $this->assertEquals(1, $documento->subido_por);

        // Verificar que el archivo se guardó
        \Illuminate\Support\Facades\Storage::disk('onedrive')
            ->assertExists($documento->ruta_archivo);
    }

    /** @test */
    public function listar_documentos_retorna_coleccion(): void
    {
        $solicitud = AntiSolicitud::factory()->create();
        AntiSolicitudDocumento::factory()->count(3)->create(['id_solicitud' => $solicitud->id]);

        $documentos = $this->service->listarDocumentos($solicitud->id);

        $this->assertCount(3, $documentos);
    }

    /** @test */
    public function eliminar_documento_borra_archivo_y_registro(): void
    {
        \Illuminate\Support\Facades\Storage::fake('onedrive');

        $solicitud = AntiSolicitud::factory()->create();
        $archivo = \Illuminate\Http\UploadedFile::fake()->create('test.pdf', 512);
        $ruta = $archivo->store("anticipos/{$solicitud->numero_solicitud}", 'onedrive');

        $documento = AntiSolicitudDocumento::factory()->create([
            'id_solicitud' => $solicitud->id,
            'ruta_archivo' => $ruta,
            'disco' => 'onedrive',
        ]);

        $this->service->eliminarDocumento($documento->id, 1);

        $this->assertDatabaseMissing('anti_solicitud_documentos', ['id' => $documento->id]);
        \Illuminate\Support\Facades\Storage::disk('onedrive')->assertMissing($ruta);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function crearEmpleadoConNivel(int $nivel): Empleado
    {
        $cargo = \App\Models\Cargo::factory()->create(['nivel_jerarquico' => $nivel]);
        return Empleado::factory()->create([
            'id_cargo' => $cargo->id_cargo,
            'estado' => true,
        ]);
    }
}
