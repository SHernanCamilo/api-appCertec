<?php

namespace App\Services\TalentoHumano;

use App\Models\TalentoHumano\EventNovedad;
use App\Models\TalentoHumano\EventNovedadCargo;
use Illuminate\Support\Facades\DB;

class EventNovedadService
{
    // ─── Catálogo event_novedades ─────────────────────────────────────────────

    public function listar(array $filters = [])
    {
        $query = EventNovedad::query();

        if (isset($filters['activo'])) {
            $query->where('activo', $filters['activo']);
        }

        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term) {
                $q->where('codigo', 'like', "%{$term}%")
                  ->orWhere('descripcion', 'like', "%{$term}%");
            });
        }

        return $query->orderBy('codigo')->get();
    }

    public function obtener(int $id): EventNovedad
    {
        return EventNovedad::findOrFail($id);
    }

    public function crear(array $data): EventNovedad
    {
        $data['codigo'] = strtoupper(trim($data['codigo']));
        return EventNovedad::create($data);
    }

    public function actualizar(int $id, array $data): EventNovedad
    {
        $novedad = EventNovedad::findOrFail($id);
        if (isset($data['codigo'])) {
            $data['codigo'] = strtoupper(trim($data['codigo']));
        }
        $novedad->update($data);
        return $novedad->fresh();
    }

    public function eliminar(int $id): void
    {
        $novedad = EventNovedad::findOrFail($id);
        $novedad->delete();
    }

    // ─── Vinculaciones event_novedad_cargo ────────────────────────────────────

    public function listarVinculaciones(array $filters = [])
    {
        $query = EventNovedadCargo::with([
            'novedad',
            'empresa:id,nombre',
            'cargo:id_cargo,nombre_cargo',
        ]);

        if (!empty($filters['novedad_id'])) {
            $query->where('novedad_id', $filters['novedad_id']);
        }
        if (!empty($filters['empresa_id'])) {
            $query->where('empresa_id', $filters['empresa_id']);
        }
        if (!empty($filters['cargo_id'])) {
            $query->where('cargo_id', $filters['cargo_id']);
        }

        return $query->get();
    }

    public function vincular(array $data): EventNovedadCargo
    {
        // Evita duplicados
        return EventNovedadCargo::firstOrCreate(
            [
                'novedad_id'  => $data['novedad_id'],
                'empresa_id'  => $data['empresa_id'] ?? null,
                'cargo_id'    => $data['cargo_id'] ?? null,
            ],
            ['activo' => $data['activo'] ?? true]
        );
    }

    public function desvincular(int $id): void
    {
        EventNovedadCargo::findOrFail($id)->delete();
    }

    /**
     * Retorna las novedades que aplican para una empresa y cargo dados.
     * Incluye novedades globales (empresa_id NULL y cargo_id NULL).
     */
    public function novedadesAplicables(int $empresaId, int $cargoId)
    {
        return EventNovedadCargo::with('novedad')
            ->where(function ($q) use ($empresaId) {
                $q->where('empresa_id', $empresaId)->orWhereNull('empresa_id');
            })
            ->where(function ($q) use ($cargoId) {
                $q->where('cargo_id', $cargoId)->orWhereNull('cargo_id');
            })
            ->where('activo', true)
            ->get()
            ->pluck('novedad')
            ->filter()
            ->values();
    }
}
