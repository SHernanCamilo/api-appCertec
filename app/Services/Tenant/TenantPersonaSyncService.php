<?php

namespace App\Services\Tenant;

use App\Models\Cargo;
use App\Models\Empleado;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Vincula o crea config_person_tercero al sincronizar usuarios desde Azure AD.
 */
class TenantPersonaSyncService
{
    /**
     * Si ya existe tercero por cédula → vincula id_user.
     * Si no existe → crea el registro en config_person_tercero.
     *
     * @param  array{department?: ?string, job_title?: ?string}  $tenantData
     * @return array{accion: string, tercero_id?: int, motivo?: string}
     */
    public function syncFromUser(User $user, array $tenantData, int $empresaId, ?int $usuarioCreaId = null): array
    {
        $cedula = trim((string) ($user->numero_identificacion ?? ''));

        if ($cedula === '') {
            return ['accion' => 'omitido', 'motivo' => 'sin_numero_identificacion'];
        }

        $tercero = Empleado::where('numero_identificacion', $cedula)->first();

        if ($tercero) {
            return $this->vincularTerceroExistente($tercero, $user, $tenantData, $usuarioCreaId);
        }

        return $this->crearTercero($user, $tenantData, $empresaId, $usuarioCreaId);
    }

    private function vincularTerceroExistente(
        Empleado $tercero,
        User $user,
        array $tenantData,
        ?int $usuarioCreaId
    ): array {
        $updates = [];

        if (!$tercero->id_user) {
            $updates['id_user'] = $user->id;
        }

        if (empty($tercero->email) && $user->email) {
            $updates['email'] = $user->email;
        }

        if (empty($tercero->unidad) && !empty($tenantData['department'])) {
            $updates['unidad'] = $tenantData['department'];
        }

        if (!$tercero->id_cargo && !empty($tenantData['job_title'])) {
            $cargoId = $this->resolverCargo($tenantData['job_title'], (int) $tercero->id_empresa);
            if ($cargoId) {
                $updates['id_cargo'] = $cargoId;
            }
        }

        if ($usuarioCreaId) {
            $updates['usuario_actualiza_id'] = $usuarioCreaId;
        }

        if (!empty($updates)) {
            $tercero->update($updates);
        }

        return [
            'accion'     => 'vinculado',
            'tercero_id' => $tercero->id,
        ];
    }

    private function crearTercero(
        User $user,
        array $tenantData,
        int $empresaId,
        ?int $usuarioCreaId
    ): array {
        $cargoId = null;
        if (!empty($tenantData['job_title'])) {
            $cargoId = $this->resolverCargo($tenantData['job_title'], $empresaId);
        }

        $tercero = Empleado::create([
            'id_user'               => $user->id,
            'id_empresa'            => $empresaId,
            'id_cargo'              => $cargoId,
            'numero_identificacion' => $user->numero_identificacion,
            'nombre'                => $user->name,
            'email'                 => $user->email,
            'tipo_identificacion'   => $user->tipo_identificacion ?: 'CC',
            'unidad'                => $tenantData['department'] ?? null,
            'direccion'             => $user->direccion,
            'telefono'              => $user->telefono,
            'estado'                => true,
            'usuario_crea_id'       => $usuarioCreaId,
            'usuario_actualiza_id'  => $usuarioCreaId,
        ]);

        return [
            'accion'     => 'creado',
            'tercero_id' => $tercero->id,
        ];
    }

    private function resolverCargo(string $jobTitle, int $empresaId): ?int
    {
        $jobTitle = trim($jobTitle);
        if ($jobTitle === '') {
            return null;
        }

        $cargo = DB::table('config_cargo')
            ->where('nombre_cargo', $jobTitle)
            ->where('id_empresa', $empresaId)
            ->first();

        if ($cargo) {
            return (int) $cargo->id_cargo;
        }

        return (int) DB::table('config_cargo')->insertGetId([
            'nombre_cargo'     => $jobTitle,
            'nivel_jerarquico' => Cargo::NIVEL_OPERATIVO,
            'id_empresa'       => $empresaId,
            'estado'           => 1,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }
}
