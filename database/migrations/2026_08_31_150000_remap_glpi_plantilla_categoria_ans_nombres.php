<?php

declare(strict_types=1);

use App\Models\MesaServicio\GlpiParamPlantilla;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $plantillas = GlpiParamPlantilla::query()->with(['ans', 'categorias'])->get();

        foreach ($plantillas as $plantilla) {
            foreach ($plantilla->categorias as $categoria) {
                $resuelto = GlpiParamPlantilla::resolverNombreAns(
                    $categoria->ans_nombre,
                    $categoria->prioridad,
                    $plantilla->ans
                );
                if (! is_string($resuelto) || $resuelto === '' || $resuelto === $categoria->ans_nombre) {
                    continue;
                }
                $categoria->ans_nombre = $resuelto;
                $categoria->save();
            }
        }
    }

    public function down(): void
    {
        // Reparación de datos; no se revierte.
    }
};
