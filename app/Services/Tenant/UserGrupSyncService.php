<?php

namespace App\Services\Tenant;

use App\Models\User;
use App\Models\UserGrup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserGrupSyncService
{
    public function __construct(
        private MicrosoftGraphTenantService $graphTenantService
    ) {}

    /**
     * Sincroniza grupos/departamento desde Azure.
     *
     * Login: solo AGREGA GG-BD-* faltantes. Nunca borra (Graph a menudo trae
     * otros grupos sin los GG-BD-* y eso vaciaba users_grups).
     *
     * Sync explícito (Traer grupos Azure / artisan): puede quitar grupos que
     * Azure ya no tiene, pero NUNCA si Azure devolvió 0 GG-BD-* y el usuario
     * ya tenía asignaciones.
     *
     * @return array{synced: bool, users_grups: array<int, array<string, string>>, error: ?string}
     */
    public function syncFromAzureOnLogin(User $user, bool $allowDelete = false): array
    {
        $tenantData = $this->graphTenantService->fetchUserTenantData($user);

        if (!$tenantData['success']) {
            Log::info('UserGrupSyncService: sync omitido, se conservan datos previos', [
                'user_id' => $user->id,
                'error'   => $tenantData['error'],
            ]);

            return [
                'synced'      => false,
                'users_grups' => $this->formatGrups($this->loadUserGrups($user)),
                'error'       => $tenantData['error'],
            ];
        }

        $stats = ['inserted' => 0, 'deleted' => 0, 'updated' => 0, 'unchanged' => 0];

        try {
            DB::transaction(function () use ($user, $tenantData, $allowDelete, &$stats) {
                $vistaStats = $this->syncVistaBdGrups(
                    $user,
                    $tenantData['grupos_vista_bd'],
                    $allowDelete
                );
                $deptStats  = $this->syncDepartamento($user, $tenantData['department'], $allowDelete);

                foreach (['inserted', 'deleted', 'updated', 'unchanged'] as $key) {
                    $stats[$key] = $vistaStats[$key] + $deptStats[$key];
                }

                if (!empty($tenantData['job_title']) && $user->cargo !== $tenantData['job_title']) {
                    $user->update(['cargo' => $tenantData['job_title']]);
                }
            });
        } catch (\RuntimeException $e) {
            Log::warning('UserGrupSyncService: sync abortado, se conservan datos previos', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return [
                'synced'      => false,
                'users_grups' => $this->formatGrups($this->loadUserGrups($user)),
                'error'       => $e->getMessage(),
            ];
        }

        Log::info('UserGrupSyncService: sync Azure completado', [
            'user_id'      => $user->id,
            'allow_delete' => $allowDelete,
            'inserted'     => $stats['inserted'],
            'deleted'      => $stats['deleted'],
            'updated'      => $stats['updated'],
            'unchanged'    => $stats['unchanged'],
        ]);

        return [
            'synced'      => true,
            'users_grups' => $this->formatGrups($this->loadUserGrups($user)),
            'error'       => null,
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function currentGrups(User $user): array
    {
        return $this->formatGrups($this->loadUserGrups($user));
    }

    /**
     * @param  array<int, string>  $gruposDesdeAzure
     * @return array{inserted: int, deleted: int, updated: int, unchanged: int}
     */
    private function syncVistaBdGrups(User $user, array $gruposDesdeAzure, bool $allowDelete = false): array
    {
        $stats = ['inserted' => 0, 'deleted' => 0, 'updated' => 0, 'unchanged' => 0];

        $gruposDesdeAzure = array_filter(
            $gruposDesdeAzure,
            fn ($g) => str_starts_with(strtoupper(trim($g)), 'GG-BD-')
        );

        $corruptos = UserGrup::where('id_user', $user->id)
            ->where('origen', UserGrup::ORIGEN_AZURE)
            ->where('tipo', UserGrup::TIPO_VISTA_BD)
            ->where('permiso', 'NOT LIKE', 'GG-BD-%')
            ->delete();

        if ($corruptos > 0) {
            Log::warning('UserGrupSyncService: eliminados registros corruptos vista_bd', [
                'user_id'  => $user->id,
                'cantidad' => $corruptos,
            ]);
            $stats['deleted'] += $corruptos;
        }

        $existentes = UserGrup::where('id_user', $user->id)
            ->where('origen', UserGrup::ORIGEN_AZURE)
            ->where('tipo', UserGrup::TIPO_VISTA_BD)
            ->pluck('permiso')
            ->all();

        $nuevos     = array_values(array_unique($gruposDesdeAzure));
        $aEliminar  = array_diff($existentes, $nuevos);
        $aInsertar  = array_diff($nuevos, $existentes);
        $sinCambio  = array_intersect($existentes, $nuevos);

        // Graph suele devolver decenas de grupos (Teams, etc.) sin los GG-BD-*.
        // Tratar eso como "ya no tiene BI" borraba los permisos al rato del login.
        if (!empty($existentes) && empty($nuevos)) {
            throw new \RuntimeException(
                'Azure no devolvió grupos GG-BD-* pero el usuario ya tenía asignaciones; sync abortado para no borrar permisos'
            );
        }

        if ($allowDelete && !empty($aEliminar)) {
            $deleted = UserGrup::where('id_user', $user->id)
                ->where('origen', UserGrup::ORIGEN_AZURE)
                ->where('tipo', UserGrup::TIPO_VISTA_BD)
                ->whereIn('permiso', $aEliminar)
                ->delete();
            $stats['deleted'] += $deleted;
        } elseif (!$allowDelete && !empty($aEliminar)) {
            Log::info('UserGrupSyncService: login aditivo, no se eliminan GG-BD-*', [
                'user_id'    => $user->id,
                'conservados'=> array_values($aEliminar),
            ]);
            $aEliminar = [];
        }

        foreach ($aInsertar as $grupo) {
            UserGrup::firstOrCreate(
                [
                    'id_user'  => $user->id,
                    'tipo'     => UserGrup::TIPO_VISTA_BD,
                    'permiso'  => $grupo,
                    'origen'   => UserGrup::ORIGEN_AZURE,
                ]
            );
            $stats['inserted']++;
        }

        $stats['unchanged'] += count($sinCambio);

        return $stats;
    }

    private function syncDepartamento(User $user, ?string $department, bool $allowDelete = false): array
    {
        $stats = ['inserted' => 0, 'deleted' => 0, 'updated' => 0, 'unchanged' => 0];

        $registro = UserGrup::where('id_user', $user->id)
            ->where('origen', UserGrup::ORIGEN_AZURE)
            ->where('tipo', UserGrup::TIPO_DEPARTAMENTO)
            ->first();

        if (empty($department)) {
            if ($allowDelete && $registro) {
                $registro->delete();
                $stats['deleted']++;
            }
            return $stats;
        }

        if (!$registro) {
            UserGrup::create([
                'id_user'  => $user->id,
                'tipo'     => UserGrup::TIPO_DEPARTAMENTO,
                'permiso'  => $department,
                'origen'   => UserGrup::ORIGEN_AZURE,
            ]);
            $stats['inserted']++;
            return $stats;
        }

        if ($registro->permiso === $department) {
            $stats['unchanged']++;
            return $stats;
        }

        $registro->update(['permiso' => $department]);
        $stats['updated']++;

        return $stats;
    }

    public function formatGrups(Collection $grups): array
    {
        return $grups->map(fn (UserGrup $g) => [
            'tipo'    => $g->tipo,
            'permiso' => $g->permiso,
            'origen'  => $g->origen,
        ])->values()->all();
    }

    private function loadUserGrups(User $user): Collection
    {
        return UserGrup::where('id_user', $user->id)
            ->orderBy('tipo')
            ->orderBy('permiso')
            ->get();
    }
}
