<?php

namespace Tests\Unit\Services\Workflow;

use Tests\TestCase;
use App\Services\Workflow\WorkflowResolver;
use App\Models\Workflow\WfModulo;
use App\Models\Workflow\WfDefinicion;
use App\Models\Workflow\WfRegla;
use Illuminate\Foundation\Testing\RefreshDatabase;

class WorkflowResolverTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new WorkflowResolver();
    }

    /** @test */
    public function resuelve_flujo_nacional_nivel_1_3_para_sucursal_nva(): void
    {
        $modulo = WfModulo::factory()->create(['codigo' => 'anticipos', 'estado' => true]);

        $flujo = WfDefinicion::factory()->create([
            'codigo' => 'FLUJO_NAL_N123',
            'id_modulo' => $modulo->id,
            'id_empresa' => null,
            'estado' => true,
        ]);

        WfRegla::factory()->create([
            'id_definicion' => $flujo->id,
            'prioridad' => 20,
            'condiciones' => [
                'nivel_min' => 1,
                'nivel_max' => 3,
                'prefijo' => 'NVA',
                'cobertura' => 'nacional',
                'monto_max' => 5000000,
            ],
            'estado' => true,
        ]);

        $resultado = $this->resolver->resolverFlujo('anticipos', [
            'nivel' => 2,
            'prefijo' => 'NVA',
            'monto' => 550000,
            'cobertura' => 'nacional',
            'id_empresa' => 1,
        ]);

        $this->assertEquals($flujo->id, $resultado->id);
    }

    /** @test */
    public function resuelve_flujo_vp_para_nivel_4(): void
    {
        $modulo = WfModulo::factory()->create(['codigo' => 'anticipos', 'estado' => true]);

        // Flujo nivel 1-3 (no debería matchear)
        $flujo123 = WfDefinicion::factory()->create([
            'codigo' => 'FLUJO_NAL_N123',
            'id_modulo' => $modulo->id,
            'id_empresa' => null,
            'estado' => true,
        ]);
        WfRegla::factory()->create([
            'id_definicion' => $flujo123->id,
            'prioridad' => 20,
            'condiciones' => ['nivel_min' => 1, 'nivel_max' => 3, 'cobertura' => 'nacional'],
            'estado' => true,
        ]);

        // Flujo VP (debería matchear por prioridad menor = mayor prioridad)
        $flujoVP = WfDefinicion::factory()->create([
            'codigo' => 'FLUJO_VP',
            'id_modulo' => $modulo->id,
            'id_empresa' => null,
            'estado' => true,
        ]);
        WfRegla::factory()->create([
            'id_definicion' => $flujoVP->id,
            'prioridad' => 5,
            'condiciones' => ['nivel_min' => 4, 'cobertura' => 'nacional'],
            'estado' => true,
        ]);

        $resultado = $this->resolver->resolverFlujo('anticipos', [
            'nivel' => 4,
            'prefijo' => 'MA',
            'monto' => 1500000,
            'cobertura' => 'nacional',
            'id_empresa' => 1,
        ]);

        $this->assertEquals($flujoVP->id, $resultado->id);
    }

    /** @test */
    public function resuelve_flujo_monto_alto_cuando_supera_5m(): void
    {
        $modulo = WfModulo::factory()->create(['codigo' => 'anticipos', 'estado' => true]);

        // Flujo normal (monto_max = 5M)
        $flujoNormal = WfDefinicion::factory()->create([
            'codigo' => 'FLUJO_NAL_NORMAL',
            'id_modulo' => $modulo->id,
            'id_empresa' => null,
            'estado' => true,
        ]);
        WfRegla::factory()->create([
            'id_definicion' => $flujoNormal->id,
            'prioridad' => 20,
            'condiciones' => ['nivel_min' => 1, 'nivel_max' => 3, 'cobertura' => 'nacional', 'monto_max' => 5000000],
            'estado' => true,
        ]);

        // Flujo monto alto
        $flujoAlto = WfDefinicion::factory()->create([
            'codigo' => 'FLUJO_MONTO_ALTO',
            'id_modulo' => $modulo->id,
            'id_empresa' => null,
            'estado' => true,
        ]);
        WfRegla::factory()->create([
            'id_definicion' => $flujoAlto->id,
            'prioridad' => 8,
            'condiciones' => ['nivel_min' => 1, 'nivel_max' => 3, 'monto_min' => 5000001, 'cobertura' => 'nacional'],
            'estado' => true,
        ]);

        $resultado = $this->resolver->resolverFlujo('anticipos', [
            'nivel' => 2,
            'prefijo' => 'MA',
            'monto' => 7000000,
            'cobertura' => 'nacional',
            'id_empresa' => 1,
        ]);

        $this->assertEquals($flujoAlto->id, $resultado->id);
    }

    /** @test */
    public function resuelve_flujo_internacional(): void
    {
        $modulo = WfModulo::factory()->create(['codigo' => 'anticipos', 'estado' => true]);

        $flujoIntl = WfDefinicion::factory()->create([
            'codigo' => 'FLUJO_INTERNACIONAL',
            'id_modulo' => $modulo->id,
            'id_empresa' => null,
            'estado' => true,
        ]);
        WfRegla::factory()->create([
            'id_definicion' => $flujoIntl->id,
            'prioridad' => 3,
            'condiciones' => ['cobertura' => 'internacional'],
            'estado' => true,
        ]);

        $resultado = $this->resolver->resolverFlujo('anticipos', [
            'nivel' => 2,
            'prefijo' => 'MA',
            'monto' => 3000000,
            'cobertura' => 'internacional',
            'id_empresa' => 1,
        ]);

        $this->assertEquals($flujoIntl->id, $resultado->id);
    }

    /** @test */
    public function lanza_excepcion_si_modulo_no_existe(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("no encontrado o inactivo");

        $this->resolver->resolverFlujo('modulo_inexistente', ['nivel' => 1]);
    }

    /** @test */
    public function lanza_excepcion_si_no_hay_flujos_configurados(): void
    {
        WfModulo::factory()->create(['codigo' => 'anticipos', 'estado' => true]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("No hay flujos configurados");

        $this->resolver->resolverFlujo('anticipos', ['nivel' => 1, 'id_empresa' => 999]);
    }

    /** @test */
    public function prioriza_flujos_de_empresa_especifica(): void
    {
        $modulo = WfModulo::factory()->create(['codigo' => 'anticipos', 'estado' => true]);

        // Flujo genérico
        $flujoGenerico = WfDefinicion::factory()->create([
            'codigo' => 'FLUJO_GENERICO',
            'id_modulo' => $modulo->id,
            'id_empresa' => null,
            'estado' => true,
        ]);
        WfRegla::factory()->create([
            'id_definicion' => $flujoGenerico->id,
            'prioridad' => 10,
            'condiciones' => ['cobertura' => 'nacional'],
            'estado' => true,
        ]);

        // Flujo específico de empresa 1
        $flujoEmpresa = WfDefinicion::factory()->create([
            'codigo' => 'FLUJO_EMPRESA_1',
            'id_modulo' => $modulo->id,
            'id_empresa' => 1,
            'estado' => true,
        ]);
        WfRegla::factory()->create([
            'id_definicion' => $flujoEmpresa->id,
            'prioridad' => 10,
            'condiciones' => ['cobertura' => 'nacional'],
            'estado' => true,
        ]);

        // Debería priorizar el de empresa 1
        $resultado = $this->resolver->resolverFlujo('anticipos', [
            'nivel' => 2,
            'cobertura' => 'nacional',
            'id_empresa' => 1,
        ]);

        $this->assertEquals($flujoEmpresa->id, $resultado->id);
    }

    /** @test */
    public function resuelve_flujo_por_grupo_asistencial(): void
    {
        $modulo = WfModulo::factory()->create(['codigo' => 'anticipos', 'estado' => true]);

        $flujoAsistencial = WfDefinicion::factory()->create([
            'codigo' => 'FLUJO_ASISTENCIAL',
            'id_modulo' => $modulo->id,
            'id_empresa' => null,
            'estado' => true,
        ]);
        WfRegla::factory()->create([
            'id_definicion' => $flujoAsistencial->id,
            'prioridad' => 15,
            'condiciones' => ['id_grupo' => 1, 'cobertura' => 'nacional'],
            'estado' => true,
        ]);

        $resultado = $this->resolver->resolverFlujo('anticipos', [
            'nivel' => 3,
            'id_grupo' => 1,
            'cobertura' => 'nacional',
            'id_empresa' => 1,
        ]);

        $this->assertEquals($flujoAsistencial->id, $resultado->id);
    }
}
