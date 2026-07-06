<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Notificaciones\NotifPlantilla;

class NotifPlantillasSeeder extends Seeder
{
    public function run(): void
    {
        // =====================================================================
        // PLANTILLA: INTERCONSULTA_SOLICITUD
        // =====================================================================
        NotifPlantilla::updateOrCreate(
            ['codigo' => 'INTERCONSULTA_SOLICITUD'],
            [
                'nombre'      => 'Notificación de Nueva Interconsulta',
                'descripcion' => 'Email enviado al profesional cuando se genera una nueva interconsulta.',
                'estado'      => true,
                'contenido'   => $this->getPlantillaSolicitud(),
            ]
        );

        // =====================================================================
        // PLANTILLA: INTERCONSULTA_ANULACION
        // =====================================================================
        NotifPlantilla::updateOrCreate(
            ['codigo' => 'INTERCONSULTA_ANULACION'],
            [
                'nombre'      => 'Notificación de Anulación de Interconsulta',
                'descripcion' => 'Email enviado al profesional cuando se anula una interconsulta previamente solicitada.',
                'estado'      => true,
                'contenido'   => $this->getPlantillaAnulacion(),
            ]
        );
    }

    private function getPlantillaSolicitud(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #212529; background-color: #f4f4f4; margin: 0; padding: 0;">
<div style="max-width: 800px; margin: 20px auto; background-color: #ffffff; border: 1px solid #e0e0e0;">
    <div style="background-color: #f8f9fa; padding: 20px 30px; border-bottom: 3px solid #0d6efd; text-align: center;">
        <img src="https://ticketprocess.medilaser.com.co/assets/images/Logo-Medilaser-grande.png" alt="Clinica Medilaser" style="height: 45px; width: auto;">
        <div style="font-size: 18px; font-weight: 700; margin: 10px 0;">Sistema de Interconsultas</div>
        <span style="display: inline-block; background-color: #0d6efd; color: #fff; padding: 8px 16px; border-radius: 6px; font-weight: 600; font-size: 11px;">📋 NUEVA SOLICITUD</span>
    </div>
    <div style="padding: 30px 35px;">
        <p style="font-size: 16px; font-weight: 600;">Estimado/a Dr(a). {{profesional}},</p>
        <p>Se le ha asignado una <strong style="color: #0d6efd;">nueva interconsulta</strong>:</p>
        <div style="background-color: #f8f9fa; border: 3px solid #0d6efd; border-radius: 10px; padding: 25px; margin: 20px 0;">
            <div style="color: #0d6efd; font-size: 16px; font-weight: 700; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 3px solid #0d6efd;">✅ NUEVA INTERCONSULTA: {{especialidad}}</div>
            <table style="width: 100%; border-collapse: collapse;">
                <tr><td style="padding: 8px 0; font-weight: 600; width: 160px; color: #495057;">Paciente:</td><td>{{paciente}}</td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Identificación:</td><td>{{identificacion}}</td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Clínica:</td><td>{{clinica}}</td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Unidad Funcional:</td><td>{{unidad_funcional}}</td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Cama:</td><td>{{cama}}</td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Orden:</td><td>{{orden}}</td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Diagnóstico:</td><td>{{diagnostico}}</td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Fecha y Hora:</td><td><strong>{{fecha_orden}}</strong></td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Folio:</td><td>{{folio}}</td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Estado:</td><td><strong>{{estado_orden}}</strong></td></tr>
            </table>
        </div>
        {{observaciones_html}}
        <div style="background: #d1e7dd; border: 2px solid #198754; color: #0f5132; padding: 16px; border-radius: 8px; margin: 20px 0; text-align: center; font-size: 14px; font-weight: 600;">✅ Por favor, revise la interconsulta en el sistema hospitalario lo antes posible.</div>
    </div>
    <div style="background-color: #f8f9fa; padding: 20px; text-align: center; border-top: 3px solid #dee2e6;">
        <img src="https://ticketprocess.medilaser.com.co/assets/images/Logo-Medilaser-grande.png" alt="Clinica Medilaser" style="height: 35px;">
        <p style="color: #6c757d; font-size: 12px; margin: 6px 0;"><strong>Sistema de Notificaciones - Clínica Medilaser</strong></p>
        <p style="color: #6c757d; font-size: 11px; margin: 4px 0;">Este es un mensaje automático, por favor no responda a este correo.</p>
        <p style="font-size: 10px; color: #adb5bd;">Generado: {{fecha_generado}}</p>
    </div>
</div>
</body>
</html>
HTML;
    }

    private function getPlantillaAnulacion(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #212529; background-color: #f4f4f4; margin: 0; padding: 0;">
<div style="max-width: 800px; margin: 20px auto; background-color: #ffffff; border: 1px solid #e0e0e0;">
    <div style="background-color: #f8f9fa; padding: 20px 30px; border-bottom: 3px solid #dc3545; text-align: center;">
        <img src="https://ticketprocess.medilaser.com.co/assets/images/Logo-Medilaser-grande.png" alt="Clinica Medilaser" style="height: 45px; width: auto;">
        <div style="font-size: 18px; font-weight: 700; margin: 10px 0;">Sistema de Interconsultas</div>
        <span style="display: inline-block; background-color: #dc3545; color: #fff; padding: 8px 16px; border-radius: 6px; font-weight: 600; font-size: 11px;">⚠️ ANULACION</span>
    </div>
    <div style="padding: 30px 35px;">
        <p style="font-size: 16px; font-weight: 600;">Estimado/a Dr(a). {{profesional}},</p>
        <p>Se le informa que la siguiente interconsulta ha sido <strong style="color: #dc3545;">ANULADA</strong>:</p>
        <div style="background-color: #f8f9fa; border: 3px solid #dc3545; border-radius: 10px; padding: 25px; margin: 20px 0;">
            <div style="color: #dc3545; font-size: 16px; font-weight: 700; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 3px solid #dc3545;">⚠️ INTERCONSULTA ANULADA: {{especialidad}}</div>
            <table style="width: 100%; border-collapse: collapse;">
                <tr><td style="padding: 8px 0; font-weight: 600; width: 160px; color: #495057;">Paciente:</td><td>{{paciente}}</td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Identificación:</td><td>{{identificacion}}</td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Clínica:</td><td>{{clinica}}</td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Unidad Funcional:</td><td>{{unidad_funcional}}</td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Cama:</td><td>{{cama}}</td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Orden:</td><td>{{orden}}</td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Diagnóstico:</td><td>{{diagnostico}}</td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Fecha y Hora:</td><td><strong>{{fecha_orden}}</strong></td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Folio:</td><td>{{folio}}</td></tr>
                <tr><td style="padding: 8px 0; font-weight: 600; color: #495057;">Estado:</td><td><strong>{{estado_orden}}</strong></td></tr>
            </table>
        </div>
        {{observaciones_html}}
        <div style="background: #fff3cd; border: 2px solid #ffc107; color: #856404; padding: 16px; border-radius: 8px; margin: 20px 0; text-align: center; font-size: 14px; font-weight: 600;">⚠️ Esta interconsulta ha sido ANULADA. No requiere acción de su parte.</div>
    </div>
    <div style="background-color: #f8f9fa; padding: 20px; text-align: center; border-top: 3px solid #dee2e6;">
        <img src="https://ticketprocess.medilaser.com.co/assets/images/Logo-Medilaser-grande.png" alt="Clinica Medilaser" style="height: 35px;">
        <p style="color: #6c757d; font-size: 12px; margin: 6px 0;"><strong>Sistema de Notificaciones - Clínica Medilaser</strong></p>
        <p style="color: #6c757d; font-size: 11px; margin: 4px 0;">Este es un mensaje automático, por favor no responda a este correo.</p>
        <p style="font-size: 10px; color: #adb5bd;">Generado: {{fecha_generado}}</p>
    </div>
</div>
</body>
</html>
HTML;
    }
}
