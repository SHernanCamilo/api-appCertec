<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Fabric;

use App\Services\Fabric\ODataParquetService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Fija el comportamiento del proxy al endpoint OData de parquet de Graph.
 *
 * No golpea Graph real: se falsea la respuesta HTTP con Http::fake() y se
 * verifica QUE la query hacia Graph se arma bien (token, grupos, department,
 * $top/$skip/$count/$orderby/$select) y que la respuesta OData se traduce al
 * contrato que espera el controlador.
 */
final class ODataParquetServiceTest extends TestCase
{
    private ODataParquetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('fabric.url', 'http://graph.test');
        config()->set('fabric.token_admin', 'TOKEN123');
        $this->service = app(ODataParquetService::class);
    }

    public function test_arma_la_query_con_token_grupos_y_department(): void
    {
        Http::fake([
            'graph.test/*' => Http::response([
                'value'         => [['SOURCE' => '051', 'Llave' => 'A1']],
                '@odata.count'  => 567740,
                '@odata.nextLink' => 'http://graph.test/api/data/odata/dc/VW_AD_Paciente?$skip=50000',
            ], 200, ['X-Source' => 'parquet-local', 'X-Returned-Rows' => '1', 'X-Parquet-Age-Min' => '3.5']),
        ]);

        $result = $this->service->page('dc', 'VW_AD_Paciente', 0, 50000, [], true);

        Http::assertSent(function ($request) {
            $url = $request->url();
            return str_contains($url, '/api/data/odata/dc/VW_AD_Paciente')
                && str_contains($url, 'token=TOKEN123')
                && str_contains($url, 'grupos=' . urlencode('GG-BD-DC,GG-BD-ADMIN'))
                && str_contains($url, 'department=NAL')
                && str_contains($url, '%24top=50000')   // $top url-encoded
                && str_contains($url, '%24count=true');
        });

        $this->assertTrue($result['success']);
        $this->assertSame(567740, $result['count']);
        $this->assertSame(50000, $result['next_skip']);
        $this->assertSame('parquet-local', $result['source']);
        $this->assertSame(3.5, $result['age_min']);
    }

    public function test_no_pide_count_cuando_no_se_solicita(): void
    {
        Http::fake([
            'graph.test/*' => Http::response(['value' => []], 200),
        ]);

        $this->service->page('dc', 'VW_Test', 50000, 50000, [], false);

        Http::assertSent(fn ($request) => !str_contains($request->url(), 'count'));
    }

    public function test_sin_nextlink_no_hay_pagina_siguiente(): void
    {
        Http::fake([
            'graph.test/*' => Http::response([
                'value' => [['A' => 1]],
                // sin @odata.nextLink → última página
            ], 200),
        ]);

        $result = $this->service->page('dc', 'VW_Test', 0, 50000);

        $this->assertNull($result['next_skip'], 'Sin nextLink no debe proponer skip siguiente');
        $this->assertNull($result['count'], 'Sin $count no debe inventar total');
    }

    public function test_pasa_orderby_y_select_a_graph(): void
    {
        Http::fake(['graph.test/*' => Http::response(['value' => []], 200)]);

        $this->service->page('dc', 'VW_Test', 0, 1000, [
            'columns'  => ['SOURCE', 'Llave'],
            'sort_col' => 'Fecha',
            'sort_dir' => 'desc',
        ]);

        Http::assertSent(function ($request) {
            $url = urldecode($request->url());
            return str_contains($url, '$orderby=Fecha desc')
                && str_contains($url, '$select=SOURCE,Llave');
        });
    }

    public function test_top_se_recorta_al_maximo_permitido(): void
    {
        Http::fake(['graph.test/*' => Http::response(['value' => []], 200)]);

        // Pedir 500000 debe recortarse al tope de 200000
        $this->service->page('dc', 'VW_Test', 0, 500000);

        Http::assertSent(fn ($request) => str_contains(urldecode($request->url()), '$top=200000'));
    }

    public function test_409_devuelve_mensaje_de_parquet_no_listo(): void
    {
        Http::fake([
            'graph.test/*' => Http::response(['error' => 'no_parquet'], 409),
        ]);

        $result = $this->service->page('dc', 'VW_SinParquet', 0, 50000);

        $this->assertFalse($result['success']);
        $this->assertSame(409, $result['status']);
        $this->assertStringContainsString('parquet', strtolower($result['message']));
    }

    public function test_error_5xx_se_normaliza_a_502(): void
    {
        Http::fake([
            'graph.test/*' => Http::response('boom', 500),
        ]);

        $result = $this->service->page('dc', 'VW_Test', 0, 50000);

        $this->assertFalse($result['success']);
        $this->assertSame(502, $result['status']);
    }

    public function test_fallo_de_conexion_devuelve_503(): void
    {
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('sin red');
        });

        $result = $this->service->page('dc', 'VW_Test', 0, 50000);

        $this->assertFalse($result['success']);
        $this->assertSame(503, $result['status']);
    }
}
