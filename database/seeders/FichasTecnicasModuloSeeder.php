<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Roles, permisos y plantilla de correo del módulo Fichas Técnicas.
 *
 * Sustituye la autorización por cadena literal del legacy
 * (`$_SESSION['rol'] !== 'AUTORIZADOR'`) por roles Spatie.
 */
class FichasTecnicasModuloSeeder extends Seeder
{
    /** Rol legacy → rol Spatie del módulo. */
    private const ROLES = [
        'generador-fichas'      => 'Fichas Técnicas · Generador',
        'autorizador-fichas'    => 'Fichas Técnicas · Autorizador (Dirección Médica)',
        'aprobador-fichas'      => 'Fichas Técnicas · Aprobador (VP Financiera)',
        'parametrizador-fichas' => 'Fichas Técnicas · Parametrizador',
        'visor-fichas'          => 'Fichas Técnicas · Visor',
    ];

    public function run(): void
    {
        $this->crearRoles();
        $this->crearPlantillaCorreo();
    }

    private function crearRoles(): void
    {
        if (! class_exists(Role::class)) {
            $this->command?->warn('Spatie Permission no disponible; se omiten los roles.');

            return;
        }

        $guard = config('auth.defaults.guard', 'api');

        foreach (self::ROLES as $nombre => $descripcion) {
            Role::findOrCreate($nombre, $guard);
            $this->command?->line("  · rol {$nombre} ({$descripcion})");
        }

        $this->command?->info('✓ '.count(self::ROLES).' roles del módulo Fichas Técnicas');
    }

    private function crearPlantillaCorreo(): void
    {
        $contenido = <<<'HTML'
        <!DOCTYPE html>
        <html lang="es">
        <head><meta charset="UTF-8"><title>Ficha Técnica</title></head>
        <body style="margin:0;padding:24px;background:#f8f9fa;font-family:Segoe UI,Arial,sans-serif;">
          <table role="presentation" style="max-width:640px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;border:1px solid #dee2e6;">
            <tr>
              <td style="background:#0d6efd;color:#fff;padding:18px 24px;">
                <h2 style="margin:0;font-size:18px;">Ficha Técnica — {{estado}}</h2>
                <p style="margin:4px 0 0;font-size:13px;opacity:.9;">{{consecutivo}}</p>
              </td>
            </tr>
            <tr><td style="padding:20px 24px;">
              <table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px;">
                <tr><td style="padding:8px 12px;border-bottom:1px solid #e9ecef;font-weight:600;color:#495057;width:38%;">Agremiación</td><td style="padding:8px 12px;border-bottom:1px solid #e9ecef;">{{agremiacion}}</td></tr>
                <tr><td style="padding:8px 12px;border-bottom:1px solid #e9ecef;font-weight:600;color:#495057;">Especialidad</td><td style="padding:8px 12px;border-bottom:1px solid #e9ecef;">{{especialidad}}</td></tr>
                <tr><td style="padding:8px 12px;border-bottom:1px solid #e9ecef;font-weight:600;color:#495057;">Empresa</td><td style="padding:8px 12px;border-bottom:1px solid #e9ecef;">{{empresa}}</td></tr>
                <tr><td style="padding:8px 12px;border-bottom:1px solid #e9ecef;font-weight:600;color:#495057;">Valor del contrato</td><td style="padding:8px 12px;border-bottom:1px solid #e9ecef;">{{valor}}</td></tr>
                <tr><td style="padding:8px 12px;border-bottom:1px solid #e9ecef;font-weight:600;color:#495057;">Vigencia</td><td style="padding:8px 12px;border-bottom:1px solid #e9ecef;">{{fecha_ini}} — {{fecha_fin}}</td></tr>
                <tr><td style="padding:8px 12px;border-bottom:1px solid #e9ecef;font-weight:600;color:#495057;">Generador</td><td style="padding:8px 12px;border-bottom:1px solid #e9ecef;">{{generador}}</td></tr>
              </table>
              <div style="margin-top:18px;padding:12px;background:#fff3cd;border:1px solid #ffe69c;border-radius:8px;font-size:14px;">
                <strong>Observaciones:</strong><br>{{observacion}}
              </div>
              <p style="margin-top:20px;font-size:13px;color:#6c757d;">Ingrese a JadeOne para revisar el detalle de la ficha.</p>
            </td></tr>
            <tr><td style="background:#f1f3f5;padding:12px 24px;font-size:12px;color:#6c757d;">
              JadeOne · Fichas Técnicas Médicas · {{fecha_evento}}
            </td></tr>
          </table>
        </body>
        </html>
        HTML;

        DB::table('notif_plantillas')->updateOrInsert(
            ['codigo' => 'FICHA_TECNICA_CAMBIO_ESTADO'],
            [
                'nombre'      => 'Ficha Técnica — Cambio de estado',
                'descripcion' => 'Notificación enviada en autorización, aprobación y rechazo de fichas técnicas. '
                    .'Variables: consecutivo, estado, agremiacion, especialidad, empresa, valor, '
                    .'fecha_ini, fecha_fin, generador, observacion, fecha_evento.',
                'contenido'   => $contenido,
                'estado'      => true,
                'updated_at'  => now(),
                'created_at'  => now(),
            ]
        );

        $this->command?->info('✓ Plantilla de correo FICHA_TECNICA_CAMBIO_ESTADO');
    }
}
