<?php

namespace Tests\Unit\Services\Workflow;

use Tests\TestCase;
use App\Services\Workflow\WorkflowExecutor;
use App\Services\Workflow\WorkflowNotifier;
use App\Models\Workflow\WfDefinicion;
use App\Models\Workflow\WfInstancia;
use App\Models\Workflow\WfPaso;
use App\Models\Workflow\WfAprobacion;
use App\Models\Workflow\WfModulo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class WorkflowExecutorTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowExecutor $executor;
    private $mockNotifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockNotifier = Mockery::mock(WorkflowNotifier::class);
        $this->mockNotifier->shouldReceive('notificarAprobador')->andReturnNull();
        $this->mockNotifier->shouldReceive('notificarAprobacion')->andReturnNull();
        $this->mockNotifier->shouldReceive('notificarRechazo')->andReturnNull();
        $this->mockNotifier->shouldReceive('esUsuarioAutorizado')->andReturn(true);

        $this->executor = new WorkflowExecutor($this->mockNotifier);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function iniciar_flujo_crea_instancia_en_primer_paso(): void
    {
        $modulo = WfModulo::factory()->create(['codigo' => 'anticipos']);
        $flujo = WfDefinicion::factory()->create(['id_modulo' => $modulo->id, 'estado' => true]);
        $paso1 = WfPaso::factory()->create(['id_definicion' => $flujo->id, 'orden' => 1, 'rol_aprobador' => 'jefe_inmediato', 'estado' => true]);
        $paso2 = WfPaso::factory()->create(['id_definicion' => $flujo->id, 'orden' => 2, 'rol_aprobador' => 'financiero', 'estado' => true]);

        $instancia = $this->executor->iniciarFlujo(
            $flujo,
            1, // solicitante_id
            ['record_id' => 123, 'nivel' => 2, 'prefijo' => 'MA'],
            'ANT-2026-00001'
        );

        $this->assertNotNull($instancia->id);
        $this->assertEquals(WfInstancia::ESTADO_EN_PROGRESO, $instancia->estado);
        $this->assertEquals($paso1->id, $instancia->id_paso_actual);
        $this->assertEquals(1, $instancia->solicitante_id);
        $this->assertEquals(123, $instancia->modulo_record_id);
        $this->assertEquals('ANT-2026-00001', $instancia->consecutivo);
        $this->assertEquals(['record_id' => 123, 'nivel' => 2, 'prefijo' => 'MA'], $instancia->contexto);
    }

    /** @test */
    public function iniciar_flujo_falla_sin_record_id_en_contexto(): void
    {
        $modulo = WfModulo::factory()->create(['codigo' => 'anticipos']);
        $flujo = WfDefinicion::factory()->create(['id_modulo' => $modulo->id, 'estado' => true]);
        WfPaso::factory()->create(['id_definicion' => $flujo->id, 'orden' => 1, 'estado' => true]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("record_id");

        $this->executor->iniciarFlujo($flujo, 1, ['nivel' => 2]);
    }

    /** @test */
    public function iniciar_flujo_falla_si_no_tiene_pasos(): void
    {
        $modulo = WfModulo::factory()->create(['codigo' => 'anticipos']);
        $flujo = WfDefinicion::factory()->create(['id_modulo' => $modulo->id, 'estado' => true]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("no tiene pasos");

        $this->executor->iniciarFlujo($flujo, 1, ['record_id' => 1]);
    }

    /** @test */
    public function aprobar_avanza_al_siguiente_paso(): void
    {
        $modulo = WfModulo::factory()->create(['codigo' => 'anticipos']);
        $flujo = WfDefinicion::factory()->create(['id_modulo' => $modulo->id, 'estado' => true]);
        $paso1 = WfPaso::factory()->create(['id_definicion' => $flujo->id, 'orden' => 1, 'rol_aprobador' => 'jefe_inmediato', 'estado' => true]);
        $paso2 = WfPaso::factory()->create(['id_definicion' => $flujo->id, 'orden' => 2, 'rol_aprobador' => 'financiero', 'estado' => true]);
        $paso3 = WfPaso::factory()->create(['id_definicion' => $flujo->id, 'orden' => 3, 'rol_aprobador' => 'tesoreria', 'estado' => true]);

        $instancia = WfInstancia::factory()->create([
            'id_definicion' => $flujo->id,
            'id_modulo' => $modulo->id,
            'modulo_record_id' => 100,
            'id_paso_actual' => $paso1->id,
            'estado' => WfInstancia::ESTADO_EN_PROGRESO,
            'solicitante_id' => 1,
        ]);

        $result = $this->executor->aprobar($instancia->id, 45, 'Aprobado');

        $this->assertEquals(WfInstancia::ESTADO_EN_PROGRESO, $result->estado);
        $this->assertEquals($paso2->id, $result->id_paso_actual);

        // Verificar que se creó la aprobación
        $this->assertDatabaseHas('wf_aprobaciones', [
            'id_instancia' => $instancia->id,
            'id_paso' => $paso1->id,
            'id_user' => 45,
            'accion' => WfAprobacion::ACCION_APROBADO,
            'comentario' => 'Aprobado',
        ]);
    }

    /** @test */
    public function aprobar_ultimo_paso_completa_flujo(): void
    {
        $modulo = WfModulo::factory()->create(['codigo' => 'anticipos']);
        $flujo = WfDefinicion::factory()->create(['id_modulo' => $modulo->id, 'estado' => true]);
        $paso1 = WfPaso::factory()->create(['id_definicion' => $flujo->id, 'orden' => 1, 'estado' => true]);
        $paso2 = WfPaso::factory()->create(['id_definicion' => $flujo->id, 'orden' => 2, 'estado' => true]);

        $instancia = WfInstancia::factory()->create([
            'id_definicion' => $flujo->id,
            'id_modulo' => $modulo->id,
            'modulo_record_id' => 100,
            'id_paso_actual' => $paso2->id, // ya en el último paso
            'estado' => WfInstancia::ESTADO_EN_PROGRESO,
            'solicitante_id' => 1,
        ]);

        $result = $this->executor->aprobar($instancia->id, 78, 'Todo OK', 500000);

        $this->assertEquals(WfInstancia::ESTADO_COMPLETADO, $result->estado);
        $this->assertNull($result->id_paso_actual);
        $this->assertNotNull($result->fecha_completado);
    }

    /** @test */
    public function rechazar_finaliza_flujo(): void
    {
        $modulo = WfModulo::factory()->create(['codigo' => 'anticipos']);
        $flujo = WfDefinicion::factory()->create(['id_modulo' => $modulo->id, 'estado' => true]);
        $paso1 = WfPaso::factory()->create(['id_definicion' => $flujo->id, 'orden' => 1, 'estado' => true]);

        $instancia = WfInstancia::factory()->create([
            'id_definicion' => $flujo->id,
            'id_modulo' => $modulo->id,
            'modulo_record_id' => 100,
            'id_paso_actual' => $paso1->id,
            'estado' => WfInstancia::ESTADO_EN_PROGRESO,
            'solicitante_id' => 1,
        ]);

        $result = $this->executor->rechazar($instancia->id, 45, 'Viaje no justificado');

        $this->assertEquals(WfInstancia::ESTADO_RECHAZADO, $result->estado);
        $this->assertNotNull($result->fecha_rechazado);

        $this->assertDatabaseHas('wf_aprobaciones', [
            'id_instancia' => $instancia->id,
            'id_paso' => $paso1->id,
            'id_user' => 45,
            'accion' => WfAprobacion::ACCION_RECHAZADO,
            'comentario' => 'Viaje no justificado',
        ]);
    }

    /** @test */
    public function no_permite_aprobar_instancia_no_en_progreso(): void
    {
        $modulo = WfModulo::factory()->create(['codigo' => 'anticipos']);
        $flujo = WfDefinicion::factory()->create(['id_modulo' => $modulo->id, 'estado' => true]);
        $paso = WfPaso::factory()->create(['id_definicion' => $flujo->id, 'orden' => 1, 'estado' => true]);

        $instancia = WfInstancia::factory()->create([
            'id_definicion' => $flujo->id,
            'id_modulo' => $modulo->id,
            'modulo_record_id' => 100,
            'id_paso_actual' => $paso->id,
            'estado' => WfInstancia::ESTADO_COMPLETADO,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("no está en progreso");

        $this->executor->aprobar($instancia->id, 45);
    }

    /** @test */
    public function cancelar_instancia_registra_observacion(): void
    {
        $modulo = WfModulo::factory()->create(['codigo' => 'anticipos']);
        $flujo = WfDefinicion::factory()->create(['id_modulo' => $modulo->id, 'estado' => true]);
        $paso = WfPaso::factory()->create(['id_definicion' => $flujo->id, 'orden' => 1, 'estado' => true]);

        $instancia = WfInstancia::factory()->create([
            'id_definicion' => $flujo->id,
            'id_modulo' => $modulo->id,
            'modulo_record_id' => 100,
            'id_paso_actual' => $paso->id,
            'estado' => WfInstancia::ESTADO_EN_PROGRESO,
        ]);

        $result = $this->executor->cancelar($instancia->id, 1, 'Solicitud duplicada');

        $this->assertEquals(WfInstancia::ESTADO_CANCELADO, $result->estado);

        $this->assertDatabaseHas('wf_aprobaciones', [
            'id_instancia' => $instancia->id,
            'comentario' => 'CANCELADO: Solicitud duplicada',
        ]);
    }

    /** @test */
    public function paso_condicional_se_salta_si_contexto_no_aplica(): void
    {
        $modulo = WfModulo::factory()->create(['codigo' => 'anticipos']);
        $flujo = WfDefinicion::factory()->create(['id_modulo' => $modulo->id, 'estado' => true]);

        $paso1 = WfPaso::factory()->create(['id_definicion' => $flujo->id, 'orden' => 1, 'rol_aprobador' => 'jefe', 'estado' => true]);
        // Paso 2 requiere monto > 5M (regla condicional)
        $paso2 = WfPaso::factory()->create([
            'id_definicion' => $flujo->id,
            'orden' => 2,
            'rol_aprobador' => 'vp',
            'reglas' => ['monto_min' => 5000001],
            'estado' => true,
        ]);
        $paso3 = WfPaso::factory()->create(['id_definicion' => $flujo->id, 'orden' => 3, 'rol_aprobador' => 'tesoreria', 'estado' => true]);

        // Instancia con monto < 5M → debería saltar paso 2
        $instancia = WfInstancia::factory()->create([
            'id_definicion' => $flujo->id,
            'id_modulo' => $modulo->id,
            'modulo_record_id' => 100,
            'id_paso_actual' => $paso1->id,
            'estado' => WfInstancia::ESTADO_EN_PROGRESO,
            'contexto' => ['monto' => 2000000, 'record_id' => 100],
            'solicitante_id' => 1,
        ]);

        $result = $this->executor->aprobar($instancia->id, 45, 'OK');

        // Debería saltar al paso 3 (tesoreria) porque paso 2 requiere monto > 5M
        $this->assertEquals($paso3->id, $result->id_paso_actual);
    }
}
