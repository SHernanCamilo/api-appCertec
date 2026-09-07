<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeder del catálogo de conocimiento del ChatBot.
 *
 * Cada registro describe una vista de Fabric que el bot puede consultar.
 * El bot SOLO puede consultar vistas que estén en esta tabla.
 *
 * Para agregar una nueva vista al bot:
 *   1. Inserta un registro en chatbot_knowledge_views
 *   2. El bot automáticamente podrá consultar esa vista
 *   3. Asegúrate de describir bien las columnas y filtros
 *
 * Para "entrenar" mejor al bot sobre una vista:
 *   - Mejora la descripción
 *   - Agrega más ejemplo_preguntas
 *   - Agrega notas_negocio con reglas específicas
 */
class ChatBotKnowledgeSeeder extends Seeder
{
    public function run(): void
    {
        $vistas = [
            // ═══════════════════════════════════════════════════════════════
            // ESQUEMA: dc (Datos Clínicos / Historia Clínica)
            // ═══════════════════════════════════════════════════════════════
            [
                'schema_name'      => 'dc',
                'view_name'        => 'VW_HC_Egresos_Conteo',
                'descripcion'      => 'Conteo de egresos hospitalarios (altas médicas). Muestra cuántos pacientes egresaron por sede y periodo.',
                'columnas_clave'   => json_encode([
                    'Sede' => 'Nombre de la sede/clínica',
                    'Periodo' => 'Periodo en formato YYYY-MM',
                    'TotalEgresos' => 'Número total de egresos',
                    'TipoEgreso' => 'Tipo de egreso (alta médica, remisión, fallecimiento, etc.)',
                ]),
                'ejemplo_preguntas' => json_encode([
                    '¿Cuántos egresos hubo en julio?',
                    '¿Cuál sede tuvo más altas médicas?',
                    '¿Cuántos pacientes egresaron de la sede Bogotá?',
                    '¿Comparar egresos entre sedes?',
                ]),
                'filtros_sugeridos' => json_encode([
                    'Sede' => 'Nombre de la sede (ej: CMI, EAL, FLA)',
                    'Periodo' => 'Formato YYYY-MM',
                    'TipoEgreso' => 'Tipo de egreso',
                ]),
                'notas_negocio'    => 'Un egreso es cuando un paciente sale del hospital. Puede ser por alta médica, remisión a otra institución, o fallecimiento. No confundir con consulta ambulatoria.',
                'grupo_requerido'  => 'GG-BD-DC',
                'activo'           => true,
            ],

            // ═══════════════════════════════════════════════════════════════
            // ESQUEMA: in (Inventario)
            // ═══════════════════════════════════════════════════════════════
            [
                'schema_name'      => 'in',
                'view_name'        => 'VW_perfilmedicamentos',
                'descripcion'      => 'Perfil de medicamentos por sede. Muestra el catálogo de medicamentos disponibles con sus existencias y consumos.',
                'columnas_clave'   => json_encode([
                    'CodigoProducto' => 'Código del medicamento',
                    'NombreProducto' => 'Nombre del medicamento',
                    'Sede' => 'Sede donde se encuentra',
                    'Existencia' => 'Cantidad disponible actual',
                    'ConsumoPromedio' => 'Consumo promedio mensual',
                    'DiasInventario' => 'Días de inventario disponibles',
                ]),
                'ejemplo_preguntas' => json_encode([
                    '¿Cuánto inventario hay de un medicamento?',
                    '¿Qué medicamentos tienen bajo stock?',
                    '¿Cuál es el consumo promedio de X medicamento?',
                    '¿Cuántos días de inventario quedan?',
                ]),
                'filtros_sugeridos' => json_encode([
                    'NombreProducto' => 'Nombre o parte del nombre del medicamento (soporta %)',
                    'Sede' => 'Código de la sede',
                    'CodigoProducto' => 'Código del producto',
                ]),
                'notas_negocio'    => 'Los días de inventario se calculan como Existencia / ConsumoPromedio. Si DiasInventario < 15 se considera bajo stock. Esta vista es nacional (muestra todas las sedes).',
                'grupo_requerido'  => 'GG-BD-IN',
                'activo'           => true,
            ],

            // ═══════════════════════════════════════════════════════════════
            // ESQUEMA: fr (Facturación)
            // ═══════════════════════════════════════════════════════════════
            [
                'schema_name'      => 'fr',
                'view_name'        => 'VW_Billing_Facturacion_SOAT',
                'descripcion'      => 'Facturación de servicios SOAT (Seguro Obligatorio de Accidentes de Tránsito). Muestra facturación por sede, periodo y aseguradora.',
                'columnas_clave'   => json_encode([
                    'Sede' => 'Sede que generó la factura',
                    'Periodo' => 'Periodo de facturación',
                    'Aseguradora' => 'Empresa aseguradora SOAT',
                    'NumeroFactura' => 'Número de la factura',
                    'ValorFacturado' => 'Valor total facturado',
                    'Estado' => 'Estado de la factura',
                ]),
                'ejemplo_preguntas' => json_encode([
                    '¿Cuánto se facturó en SOAT este mes?',
                    '¿Cuál aseguradora tiene más facturación?',
                    '¿Facturación SOAT por sede?',
                    '¿Cuántas facturas SOAT hay pendientes?',
                ]),
                'filtros_sugeridos' => json_encode([
                    'Sede' => 'Código de la sede',
                    'Periodo' => 'Formato YYYY-MM',
                    'Aseguradora' => 'Nombre de la aseguradora',
                    'Estado' => 'Estado de la factura (Radicada, Pendiente, Pagada, etc.)',
                ]),
                'notas_negocio'    => 'SOAT = Seguro Obligatorio de Accidentes de Tránsito. Es un tipo específico de facturación para pacientes de accidentes vehiculares. Las facturas pueden estar en estado: Radicada, Pendiente, Glosada, Pagada.',
                'grupo_requerido'  => 'GG-BD-FR',
                'activo'           => true,
            ],
        ];

        foreach ($vistas as $vista) {
            DB::table('chatbot_knowledge_views')->updateOrInsert(
                [
                    'schema_name' => $vista['schema_name'],
                    'view_name'   => $vista['view_name'],
                ],
                array_merge($vista, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        $this->command->info('ChatBot: ' . count($vistas) . ' vistas registradas en el catálogo de conocimiento.');
    }
}
