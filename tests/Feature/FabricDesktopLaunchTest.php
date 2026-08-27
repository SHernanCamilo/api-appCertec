<?php

namespace Tests\Feature;

use Tests\TestCase;

class FabricDesktopLaunchTest extends TestCase
{
    public function test_claim_rejects_invalid_ticket(): void
    {
        $response = $this->postJson('/api/fabric/viewer/desktop/claim', [
            'ticket' => str_repeat('a', 32),
        ]);

        $response->assertStatus(410)
            ->assertJson(['success' => false]);
    }

    public function test_claim_rejects_malformed_ticket(): void
    {
        $response = $this->postJson('/api/fabric/viewer/desktop/claim', [
            'ticket' => 'not-a-ticket',
        ]);

        $response->assertStatus(422);
    }

    public function test_download_returns_404_when_exe_missing(): void
    {
        $path = storage_path('app/desktop/JadeOneDesktop.exe');
        if (is_file($path)) {
            $this->markTestSkipped('JadeOneDesktop.exe ya está publicado en storage.');
        }

        $response = $this->get('/api/fabric/viewer/desktop/download');

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_launch_requires_authentication(): void
    {
        $response = $this->postJson('/api/fabric/viewer/desktop/launch', [
            'schema_name' => 'dc',
            'view'        => 'VW_Test',
        ]);

        $response->assertStatus(401);
    }
}
