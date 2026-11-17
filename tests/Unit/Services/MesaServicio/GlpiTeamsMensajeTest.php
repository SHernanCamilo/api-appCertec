<?php

declare(strict_types=1);

namespace Tests\Unit\Services\MesaServicio;

use App\Services\MesaServicio\Teams\GlpiTeamsMensaje;
use App\Services\MesaServicio\Teams\GlpiTeamsNotificador;
use Tests\TestCase;

class GlpiTeamsMensajeTest extends TestCase
{
    public function test_el_resumen_informa_conteos_de_todos_los_grupos(): void
    {
        $mensaje = (new GlpiTeamsMensaje())->construir($this->tablero(), [
            'nombre' => 'TIC NACIONAL',
            'modo' => 'resumen',
            'grupos' => [],
        ]);

        $this->assertSame(4, $mensaje['casos']);
        $this->assertStringContainsString('Tablero TIC — TIC NACIONAL', $mensaje['titulo']);
        $this->assertStringContainsString('Grupo | En tiempo | Por vencer | Vencidos | Total', $mensaje['texto']);
        $this->assertStringContainsString('N3 · 3 Desarrollo | 0 | 1 | 1 | 2', $mensaje['texto']);
        $this->assertStringContainsString('N1 · Nivel 1 Nacional | 1 | 1 | 0 | 2', $mensaje['texto']);
        $this->assertStringNotContainsString('sin ANS', $mensaje['texto']);
        $this->assertStringContainsString("VER TABLERO\nhttps://jade.medilaser.com.co/plataform/mesaServicio/tableros/tic", $mensaje['texto']);
        $this->assertStringContainsString('color:#0b5ed7;font-size:22px;font-weight:700', $mensaje['html']);
        $this->assertSame(1, substr_count($mensaje['html'], '>VER TABLERO</a>'));
        $this->assertStringNotContainsString('<!--EXCEL-->', $mensaje['html']);
        $this->assertStringContainsString('<th style="padding:10px;text-align:left;color:#0b5ed7;border-bottom:2px solid #0b5ed7;">Grupo</th>', $mensaje['html']);
        $this->assertStringContainsString('En tiempo</th>', $mensaje['html']);
        $this->assertStringContainsString('Por vencer</th>', $mensaje['html']);
        $this->assertStringContainsString('Vencidos</th>', $mensaje['html']);
        $this->assertStringContainsString('>Total</th>', $mensaje['html']);
        $this->assertStringContainsString('N3 · 3 Desarrollo', $mensaje['html']);
    }

    public function test_el_detalle_solo_incluye_el_grupo_indicado(): void
    {
        $mensaje = (new GlpiTeamsMensaje())->construir($this->tablero(), [
            'nombre' => '3 desarrollo',
            'modo' => 'detalle',
            'grupos' => ['desarrollo'],
        ]);

        $this->assertSame(2, $mensaje['casos']);
        $this->assertStringContainsString('#1 Error facturas', $mensaje['texto']);
        $this->assertStringContainsString('#3 Cambio de documento', $mensaje['texto']);
        $this->assertStringNotContainsString('#2 Usuario nuevo', $mensaje['texto']);
        $this->assertStringContainsString('Sin asignar', $mensaje['texto']);
    }

    public function test_el_excel_trae_una_hoja_por_grupo_con_sus_casos(): void
    {
        $mensaje = new GlpiTeamsMensaje();
        $tablero = $this->tablero();
        $bytes = (new \App\Services\MesaServicio\Teams\GlpiTeamsExcel())->generar(
            $mensaje->casos($tablero, ['grupos' => []])
        );

        $ruta = tempnam(sys_get_temp_dir(), 'tic');
        $this->assertNotFalse($ruta);
        file_put_contents($ruta, $bytes);

        try {
            $libro = \PhpOffice\PhpSpreadsheet\IOFactory::load($ruta);
        } finally {
            @unlink($ruta);
        }

        $resumen = $libro->getSheetByName('Resumen');
        $this->assertNotNull($resumen);
        $this->assertSame('Grupo', $resumen->getCell('A1')->getValue());
        $this->assertSame('En tiempo', $resumen->getCell('B1')->getValue());
        $this->assertSame('Vencidos', $resumen->getCell('D1')->getValue());

        $desarrollo = $libro->getSheetByName('N3 · 3 Desarrollo');
        $this->assertNotNull($desarrollo);
        $this->assertSame('Ticket', $desarrollo->getCell('A1')->getValue());
        $this->assertSame(1, $desarrollo->getCell('A2')->getValue());
        $this->assertSame('Error facturas', $desarrollo->getCell('B2')->getValue());
        $this->assertSame('Vencido', $desarrollo->getCell('F2')->getValue());
        $this->assertSame(3, $desarrollo->getCell('A3')->getValue());

        $nacional = $libro->getSheetByName('N1 · Nivel 1 Nacional');
        $this->assertNotNull($nacional);
        $this->assertSame(2, $nacional->getCell('A2')->getValue());
        $this->assertSame('En tiempo', $nacional->getCell('F2')->getValue());
    }

    public function test_el_webhook_de_office_usa_message_card_y_el_flujo_usa_adaptive_card(): void
    {
        $mensaje = [
            'titulo' => 'Hola',
            'texto' => 'Cuerpo',
            'html' => '<p>Cuerpo</p>',
            'adaptive' => ['type' => 'message'],
            'casos' => 1,
        ];

        $office = GlpiTeamsNotificador::payloadWebhook(
            'https://empresa.webhook.office.com/webhookb2/abc',
            $mensaje
        );
        $flujo = GlpiTeamsNotificador::payloadWebhook(
            'https://prod.logic.azure.com/workflows/x',
            $mensaje
        );

        $this->assertSame('MessageCard', $office['@type']);
        $this->assertSame('Cuerpo', $office['text']);
        $this->assertSame('message', $flujo['type']);
    }

    public function test_extrae_el_chat_desde_el_enlace_de_teams(): void
    {
        $enlace = 'https://teams.microsoft.com/l/chat/19%3A6c5a232b0c844cf8812da59b5bb7a554%40thread.v2/conversations';

        $this->assertSame(
            '19:6c5a232b0c844cf8812da59b5bb7a554@thread.v2',
            \App\Services\MesaServicio\Teams\GraphTeamsClient::chatIdDesdeTexto($enlace)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function tablero(): array
    {
        return [
            'generado_en' => '2026-11-03T09:32:00-05:00',
            'grupo' => ['id' => 29, 'nombre' => 'Nivel 1 Nacional'],
            'tickets' => [
                $this->ticket(1, 'Error facturas', 'Sin asignar', 'vencido', '3 Desarrollo', 3),
                $this->ticket(2, 'Usuario nuevo', 'Ana Pérez', 'en_tiempo', 'Nivel 1 Nacional', 1),
                $this->ticket(3, 'Cambio de documento', 'Sin asignar', 'por_vencer', '3 Desarrollo', 3),
                $this->ticket(4, 'Sin fecha ANS', 'Ana Pérez', 'sin_ans', 'Nivel 1 Nacional', 1),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticket(
        int $id,
        string $titulo,
        string $tecnico,
        string $alerta,
        string $grupo,
        int $nivel
    ): array {
        return [
            'id' => $id,
            'titulo' => $titulo,
            'tecnico' => $tecnico,
            'alerta' => $alerta,
            'tiempo_texto' => 'Faltan 2h',
            'grupo_actual' => $grupo,
            'nivel' => $nivel,
            'prioridad' => 'Media',
            'entidad_corta' => 'Sede',
            'url' => 'https://glpi.example/ticket/'.$id,
        ];
    }
}
