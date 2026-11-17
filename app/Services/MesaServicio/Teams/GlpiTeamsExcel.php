<?php

declare(strict_types=1);

namespace App\Services\MesaServicio\Teams;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

class GlpiTeamsExcel
{
    /**
     * @param  list<array<string, mixed>>  $tickets
     */
    public function generar(array $tickets): string
    {
        $libro = new Spreadsheet();
        $this->resumen($libro->getActiveSheet(), $tickets);

        $usados = ['resumen' => true];
        foreach ($this->porGrupo($tickets) as $grupo => $filas) {
            $hoja = $libro->createSheet();
            $hoja->setTitle($this->tituloHoja($grupo, $usados));
            $this->detalle($hoja, $filas);
        }

        $temporal = tempnam(sys_get_temp_dir(), 'tic');
        if ($temporal === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal del Excel.');
        }

        try {
            (new Xlsx($libro))->save($temporal);
            $bytes = file_get_contents($temporal);
        } finally {
            @unlink($temporal);
        }

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('No se pudo generar el Excel de casos.');
        }

        return $bytes;
    }

    /**
     * @param  list<array<string, mixed>>  $tickets
     */
    private function resumen(Worksheet $hoja, array $tickets): void
    {
        $hoja->setTitle('Resumen');
        $hoja->fromArray(['Grupo', 'En tiempo', 'Por vencer', 'Vencidos', 'Total'], null, 'A1');
        $fila = 2;
        foreach ($this->porGrupo($tickets) as $grupo => $casos) {
            $conteo = $this->conteo($casos);
            $hoja->fromArray([
                $grupo,
                $conteo['en_tiempo'],
                $conteo['por_vencer'],
                $conteo['vencidos'],
                $conteo['total'],
            ], null, 'A'.$fila);
            $fila++;
        }
        $this->encabezado($hoja, 'A1:E1');
        foreach (range('A', 'E') as $columna) {
            $hoja->getColumnDimension($columna)->setAutoSize(true);
        }
        $hoja->setAutoFilter('A1:E'.max(1, $fila - 1));
    }

    /**
     * @param  list<array<string, mixed>>  $tickets
     */
    private function detalle(Worksheet $hoja, array $tickets): void
    {
        $hoja->fromArray(
            ['Ticket', 'Título', 'Técnico', 'Entidad', 'Prioridad', 'Alerta', 'Tiempo', 'Enlace'],
            null,
            'A1'
        );
        $fila = 2;
        foreach ($tickets as $ticket) {
            $hoja->fromArray([
                (int) ($ticket['id'] ?? 0),
                trim((string) ($ticket['titulo'] ?? 'Sin título')),
                trim((string) ($ticket['tecnico'] ?? '')) ?: 'Sin asignar',
                trim((string) ($ticket['entidad_corta'] ?? $ticket['entidad'] ?? '')),
                trim((string) ($ticket['prioridad'] ?? '')),
                $this->alerta((string) ($ticket['alerta'] ?? '')),
                trim((string) ($ticket['tiempo_texto'] ?? '')),
                trim((string) ($ticket['url'] ?? '')),
            ], null, 'A'.$fila);
            $fila++;
        }
        $this->encabezado($hoja, 'A1:H1');
        foreach (range('A', 'H') as $columna) {
            $hoja->getColumnDimension($columna)->setAutoSize(true);
        }
        $hoja->setAutoFilter('A1:H'.max(1, $fila - 1));
    }

    private function encabezado(Worksheet $hoja, string $rango): void
    {
        $hoja->getStyle($rango)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1A1A2E'],
            ],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);
        $hoja->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
    }

    /**
     * @param  list<array<string, mixed>>  $tickets
     * @return array<string, list<array<string, mixed>>>
     */
    private function porGrupo(array $tickets): array
    {
        $mapa = [];
        foreach ($tickets as $ticket) {
            if (! is_array($ticket)) {
                continue;
            }
            $nombre = trim((string) ($ticket['grupo_actual'] ?? 'Sin grupo'));
            if ($nombre === '') {
                $nombre = 'Sin grupo';
            }
            $nivel = (int) ($ticket['nivel'] ?? 1);
            $clave = ($nivel === 4 ? 'Proveedor' : 'N'.$nivel).' · '.$nombre;
            $mapa[$clave][] = $ticket;
        }

        uksort($mapa, static fn (string $a, string $b): int => strcasecmp($a, $b));

        return $mapa;
    }

    /**
     * @param  list<array<string, mixed>>  $tickets
     * @return array{total: int, en_tiempo: int, por_vencer: int, vencidos: int}
     */
    private function conteo(array $tickets): array
    {
        $conteo = ['total' => 0, 'en_tiempo' => 0, 'por_vencer' => 0, 'vencidos' => 0];
        foreach ($tickets as $ticket) {
            $conteo['total']++;
            $alerta = (string) ($ticket['alerta'] ?? '');
            if ($alerta === 'sin_ans') {
                $alerta = 'por_vencer';
            }
            if (isset($conteo[$alerta === 'vencido' ? 'vencidos' : $alerta])) {
                $conteo[$alerta === 'vencido' ? 'vencidos' : $alerta]++;
            }
        }

        return $conteo;
    }

    private function alerta(string $alerta): string
    {
        return match ($alerta) {
            'vencido' => 'Vencido',
            'por_vencer', 'sin_ans' => 'Por vencer',
            'en_tiempo' => 'En tiempo',
            default => $alerta !== '' ? $alerta : 'Sin estado',
        };
    }

    /**
     * @param  array<string, true>  $usados
     */
    private function tituloHoja(string $nombre, array &$usados): string
    {
        $limpio = preg_replace('/[\\\\\\/\\?\\*\\:\\[\\]]/u', ' ', $nombre) ?? $nombre;
        $limpio = trim((string) preg_replace('/\s+/u', ' ', $limpio));
        if ($limpio === '') {
            $limpio = 'Grupo';
        }

        $base = mb_substr($limpio, 0, 31);
        $titulo = $base;
        $numero = 2;
        while (isset($usados[mb_strtolower($titulo)])) {
            $sufijo = ' '.$numero;
            $titulo = mb_substr($base, 0, 31 - mb_strlen($sufijo)).$sufijo;
            $numero++;
        }
        $usados[mb_strtolower($titulo)] = true;

        return $titulo;
    }
}
