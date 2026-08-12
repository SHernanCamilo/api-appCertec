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
     * Sincroniza grupos/departamento desde Azure (login o "Traer grupos Azure").
     * Solo agrega los que faltan, elimina los que ya no vienen de Azure
     * y actualiza departamento si cambió. No recrea registros existentes.
     *
     * Si Graph falla o memberOf viene vacío con grupos previos, NO borra:
     * conserva users_grups y retorna synced=false + error.
     *
     * @return array{synced: bool, users_grups: array<int, array<string, string>>, error: ?string}
     */
    public function syncFromAzureOnLogin(User $user): array
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
            DB::transaction(function () use ($user, $tenantData, &$stats) {
                $vistaStats = $this->syncVistaBdGrups(
                    $user,
                    $tenantData['grupos_vista_bd'],
                    (int) ($tenantData['member_of_count'] ?? 0)
                );
                $deptStats  = $this->syncDepartamento($user, $tenantData['department']);

                foreach (['inserted', 'deleted', 'updated', 'unchanged'] as $key) {
                    $stats[$key] = $vistaStats[$key] + $deptStats[$key];
                }

                if (!empty($tenantData['job_title']) && $user->cargo !== $tenantData['job_title']) {
                    $user->update(['cargo' => $tenantData['job_title']]);
                }
            });
        } catch (\RuntimeException $e) {
            // Las guardas anti-borrado lanzan RuntimeException: conservar estado previo.
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
            'user_id'  => $user->id,
            'inserted' => $stats['inserted'],
            'deleted'  => $stats['deleted'],
            'updated'  => $stats['updated'],
            'unchanged'=> $stats['unchanged'],
        ]);

        return [
            'synced'      => true,
            'users_grups' => $this->formatGrups($this->loadUserGrups($user)),
            'error'       => null,
        ];
    }

    /**
     * @param  array<int, string>  $gruposDesdeAzure
     * @return array{inserted: int, deleted: int, updated: int, unchanged: int}
     */
    private function syncVistaBdGrups(User $user, array $gruposDesdeAzure, int $memberOfCount = 0): array
    {
        $stats = ['inserted' => 0, 'deleted' => 0, 'updated' => 0, 'unchanged' => 0];

        // PROTECCIÓN: solo aceptar grupos con prefijo GG-BD- (evita corrupción
        // como la que afectó a 354 usuarios con "FLA-SERVICIO MEDICO" en vista_bd)
        $gruposDesdeAzure = array_filter(
            $gruposDesdeAzure,
            fn ($g) => str_starts_with(strtoupper(trim($g)), 'GG-BD-')
        );

        // Limpiar registros corruptos previos (sin prefijo GG-BD-) que pudieron
        // insertarse antes del fix de validación. Esto garantiza una base limpia.
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

        // GUARDA ANTI-BORRADO: si Graph no trajo ninguna membresía (memberOf vacío)
        // pero el usuario ya tenía GG-BD-*, es casi seguro un fallo parcial/intermitente.
        // Si memberOf sí trajo grupos y ninguno es GG-BD-*, se permite limpiar.
        if (!empty($existentes) && empty($nuevos) && $memberOfCount === 0) {
            throw new \RuntimeException(
                'Azure devolvió memberOf vacío pero el usuario ya tenía grupos GG-BD-*; sync abortado para no borrar permisos'
            );
        }

        if (!empty($aEliminar)) {
            $deleted = UserGrup::where('id_user', $user->id)
                ->where('origen', UserGrup::ORIGEN_AZURE)
                ->where('tipo', UserGrup::TIPO_VISTA_BD)
                ->whereIn('permiso', $aEliminar)
                ->delete();
            $stats['deleted'] += $deleted;
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

    private function syncDepartamento(User $user, ?string $department): array
    {
        $stats = ['inserted' => 0, 'deleted' => 0, 'updated' => 0, 'unchanged' => 0];

        $registro = UserGrup::where('id_user', $user->id)
            ->where('origen', UserGrup::ORIGEN_AZURE)
            ->where('tipo', UserGrup::TIPO_DEPARTAMENTO)
            ->first();

        if (empty($department)) {
            if ($registro) {
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
