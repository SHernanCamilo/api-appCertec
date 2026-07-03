<?php

namespace App\Services\Config;

use App\Models\Config\ConfigUnidadFuncional;
use App\Models\Empleado;
use App\Models\Sede;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UnidadFuncionalService
{
    public function listar(array $filters, User $user): Collection
    {
        $query = ConfigUnidadFuncional::query()
            ->with(['empresa:id,nombre,prefijo', 'sucursal:id,nombre', 'sede:id,nombre'])
            ->accessibleByUser($user)
            ->orderBy('nombre');

        if (!empty($filters['empresa_id'])) {
            $query->where('id_empresa', (int) $filters['empresa_id']);
        }

        return $query->get();
    }

    public function buscarPorCodigo(string $codigo, int $empresaId, User $user): ?ConfigUnidadFuncional
    {
        return ConfigUnidadFuncional::query()
            ->with(['empresa:id,nombre,prefijo', 'sucursal:id,nombre', 'sede:id,nombre', 'usuarios:id,nombre,email', 'responsables:id,name,email'])
            ->accessibleByUser($user)
            ->where('id_empresa', $empresaId)
            ->where('codigo', $codigo)
            ->first();
    }

    public function obtener(int $id, User $user): ConfigUnidadFuncional
    {
        return ConfigUnidadFuncional::query()
            ->with(['empresa:id,nombre,prefijo', 'sucursal:id,nombre', 'sede:id,nombre', 'usuarios:id,nombre,email', 'responsables:id,name,email'])
            ->accessibleByUser($user)
            ->findOrFail($id);
    }

    public function crear(array $data, User $user): ConfigUnidadFuncional
    {
        $this->validarJerarquia($data);
        $this->validarAccesoEmpresa($data['id_empresa'], $user);

        return DB::transaction(function () use ($data) {
            $unidad = ConfigUnidadFuncional::create([
                'id_empresa' => $data['id_empresa'],
                'id_sucursal' => $data['id_sucursal'],
                'id_sede' => $data['id_sede'] ?? null,
                'codigo' => strtoupper(trim($data['codigo'])),
                'nombre' => trim($data['nombre']),
                'estado' => array_key_exists('estado', $data) ? (bool) $data['estado'] : true,
            ]);

            $this->syncUsuarios($unidad, $data['usuarios_autorizados'] ?? []);
            $this->syncResponsables($unidad, $data['jefes_encargados'] ?? []);

            return $this->obtener($unidad->id, auth('api')->user());
        });
    }

    public function actualizar(int $id, array $data, User $user): ConfigUnidadFuncional
    {
        $unidad = $this->obtener($id, $user);
        $this->validarJerarquia($data);
        $this->validarAccesoEmpresa($data['id_empresa'], $user);

        return DB::transaction(function () use ($unidad, $data) {
            $unidad->update([
                'id_empresa' => $data['id_empresa'],
                'id_sucursal' => $data['id_sucursal'],
                'id_sede' => $data['id_sede'] ?? null,
                'codigo' => strtoupper(trim($data['codigo'])),
                'nombre' => trim($data['nombre']),
                'estado' => array_key_exists('estado', $data) ? (bool) $data['estado'] : $unidad->estado,
            ]);

            if (array_key_exists('usuarios_autorizados', $data)) {
                $this->syncUsuarios($unidad, $data['usuarios_autorizados'] ?? []);
            }

            if (array_key_exists('jefes_encargados', $data)) {
                $this->syncResponsables($unidad, $data['jefes_encargados'] ?? []);
            }

            return $this->obtener($unidad->id, auth('api')->user());
        });
    }

    public function eliminar(int $id, User $user): void
    {
        $unidad = $this->obtener($id, $user);
        $unidad->delete();
    }

    public function formatearRespuesta(ConfigUnidadFuncional $unidad): array
    {
        $unidad->loadMissing(['empresa', 'sucursal', 'sede', 'usuarios', 'responsables']);

        return [
            'id' => $unidad->id,
            'codigo' => $unidad->codigo,
            'nombre' => $unidad->nombre,
            'id_empresa' => $unidad->id_empresa,
            'id_sucursal' => $unidad->id_sucursal,
            'id_sede' => $unidad->id_sede,
            'estado' => $unidad->estado ? 1 : 0,
            'empresa' => $unidad->empresa ? [
                'id' => $unidad->empresa->id,
                'nombre' => $unidad->empresa->nombre,
                'prefijo' => $unidad->empresa->prefijo ?? null,
            ] : null,
            'sucursal' => $unidad->sucursal ? [
                'id' => $unidad->sucursal->id,
                'nombre' => $unidad->sucursal->nombre,
            ] : null,
            'sede' => $unidad->sede ? [
                'id' => $unidad->sede->id,
                'nombre' => $unidad->sede->nombre,
            ] : null,
            'usuarios_autorizados' => $unidad->usuarios->map(fn ($empleado) => [
                'id_user' => $empleado->id,
                'codigo' => str_pad((string) $empleado->id, 3, '0', STR_PAD_LEFT),
                'nombre' => $empleado->nombre,
            ])->values()->all(),
            'jefes_encargados' => $unidad->responsables->map(fn ($user) => [
                'id_user' => $user->id,
                'codigo' => str_pad((string) $user->id, 3, '0', STR_PAD_LEFT),
                'nombre' => $user->name,
            ])->values()->all(),
            'created_at' => $unidad->created_at,
            'updated_at' => $unidad->updated_at,
        ];
    }

    private function syncUsuarios(ConfigUnidadFuncional $unidad, array $personIds): void
    {
        $ids = collect($personIds)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $this->validarPersonasEmpresa($ids, (int) $unidad->id_empresa);

        $unidad->usuarios()->sync($ids);
    }

    private function syncResponsables(ConfigUnidadFuncional $unidad, array $terceroIds): void
    {
        $ids = collect($terceroIds)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        // Traducir IDs de terceros a IDs de users usando config_person_tercero.id_user
        $userIds = DB::table('config_person_tercero')
            ->whereIn('id', $ids)
            ->whereNotNull('id_user')
            ->pluck('id_user')
            ->toArray();

        $unidad->responsables()->sync($userIds);
    }

    private function validarPersonasEmpresa(array $personIds, int $empresaId): void
    {
        if (empty($personIds)) {
            return;
        }

        $validos = Empleado::query()
            ->where('id_empresa', $empresaId)
            ->whereIn('id', $personIds)
            ->count();

        if ($validos !== count($personIds)) {
            throw ValidationException::withMessages([
                'usuarios_autorizados' => ['Una o más personas no pertenecen a la empresa seleccionada'],
            ]);
        }
    }

    private function validarJerarquia(array $data): void
    {
        $sucursal = Sucursal::find($data['id_sucursal']);
        if (!$sucursal || (int) $sucursal->id_Empresa !== (int) $data['id_empresa']) {
            throw ValidationException::withMessages([
                'id_sucursal' => ['La sucursal no pertenece a la empresa seleccionada'],
            ]);
        }

        if (!empty($data['id_sede'])) {
            $sede = Sede::find($data['id_sede']);
            if (!$sede || (int) $sede->id_Sucursal !== (int) $data['id_sucursal']) {
                throw ValidationException::withMessages([
                    'id_sede' => ['La sede no pertenece a la sucursal seleccionada'],
                ]);
            }
        }
    }

    private function validarAccesoEmpresa(int $empresaId, User $user): void
    {
        $user->loadMissing('empresas');

        if ($user->empresas->isEmpty()) {
            return;
        }

        $permitida = $user->empresas->contains(fn ($empresa) => (int) $empresa->id === $empresaId);

        if (!$permitida) {
            throw ValidationException::withMessages([
                'id_empresa' => ['No tiene permisos sobre la empresa seleccionada'],
            ]);
        }
    }
}
