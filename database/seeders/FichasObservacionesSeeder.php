<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Siembra las observaciones parametrizables por ítem del módulo Fichas Técnicas
 * y su relación N:M con los tipos de servicio (códigos 1-8 del legacy).
 *
 * Tablas:
 *   - fich_obs_items (93 filas): observaciones parametrizables (descripcion + estado).
 *   - fich_obs_servicio_detalle (87 filas): relación id_obs_item -> id_tipo_servicio.
 *
 * La relación replica el filtro legacy: cada observación aplica a uno o varios
 * tipos de servicio (id_tipo_servicio 1-8), de modo que al elegir el tipo de
 * liquidación/servicio en el paso 2 se listan solo las observaciones pertinentes.
 *
 * Seguro de re-ejecutar: insertOrIgnore no duplica registros ni sobrescribe.
 *
 * Ejecutar:
 *   php artisan db:seed --class=FichasObservacionesSeeder
 */
class FichasObservacionesSeeder extends Seeder
{
    public function run(): void
    {
        $this->sembrarItems();
        $this->sembrarRelacionServicios();

        $this->command?->info('✓ Observaciones por ítem sembradas (93 ítems, 87 relaciones).');
    }

    // ── 93 observaciones parametrizables (IDs originales para preservar FK) ──
    private function sembrarItems(): void
    {
        $rows = [
            ['id' => 1, 'descripcion' => 'PACIENTE EFECTIVO VISTO', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'descripcion' => 'REVISTA HOSPITALARIA EN INTERNACIÓN Y URGENCIAS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'descripcion' => 'SE RECONOCERÁ SI ES OFERTADO POR PAQUETE A LAS EAPB COTIZADO Y AUTORIZADO', 'estado' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'descripcion' => 'VALOR POR HORA SEGÚN CUADRO DE TURNOS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'descripcion' => 'APLICA PARA PROCEDIMIENTOS DE ARTROSCOPIA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'descripcion' => 'APLICA PARA PROCEDIMIENTOS POR VÍA LAPAROSCÓPICA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 7, 'descripcion' => 'APLICA PARA REEMPLAZOS ARTICULARES Y REVISIÓN', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 8, 'descripcion' => 'TARIFA BASE', 'estado' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 9, 'descripcion' => 'APLICA POR HORA EN UNIDAD DE CUIDADOS INTENSIVOS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 10, 'descripcion' => 'APLICA POR HORA EN URGENCIAS, INTERNACIÓN Y QUIRÓFANO', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 11, 'descripcion' => 'APLICA POR HORA EN UCI PEDIÁTRICO SEGÚN CUADRO DE TURNOS MES', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 12, 'descripcion' => 'APLICA POR HORA EN INTERNACIÓN Y URGENCIAS PEDIÁTRICA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 13, 'descripcion' => 'APLICA PARA UCI DE LUNES A VIERNES JORNADA DIURNA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 14, 'descripcion' => 'APLICA PARA UCI DE FINES DE SEMANA, FESTIVOS Y JORNADA NOCTURNA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 15, 'descripcion' => 'APLICA PARA PROCEDIMIENTOS ESPECIALES SEGÚN ART 62 MANUAL ISS', 'estado' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 16, 'descripcion' => 'APLICA PARA TODAS LAS ENTIDADES EXCEPTO ACCIDENTES DE TRÁNSITO', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 17, 'descripcion' => 'APLICA SOLO PARA ACCIDENTES DE TRÁNSITO', 'estado' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 18, 'descripcion' => 'APLICA PARA TECHO PRESUPUESTAL O PGP', 'estado' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 19, 'descripcion' => 'MONTO FIJO APLICA PARA SÁBADOS, DOMINGOS Y FESTIVOS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 20, 'descripcion' => 'APLICA POR ESPECIALISTA PRINCIPAL VÍAS DE ACCESO SEGÚN ACUERDO 256', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 21, 'descripcion' => 'APLICA POR AYUDANTE ESPECIALISTA VÍAS DE ACCESO SEGÚN ART 63 ACUERDO 256', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 22, 'descripcion' => 'MONTO FIJO', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 23, 'descripcion' => 'APLICA PARA CIRUGÍA ONCOLÓGICA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 24, 'descripcion' => 'APLICA SI SE REALIZA COMO CIRUJANO PRINCIPAL', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 25, 'descripcion' => 'APLICA SI SE REALIZA COMO SEGUNDO CIRUJANO', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 26, 'descripcion' => 'TARIFA INSTITUCIONAL', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 27, 'descripcion' => 'CADA LECTURA DE RX CONVENCIONAL', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 28, 'descripcion' => 'CADA LECTURA DE TOMOGRAFÍA SIMPLE Y CONTRASTADA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 29, 'descripcion' => 'CADA LECTURA DE UROGRAFÍA Y ANGIOTMOGRAFÍA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 30, 'descripcion' => 'CADA LECTURA DE RESONANCIA SIMPLE Y CONTRASTADA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 31, 'descripcion' => 'CADA LECTURA DE ANGIORRESONANCIA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 32, 'descripcion' => 'APLICA POR HORA EN UCI NEONATAL SEGÚN CUADRO DE TURNOS MES', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 33, 'descripcion' => '* VER OBSERVACIONES GENERALES', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 34, 'descripcion' => 'APLICA POR HORA EN UCI ADULTOS SEGÚN CUADRO DE TURNOS MES', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 35, 'descripcion' => 'PORCENTAJE DEL VALOR FACTURADO', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 36, 'descripcion' => 'SEGUN CUADRO DE TURNOS EN LAS DIFERENTES UNIDADES FUNCIONALES INCLUYE TOMAS DE ECOGRAFIAS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 37, 'descripcion' => 'TRES PACIENTES EFECTIVOS VISTOS DE P.O.P.', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 38, 'descripcion' => 'APLICA POR HORA EN UNIDAD DE CUIDADOS INTERMEDIOS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 39, 'descripcion' => 'APLICA DE LUNES A DOMINGO JORNADA DIURNA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 40, 'descripcion' => 'APLICA DE LUNES A DOMINGO JORNADA NOCTURNA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 41, 'descripcion' => 'APLICA PARA PROCEDIMIENTOS EN SALAS DE HEMODINAMIA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 42, 'descripcion' => 'APLICA PARA PROCEDIMIENTOS EN SALAS DE CIRUGIA', 'estado' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 43, 'descripcion' => 'APLICA PARA NEFROLITOTOMIA ENDOSCOPICA Y NEFRECTOMIA PERCUTANEAS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 44, 'descripcion' => 'SE RECONOCERAN LOS EVENTOS SOLO PARA LAS CIRUGIAS PROGRAMADAS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 45, 'descripcion' => 'APLICA SOLO INTERPRETACIÓN', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 46, 'descripcion' => 'APLICA PARA TOMA E INTERPRETACIÓN', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 47, 'descripcion' => 'TRES PACIENTES EFECTIVOS VISTOS POR HORA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 48, 'descripcion' => 'APLICA DE LUNES A DOMINGO 24HORAS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 49, 'descripcion' => 'APLICA DE LUNES A DOMINGO JORNADA TARDE-NOCHE', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 50, 'descripcion' => 'CADA LECTURA DE DOPPLER', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 51, 'descripcion' => 'CADA LECTURA DE ECOGRAFIA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 52, 'descripcion' => 'APLICA PARA TOMA DE ECOGRAFIAS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 53, 'descripcion' => 'APLICA DE LUNES A VIERNES', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 54, 'descripcion' => 'APLICA PARA SÁBADOS, DOMINGOS Y FESTIVOS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 55, 'descripcion' => 'APLICA SOBRE DERECHOS DE SALA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 56, 'descripcion' => 'APLICA PARA INTERVENCIÓN BILATERAL', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 57, 'descripcion' => 'APLICA PARA INTERVENCIÓN UNILATERAL', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 58, 'descripcion' => 'APLICA SOLO CUANDO ES RECONOCIDO PREVIAMENTE POR LA EAPB O ERP', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 59, 'descripcion' => 'APLICA PARA CIRUGIA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 60, 'descripcion' => 'APLICA PARA HOSP, URGENCIAS Y CIRUGIA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 61, 'descripcion' => 'APLICA PARA HOSPITALIZACION', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 62, 'descripcion' => 'APLICA PARA HOSPITALIZACION Y URGENCIAS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 63, 'descripcion' => 'APLICA PARA UCI ADULTOS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 64, 'descripcion' => 'APLICA PARA UCI INTERMEDIOS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 65, 'descripcion' => 'APLICA PARA UCI NEONATAL', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 66, 'descripcion' => 'APLICA PARA UCI PEDIATRICA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 67, 'descripcion' => 'APLICA PARA URGENCIAS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 68, 'descripcion' => 'TARIFA INTEGRAL', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 69, 'descripcion' => 'TARIFA GENERAL', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 70, 'descripcion' => 'APLICA A CONTRATOS DE MONTOS FIJO (PGP, TECHOS, CAPITA, ETC)', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 71, 'descripcion' => 'APLICA A CADA LECTURA DE ANGIORRESONANCIA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 72, 'descripcion' => 'APLICA A CADA LECTURA DE DOPPLER', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 73, 'descripcion' => 'APLICA A CADA LECTURA DE ECOGRAFIA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 74, 'descripcion' => 'APLICA A CADA LECTURA DE RESONANCIA SIMPLE Y CONTRASTADA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 75, 'descripcion' => 'APLICA A CADA LECTURA DE RX', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 76, 'descripcion' => 'APLICA A CADA LECTURA DE TOMOGRAFÍA SIMPLE Y CONTRASTADA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 77, 'descripcion' => 'APLICA A CADA LECTURA DE UROGRAFÍA Y ANGIOTMOGRAFÍA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 78, 'descripcion' => 'APLICA A CADA TOMA DE ECOGRAFIA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 79, 'descripcion' => 'APLICA A CIRUGIAS PROGRAMADAS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 80, 'descripcion' => 'APLICA A CIRUJANO SECUNDARIO', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 81, 'descripcion' => 'APLICA A INTERPRETACION', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 82, 'descripcion' => 'APLICA A PROCEDIMIENTOS EN SALA QUIRURGICA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 83, 'descripcion' => 'APLICA A PROCEDIMIENTOS LAPAROSCOPICOS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 84, 'descripcion' => 'APLICA AL ESPECIALISTA AYUDANTE VÍA DE ACCESO SEGÚN MANUAL ISS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 85, 'descripcion' => 'APLICA PARA ACCIDENTES DE TRÁNSITO, NO ADRES', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 86, 'descripcion' => 'APLICA AL ESPECIALISTA PRINCIPAL VÍA DE ACCESO SEGÚN MANUAL ISS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 87, 'descripcion' => 'APLICA A REINTERVENCIONES', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 88, 'descripcion' => 'APLICA CUANDO SUPERA EL MONTO FIJO', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 89, 'descripcion' => 'TARIFA GENERAL BASE', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 91, 'descripcion' => 'APLICA SEGÚN CUADRO DE TURNOS', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 92, 'descripcion' => 'APLICA PARA PROCEDIMIENTOS EN SALAS DE ELECTROFISIOLOGIA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 93, 'descripcion' => 'APLICA PARA PROCEDIMIENTOS EN SALAS DE CIRUGIA CARDIOVASCULAR PERIFERICA', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 94, 'descripcion' => 'APLICA PARA PROCEDIMIENTOS EN SALAS DE CIRUGIA VASCULAR Y NEUROINTERVENCIONISMO', 'estado' => 1, 'created_at' => now(), 'updated_at' => now()],
        ];

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('fich_obs_items')->insertOrIgnore($chunk);
        }
    }

    // ── 87 relaciones observación <-> tipo de servicio (código 1-8) ──
    private function sembrarRelacionServicios(): void
    {
        $rows = [
            ['id' => 1, 'id_obs_item' => 59, 'id_tipo_servicio' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'id_obs_item' => 60, 'id_tipo_servicio' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'id_obs_item' => 61, 'id_tipo_servicio' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 4, 'id_obs_item' => 62, 'id_tipo_servicio' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 5, 'id_obs_item' => 63, 'id_tipo_servicio' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 6, 'id_obs_item' => 64, 'id_tipo_servicio' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 7, 'id_obs_item' => 65, 'id_tipo_servicio' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 8, 'id_obs_item' => 66, 'id_tipo_servicio' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 9, 'id_obs_item' => 67, 'id_tipo_servicio' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 10, 'id_obs_item' => 68, 'id_tipo_servicio' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 11, 'id_obs_item' => 37, 'id_tipo_servicio' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 12, 'id_obs_item' => 47, 'id_tipo_servicio' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 13, 'id_obs_item' => 69, 'id_tipo_servicio' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 15, 'id_obs_item' => 55, 'id_tipo_servicio' => 5, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 16, 'id_obs_item' => 70, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 17, 'id_obs_item' => 71, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 18, 'id_obs_item' => 72, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 19, 'id_obs_item' => 73, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 20, 'id_obs_item' => 74, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 21, 'id_obs_item' => 75, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 22, 'id_obs_item' => 76, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 23, 'id_obs_item' => 77, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 24, 'id_obs_item' => 78, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 25, 'id_obs_item' => 23, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 26, 'id_obs_item' => 79, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 27, 'id_obs_item' => 24, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 28, 'id_obs_item' => 80, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 29, 'id_obs_item' => 81, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 30, 'id_obs_item' => 43, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 31, 'id_obs_item' => 5, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 32, 'id_obs_item' => 41, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 33, 'id_obs_item' => 82, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 34, 'id_obs_item' => 83, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 35, 'id_obs_item' => 7, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 36, 'id_obs_item' => 16, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 37, 'id_obs_item' => 46, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 38, 'id_obs_item' => 84, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 39, 'id_obs_item' => 86, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 40, 'id_obs_item' => 85, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 41, 'id_obs_item' => 56, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 42, 'id_obs_item' => 57, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 43, 'id_obs_item' => 55, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 44, 'id_obs_item' => 87, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 45, 'id_obs_item' => 88, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 46, 'id_obs_item' => 58, 'id_tipo_servicio' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 47, 'id_obs_item' => 70, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 48, 'id_obs_item' => 71, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 49, 'id_obs_item' => 72, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 50, 'id_obs_item' => 73, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 51, 'id_obs_item' => 74, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 52, 'id_obs_item' => 75, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 53, 'id_obs_item' => 76, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 54, 'id_obs_item' => 77, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 55, 'id_obs_item' => 78, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 56, 'id_obs_item' => 23, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 57, 'id_obs_item' => 79, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 58, 'id_obs_item' => 24, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 59, 'id_obs_item' => 80, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 60, 'id_obs_item' => 81, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 61, 'id_obs_item' => 43, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 62, 'id_obs_item' => 5, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 63, 'id_obs_item' => 41, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 64, 'id_obs_item' => 82, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 65, 'id_obs_item' => 83, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 66, 'id_obs_item' => 7, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 67, 'id_obs_item' => 16, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 68, 'id_obs_item' => 46, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 69, 'id_obs_item' => 84, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 70, 'id_obs_item' => 86, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 71, 'id_obs_item' => 85, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 72, 'id_obs_item' => 56, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 73, 'id_obs_item' => 57, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 74, 'id_obs_item' => 55, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 75, 'id_obs_item' => 87, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 76, 'id_obs_item' => 88, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 77, 'id_obs_item' => 58, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 78, 'id_obs_item' => 89, 'id_tipo_servicio' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 79, 'id_obs_item' => 89, 'id_tipo_servicio' => 6, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 80, 'id_obs_item' => 70, 'id_tipo_servicio' => 6, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 81, 'id_obs_item' => 5, 'id_tipo_servicio' => 6, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 82, 'id_obs_item' => 7, 'id_tipo_servicio' => 6, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 83, 'id_obs_item' => 85, 'id_tipo_servicio' => 6, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 84, 'id_obs_item' => 16, 'id_tipo_servicio' => 6, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 85, 'id_obs_item' => 41, 'id_tipo_servicio' => 6, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 86, 'id_obs_item' => 89, 'id_tipo_servicio' => 7, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 87, 'id_obs_item' => 89, 'id_tipo_servicio' => 8, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 88, 'id_obs_item' => 87, 'id_tipo_servicio' => 6, 'created_at' => now(), 'updated_at' => now()],
        ];

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('fich_obs_servicio_detalle')->insertOrIgnore($chunk);
        }
    }
}