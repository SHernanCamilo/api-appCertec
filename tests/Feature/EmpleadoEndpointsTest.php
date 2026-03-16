<?php

namespace Tests\Feature;

use App\Models\Cargo;
use App\Models\Empleado;
use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmpleadoEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private function crearEmpresa(): Empresa
    {
        return Empresa::create([
            'nombre' => 'Empresa Test',
            'prefijo' => 'TEST',
            'rep_legal' => 'Representante Test',
            'cc_rep_legal' => 12345678,
            'direccion' => 'Direccion Test',
            'telefono' => 3001234567,
            'nit' => 900123456,
            'estado' => 1
        ]);
    }

    private function crearCargo(): Cargo
    {
        return Cargo::create([
            'nombre_cargo' => 'Analista',
            'descripcion' => null,
            'estado' => true
        ]);
    }

    public function test_lista_empleados(): void
    {
        $user = User::factory()->create();
        $empresa = $this->crearEmpresa();
        $cargo = $this->crearCargo();

        Empleado::create([
            'id_empresa' => $empresa->id,
            'id_cargo' => $cargo->id_cargo,
            'numero_identificacion' => '1001',
            'nombre' => 'Empleado Uno',
            'email' => 'empleado1@example.com',
            'tipo_identificacion' => 'CC',
            'estado' => true
        ]);

        $response = $this->actingAs($user, 'api')->getJson('/api/empleados');

        $response->assertStatus(200)
            ->assertJsonFragment(['nombre' => 'Empleado Uno']);
    }

    public function test_crea_empleado(): void
    {
        $user = User::factory()->create();
        $empresa = $this->crearEmpresa();
        $cargo = $this->crearCargo();

        $payload = [
            'id_empresa' => $empresa->id,
            'id_cargo' => $cargo->id_cargo,
            'numero_identificacion' => '2002',
            'nombre' => 'Empleado Dos',
            'email' => 'empleado2@example.com',
            'tipo_identificacion' => 'CC'
        ];

        $response = $this->actingAs($user, 'api')->postJson('/api/empleados', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.numero_identificacion', '2002');

        $this->assertDatabaseHas('config_person_tercero', [
            'numero_identificacion' => '2002',
            'email' => 'empleado2@example.com'
        ]);
    }

    public function test_actualiza_empleado(): void
    {
        $user = User::factory()->create();
        $empresa = $this->crearEmpresa();
        $cargo = $this->crearCargo();

        $empleado = Empleado::create([
            'id_empresa' => $empresa->id,
            'id_cargo' => $cargo->id_cargo,
            'numero_identificacion' => '3003',
            'nombre' => 'Empleado Tres',
            'email' => 'empleado3@example.com',
            'tipo_identificacion' => 'CC',
            'estado' => true
        ]);

        $response = $this->actingAs($user, 'api')->putJson('/api/empleados/' . $empleado->id, [
            'nombre' => 'Empleado Tres Actualizado'
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.nombre', 'Empleado Tres Actualizado');
    }

    public function test_elimina_empleado(): void
    {
        $user = User::factory()->create();
        $empresa = $this->crearEmpresa();
        $cargo = $this->crearCargo();

        $empleado = Empleado::create([
            'id_empresa' => $empresa->id,
            'id_cargo' => $cargo->id_cargo,
            'numero_identificacion' => '4004',
            'nombre' => 'Empleado Cuatro',
            'email' => 'empleado4@example.com',
            'tipo_identificacion' => 'CC',
            'estado' => true
        ]);

        $response = $this->actingAs($user, 'api')->deleteJson('/api/empleados/' . $empleado->id);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('config_person_tercero', [
            'id' => $empleado->id
        ]);
    }
}
