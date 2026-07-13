<?php

namespace App\Services\Fabric;

use App\Models\BiGrupo;
use App\Models\BiVistaAccessLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class BiVistaAuditService
{
    public function log(
        ?User $user,
        string $schema,
        string $view,
        string $accion,
        Request $request,
        array $extra = []
    ): void {
        if (!Schema::hasTable('bi_vista_access_logs')) {
            return;
        }

        $empresaId = null;
        $empresaNombre = null;

        if ($user) {
            $empresa = $user->empresas()->orderBy('ent_empresas.nombre')->first(['ent_empresas.id', 'ent_empresas.nombre']);
            if ($empresa) {
                $empresaId = (int) $empresa->id;
                $empresaNombre = $empresa->nombre;
            }
        }

        BiVistaAccessLog::create([
            'user_id'        => $user?->id,
            'user_email'     => $user?->email,
            'user_name'      => $user?->name,
            'empresa_id'     => $empresaId,
            'empresa_nombre' => $empresaNombre,
            'schema_name'    => strtolower($schema),
            'view_name'      => $view,
            'accion'         => $accion,
            'filters'        => $extra['filters'] ?? null,
            'rows_returned'  => (int) ($extra['rows_returned'] ?? 0),
            'elapsed_ms'     => (int) ($extra['elapsed_ms'] ?? 0),
            'success'        => (bool) ($extra['success'] ?? true),
            'ip_address'     => $request->ip(),
            'user_agent'     => substr((string) $request->userAgent(), 0, 500),
            'metadata'       => $extra['metadata'] ?? null,
            'accessed_at'    => now(),
        ]);
    }

    /**
     * @return array{items: \Illuminate\Support\Collection, total: int}
     */
    public function buscar(array $filters): array
    {
        if (!Schema::hasTable('bi_vista_access_logs')) {
            return ['items' => collect(), 'total' => 0];
        }

        $query = BiVistaAccessLog::query()->orderByDesc('accessed_at');

        if (!empty($filters['fecha_desde'])) {
            $query->where('accessed_at', '>=', $filters['fecha_desde'] . ' 00:00:00');
        }

        if (!empty($filters['fecha_hasta'])) {
            $query->where('accessed_at', '<=', $filters['fecha_hasta'] . ' 23:59:59');
        }

        if (!empty($filters['empresa_id'])) {
            $empresaId = (int) $filters['empresa_id'];
            $query->where(function ($q) use ($empresaId) {
                $q->where('empresa_id', $empresaId)
                    ->orWhereHas('user.empresas', fn ($eq) => $eq->where('ent_empresas.id', $empresaId));
            });
        }

        if (!empty($filters['schema'])) {
            $query->where('schema_name', strtolower($filters['schema']));
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (!empty($filters['accion'])) {
            $query->where('accion', $filters['accion']);
        }

        if (!empty($filters['view'])) {
            $term = '%' . $filters['view'] . '%';
            $query->where('view_name', 'like', $term);
        }

        $limit = min((int) ($filters['limit'] ?? 500), 2000);
        $total = (clone $query)->count();
        $items = $query->limit($limit)->get();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array<int, array{schema: string, codigo: string, nombre: string}>
     */
    public function listarEsquemas(?int $empresaId = null): array
    {
        if (!Schema::hasTable('bi_grupos')) {
            return [];
        }

        $query = BiGrupo::query()->orderBy('codigo');
        if ($empresaId) {
            $query->where('empresa_id', $empresaId);
        }

        return $query->get(['codigo', 'descripcion', 'tipo'])
            ->map(function (BiGrupo $grupo) {
                $codigo = strtoupper(trim($grupo->codigo));
                $schema = str_starts_with($codigo, 'GG-BD-')
                    ? strtolower(substr($codigo, 6))
                    : strtolower($codigo);

                return [
                    'schema'  => $schema,
                    'codigo'  => $grupo->codigo,
                    'nombre'  => $grupo->descripcion ?? strtoupper($schema),
                ];
            })
            ->unique('schema')
            ->values()
            ->all();
    }
}
