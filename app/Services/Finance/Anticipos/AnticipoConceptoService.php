<?php

namespace App\Services\Finance\Anticipos;

use App\Models\AntiConcepto;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de Conceptos de Anticipo.
 * Orquesta la lógica de negocio delegando reglas a AnticipoReglaService.
 */
class AnticipoConceptoService
{
    public function __construct(
        private readonly AnticipoReglaService $reglaService
    ) {}

    public function listar(array $filters = []): LengthAwarePaginator
    {
        $query = AntiConcepto::with(['tipo', 'clase', 'modalidad', 'reglas']);

        if (!empty($filters['tipo_id'])) {
            $query->where('id_tipo', $filters['tipo_id']);
        }

        if (!empty($filters['clase_id'])) {
            $query->where('id_clase', $filters['clase_id']);
        }

        if (isset($filters['estado'])) {
            $query->where('estado', $filters['estado']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->whereHas('tipo', fn($s) => $s->where('nombre', 'like', "%{$search}%"))
                  ->orWhereHas('clase', fn($s) => $s->where('nombre', 'like', "%{$search}%"))
                  ->orWhereHas('modalidad', fn($s) => $s->where('nombre', 'like', "%{$search}%"));
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 10), 5), 100);

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function obtener(int $id): AntiConcepto
    {
        return AntiConcepto::with(['tipo', 'clase', 'modalidad', 'reglas'])->findOrFail($id);
    }

    /**
     * Crea un concepto nuevo o agrega reglas si ya existe la combinación tipo/clase/modalidad.
     */
    public function crear(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $existente = AntiConcepto::where('id_tipo', $data['id_tipo'])
                ->where('id_clase', $data['id_clase'])
                ->where('id_modalidad', $data['id_modalidad'])
                ->first();

            if ($existente) {
                $this->reglaService->crearReglas($existente, $data['reglas']);
                $existente->load(['tipo', 'clase', 'modalidad', 'reglas']);
                return ['concepto' => $existente, 'creado' => false];
            }

            $concepto = AntiConcepto::create([
                'id_tipo'      => $data['id_tipo'],
                'id_clase'     => $data['id_clase'],
                'id_modalidad' => $data['id_modalidad'],
                'estado'       => $data['estado'] ?? true,
            ]);

            $this->reglaService->crearReglas($concepto, $data['reglas']);
            $concepto->load(['tipo', 'clase', 'modalidad', 'reglas']);

            return ['concepto' => $concepto, 'creado' => true];
        });
    }

    public function actualizar(int $id, array $data): AntiConcepto
    {
        return DB::transaction(function () use ($id, $data) {
            $concepto = AntiConcepto::findOrFail($id);

            $duplicado = AntiConcepto::where('id_tipo', $data['id_tipo'])
                ->where('id_clase', $data['id_clase'])
                ->where('id_modalidad', $data['id_modalidad'])
                ->where('id', '!=', $id)
                ->exists();

            if ($duplicado) {
                throw new \DomainException('Ya existe otro concepto con esta combinación de Tipo, Clase y Modalidad.');
            }

            $concepto->update([
                'id_tipo'      => $data['id_tipo'],
                'id_clase'     => $data['id_clase'],
                'id_modalidad' => $data['id_modalidad'],
                'estado'       => $data['estado'] ?? $concepto->estado,
            ]);

            $this->reglaService->reemplazarReglas($concepto, $data['reglas']);
            $concepto->load(['tipo', 'clase', 'modalidad', 'reglas']);

            return $concepto;
        });
    }

    public function eliminar(int $id): void
    {
        AntiConcepto::findOrFail($id)->delete();
    }

    public function toggleEstado(int $id): AntiConcepto
    {
        $concepto = AntiConcepto::findOrFail($id);
        $concepto->estado = !$concepto->estado;
        $concepto->save();
        return $concepto;
    }
}
