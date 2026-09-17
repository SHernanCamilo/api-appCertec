<?php

namespace Tests\Feature;

use App\Models\Inventory\InvOrdenCompra;
use App\Models\Inventory\InvPedidoDetalle;
use App\Models\Inventory\InvRecepcion;
use App\Models\Inventory\InvRecepcionDetalle;
use App\Services\Inventory\Pharmacy\InvRecepcionService;
use App\Services\Inventory\Pharmacy\InvSequenceService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Verifica que la recepción técnica con DESDOBLAMIENTO (un renglón de la OC que
 * llega en varios CUM/lote) guarda cada fragmento como un detalle independiente
 * en inv_recepcion_detalles, marca los hijos con es_desdoblamiento y acumula la
 * cantidad recibida del renglón de la OC.
 *
 * No usa RefreshDatabase (la BD de testing ya está migrada; migrate:fresh falla
 * por un seeder no relacionado). Siembra lo mínimo con FK checks apagadas y
 * limpia lo creado en tearDown.
 */
class InvRecepcionDesdoblamientoTest extends TestCase
{
    private int $compraId = 0;
    private int $pedidoDetalleId = 0;
    private int $userId = 999001; // id sintético para el test

    protected function setUp(): void
    {
        parent::setUp();

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $compra = InvOrdenCompra::create([
            'numero_orden_compra' => 'TEST-DESD-' . uniqid(),
            'fecha_orden'         => now()->toDateString(),
            'proveedor_nombre'    => 'Proveedor Test',
            'estado'              => 'confirmado',
            'creado_por'          => $this->userId,
            'sucursal_id'         => 1,
        ]);
        $this->compraId = (int) $compra->id;

        $pd = InvPedidoDetalle::create([
            'pedido_id'           => 987654,
            'codigo_producto'     => 'PROD-TEST-1',
            'producto_nombre'     => 'ETONOGESTREL 68MG IMPLANTE',
            'cantidad_solicitada' => 50,
            'cantidad_recibida'   => 0,
            'estado'              => 'pendiente',
        ]);
        $this->pedidoDetalleId = (int) $pd->id;

        // El servicio de secuencia toca tablas de consecutivos; lo simulamos para
        // aislar el test a la lógica de guardado de detalles.
        $this->mock(InvSequenceService::class, function ($mock) {
            $mock->shouldReceive('generateSequence')->andReturn('TEST-2026-000001');
        });
    }

    protected function tearDown(): void
    {
        // Limpiar lo creado por el test.
        $recepciones = InvRecepcion::where('compra_id', $this->compraId)->pluck('id');
        InvRecepcionDetalle::whereIn('recepcion_id', $recepciones)->delete();
        InvRecepcion::whereIn('id', $recepciones)->delete();
        InvPedidoDetalle::where('id', $this->pedidoDetalleId)->delete();
        InvOrdenCompra::where('id', $this->compraId)->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        parent::tearDown();
    }

    public function test_desdoblamiento_crea_un_detalle_por_fragmento(): void
    {
        /** @var InvRecepcionService $service */
        $service = app(InvRecepcionService::class);

        // Un renglón (mismo pedido_detalle_id) que llega en 3 fragmentos: 25 + 10 + 15 = 50.
        $baseItem = [
            'pedido_detalle_id'   => $this->pedidoDetalleId,
            'codigo_producto'     => 'PROD-TEST-1',
            'producto_nombre'     => 'ETONOGESTREL 68MG IMPLANTE',
            'cantidad_solicitada' => 50,
            'numero_lote'         => 'LOTE-A',
            'fecha_vencimiento'   => '2027-01-01',
            'estado_invima'       => 'Vigente',
            'concepto_recepcion'  => 'aceptado',
            'recibido'            => 1,
        ];

        $payload = [
            'compra_id' => $this->compraId,
            'items'     => [
                // Padre
                array_merge($baseItem, [
                    'cum_recibido' => 'CUM-0001', 'numero_lote' => 'LOTE-A',
                    'cantidad_recibida' => 25, 'es_desdoblamiento' => 0,
                    'cadena_frio_temperatura' => 4.5,
                ]),
                // Fragmento 1
                array_merge($baseItem, [
                    'cum_recibido' => 'CUM-0002', 'numero_lote' => 'LOTE-B',
                    'cantidad_recibida' => 10, 'es_desdoblamiento' => 1,
                    'cadena_frio_temperatura' => 5.0,
                ]),
                // Fragmento 2
                array_merge($baseItem, [
                    'cum_recibido' => 'CUM-0003', 'numero_lote' => 'LOTE-C',
                    'cantidad_recibida' => 15, 'es_desdoblamiento' => 1,
                    'cadena_frio_temperatura' => 6.0,
                ]),
            ],
        ];

        $result = $service->store($payload, $this->userId);

        $this->assertTrue($result['success'], 'store() debe retornar success. Msg: ' . ($result['message'] ?? '') . ' Err: ' . ($result['error'] ?? ''));

        $recepcionId = (int) $result['data']->id;

        // 1) Se crearon 3 detalles para el MISMO pedido_detalle_id (padre + 2 hijos).
        $detalles = InvRecepcionDetalle::where('recepcion_id', $recepcionId)
            ->where('pedido_detalle_id', $this->pedidoDetalleId)
            ->orderBy('id')
            ->get();
        $this->assertCount(3, $detalles, 'Deben existir 3 detalles (1 padre + 2 fragmentos).');

        // 2) Un padre (es_desdoblamiento=0) y dos hijos (es_desdoblamiento=1).
        $padres = $detalles->where('es_desdoblamiento', 0)->values();
        $hijos  = $detalles->where('es_desdoblamiento', 1)->values();
        $this->assertCount(1, $padres, 'Debe haber exactamente 1 detalle padre.');
        $this->assertCount(2, $hijos, 'Deben existir 2 fragmentos (hijos).');

        // 3) Cada fragmento conserva su propio CUM y lote (trazabilidad).
        $cums = $detalles->pluck('cum_recibido')->sort()->values()->all();
        $this->assertSame(['CUM-0001', 'CUM-0002', 'CUM-0003'], $cums);
        $lotes = $detalles->pluck('numero_lote')->sort()->values()->all();
        $this->assertSame(['LOTE-A', 'LOTE-B', 'LOTE-C'], $lotes);

        // 4) La temperatura de cadena de frío se persiste por fragmento.
        $temps = $detalles->pluck('cadena_frio_temperatura')->map(fn ($t) => (float) $t)->sort()->values()->all();
        $this->assertSame([4.5, 5.0, 6.0], $temps);

        // 5) El renglón de la OC acumula la cantidad recibida total (25+10+15=50).
        $pd = InvPedidoDetalle::find($this->pedidoDetalleId);
        $this->assertEquals(50, (int) $pd->cantidad_recibida, 'El pedido_detalle debe acumular la suma de los fragmentos.');
    }
}
