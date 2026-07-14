<?php

namespace App\Services\Fabric;

use App\Models\BiGrupo;
use App\Models\BiVista;
use App\Models\BiVistaDelegacion;
use App\Models\BiVistaDelegacionUsuario;
use App\Models\User;
use App\Models\UserGrup;
use Illuminate\Support\Facades\Schema;

class BiUsuarioPermisosService
{
    public function __construct(
        private GraphFabricGatewayService $gateway
    ) {}

    /**
     * Resumen de permisos BI de un usuario para auditoría administrativa.
     */
    public function getPermisos(User $user, ?int $empresaContextId = null): array
    {
        $catalogo = $this->gateway->getCatalogoGrupos();
        $empresas = $user->empresas()->get(['ent_empresas.id', 'ent_empresas.nombre']);
        $empresaIds = $empresas->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($empresaContextId !== null && !in_array($empresaContextId, $empresaIds, true)) {
            throw new \InvalidArgumentException('El usuario no pertenece a la empresa seleccionada.');
        }

        $gruposDirectos = $this->buildGruposDirectos($user, $catalogo);
        $esquemasCatalogo = $this->gateway->getEsquemasCatalogoUsuario($user);
        $delegacionesEmpresa = $this->buildDelegacionesEmpresa($empresas, $empresaContextId);
        $delegacionesUsuario = $this->buildDelegacionesUsuario($user, $empresaContextId);
        $usersGrups = UserGrup::where('id_user', $user->id)
            ->orderBy('tipo')
            ->orderBy('permiso')
            ->get(['tipo', 'permiso', 'origen']);

        return [
            'usuario' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'cargo' => $user->cargo,
            ],
            'empresa_contexto_id' => $empresaContextId,
            'empresas'            => $empresas->map(fn ($e) => [
                'id'     => $e->id,
                'nombre' => $e->nombre,
            ])->values()->all(),
            'departamento'          => $this->gateway->getDepartamento($user),
            'grupos_totales'        => $this->gateway->getGruposBd($user),
            'esquemas_totales'      => $this->gateway->getEsquemasPermitidos($user),
            'grupos_directos'       => $gruposDirectos,
            'esquemas_catalogo'     => $esquemasCatalogo,
            'delegaciones_empresa'  => $delegacionesEmpresa,
            'delegaciones_usuario'  => $delegacionesUsuario,
            'users_grups'           => $usersGrups->map(fn (UserGrup $g) => [
                'tipo'    => $g->tipo,
                'permiso' => $g->permiso,
                'origen'  => $g->origen,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, array{codigo: string, tipo: int, descripcion: ?string}>  $catalogo
     */
    private function buildGruposDirectos(User $user, array $catalogo): array
    {
        return collect($this->gateway->getGruposBdDirectos($user))
            ->map(function (string $grupo) use ($catalogo) {
                $upper  = strtoupper(trim($grupo));
                $meta   = $catalogo[$upper] ?? null;
                $schema = $this->gateway->extractSchema($grupo);

                return [
                    'grupo'       => $grupo,
                    'schema'      => $schema,
                    'tipo'        => $meta['tipo'] ?? null,
                    'descripcion' => $meta['descripcion'] ?? null,
                    'origen'      => 'Azure',
                    'fuente'      => 'directo',
                ];
            })
            ->values()
            ->all();
    }

    private function buildDelegacionesEmpresa($empresas, ?int $empresaContextId): array
    {
        if (!Schema::hasTable('bi_vista_delegaciones')) {
            return [];
        }

        $empresaIds = $empresas->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($empresaContextId !== null) {
            $empresaIds = [$empresaContextId];
        }

        if ($empresaIds === []) {
            return [];
        }

        $rows = BiVistaDelegacion::query()
            ->whereIn('empresa_id', $empresaIds)
            ->with([
                'grupo:id,codigo,tipo,descripcion,empresa_id',
                'grupo.empresa:id,nombre',
                'vista:id,nombre,descripcion,estado',
            ])
            ->get();

        $empresaMap = $empresas->keyBy('id');

        return $rows
            ->groupBy(fn (BiVistaDelegacion $d) => $d->empresa_id . ':' . $d->id_bi_grupos)
            ->map(function ($items) use ($empresaMap) {
                /** @var BiVistaDelegacion $first */
                $first = $items->first();
                $grupo = $first->grupo;
                $codigo = strtoupper(trim($grupo?->codigo ?? ''));
                $grupoGg = str_starts_with($codigo, 'GG-BD-') ? $codigo : 'GG-BD-' . $codigo;

                return [
                    'empresa_receptora_id'   => (int) $first->empresa_id,
                    'empresa_receptora'      => $empresaMap->get($first->empresa_id)?->nombre,
                    'id_bi_grupos'           => (int) $first->id_bi_grupos,
                    'schema'                 => $this->gateway->extractSchema($grupoGg),
                    'grupo'                  => $grupoGg,
                    'tipo'                   => $grupo?->tipo,
                    'descripcion_esquema'    => $grupo?->descripcion,
                    'empresa_propietaria_id' => $grupo?->empresa_id,
                    'empresa_propietaria'    => $grupo?->empresa?->nombre,
                    'es_otra_empresa'        => $grupo?->empresa_id !== null
                        && (int) $grupo->empresa_id !== (int) $first->empresa_id,
                    'vistas'                 => $items->map(fn (BiVistaDelegacion $d) => [
                        'id'          => $d->vista?->id,
                        'nombre'      => $d->vista?->nombre,
                        'descripcion' => $d->vista?->descripcion,
                        'estado'      => $d->vista?->estado,
                    ])->values()->all(),
                    'total_vistas'           => $items->count(),
                ];
            })
            ->values()
            ->sortBy('schema')
            ->values()
            ->all();
    }

    private function buildDelegacionesUsuario(User $user, ?int $empresaContextId): array
    {
        if (!Schema::hasTable('bi_vista_delegacion_usuarios')) {
            return [];
        }

        $query = BiVistaDelegacionUsuario::query()
            ->where('user_id', $user->id)
            ->with([
                'grupo:id,codigo,tipo,descripcion,empresa_id',
                'grupo.empresa:id,nombre',
                'vista:id,nombre,descripcion,estado',
            ]);

        if ($empresaContextId !== null) {
            $query->where('empresa_id', $empresaContextId);
        }

        return $query->get()
            ->groupBy(fn (BiVistaDelegacionUsuario $d) => $d->empresa_id . ':' . $d->id_bi_grupos)
            ->map(function ($items) {
                /** @var BiVistaDelegacionUsuario $first */
                $first = $items->first();
                $grupo = $first->grupo;
                $codigo = strtoupper(trim($grupo?->codigo ?? ''));
                $grupoGg = str_starts_with($codigo, 'GG-BD-') ? $codigo : 'GG-BD-' . $codigo;

                return [
                    'empresa_id'          => (int) $first->empresa_id,
                    'id_bi_grupos'        => (int) $first->id_bi_grupos,
                    'schema'              => $this->gateway->extractSchema($grupoGg),
                    'grupo'               => $grupoGg,
                    'tipo'                => $grupo?->tipo,
                    'descripcion_esquema' => $grupo?->descripcion,
                    'empresa_esquema'     => $grupo?->empresa?->nombre,
                    'vistas'              => $items->map(fn (BiVistaDelegacionUsuario $d) => [
                        'id'          => $d->vista?->id,
                        'nombre'      => $d->vista?->nombre,
                        'descripcion' => $d->vista?->descripcion,
                        'estado'      => $d->vista?->estado,
                    ])->values()->all(),
                    'total_vistas'        => $items->count(),
                ];
            })
            ->values()
            ->sortBy('schema')
            ->values()
            ->all();
    }
}
