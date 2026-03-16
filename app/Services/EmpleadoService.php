<?php

namespace App\Services;

use App\Models\Empleado;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EmpleadoService
{
    public function listar(array $filters = [])
    {
        $query = Empleado::with(['empresa', 'cargoRelacion']);

        if (!empty($filters['id_empresa'])) {
            $query->where('id_empresa', $filters['id_empresa']);
        }

        if (!empty($filters['id_cargo'])) {
            $query->where('id_cargo', $filters['id_cargo']);
        }

        if (array_key_exists('estado', $filters)) {
            $query->where('estado', filter_var($filters['estado'], FILTER_VALIDATE_BOOLEAN));
        }

        if (!empty($filters['buscar'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('nombre', 'like', '%' . $filters['buscar'] . '%')
                    ->orWhere('numero_identificacion', 'like', '%' . $filters['buscar'] . '%')
                    ->orWhere('email', 'like', '%' . $filters['buscar'] . '%');
            });
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function crear(array $data): Empleado
    {
        $validator = Validator::make($data, [
            'id_empresa' => 'required|exists:ent_empresas,id',
            'id_cargo' => 'required|exists:config_cargo,id_cargo',
            'numero_identificacion' => 'required|string|max:50|unique:config_person_tercero,numero_identificacion',
            'nombre' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|unique:config_person_tercero,email',
            'tipo_identificacion' => 'nullable|in:CC,CE,NIT,TI,PP,PEP',
            'unidad' => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'estado' => 'nullable|boolean',
            'caso_glpi' => 'nullable|string|max:100',
            'usuario_crea_id' => 'nullable|exists:users,id',
            'usuario_actualiza_id' => 'nullable|exists:users,id'
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return DB::transaction(function () use ($data) {
            if (empty($data['tipo_identificacion'])) {
                $data['tipo_identificacion'] = 'CC';
            }

            $empleado = Empleado::create($data);
            $empleado->load(['empresa', 'cargoRelacion']);

            return $empleado;
        });
    }

    public function actualizar(int $id, array $data): Empleado
    {
        $validator = Validator::make($data, [
            'id_empresa' => 'sometimes|required|exists:ent_empresas,id',
            'id_cargo' => 'sometimes|required|exists:config_cargo,id_cargo',
            'numero_identificacion' => 'sometimes|required|string|max:50|unique:config_person_tercero,numero_identificacion,' . $id,
            'nombre' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255|unique:config_person_tercero,email,' . $id,
            'tipo_identificacion' => 'sometimes|required|in:CC,CE,NIT,TI,PP,PEP',
            'unidad' => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'estado' => 'nullable|boolean',
            'caso_glpi' => 'nullable|string|max:100',
            'usuario_crea_id' => 'nullable|exists:users,id',
            'usuario_actualiza_id' => 'nullable|exists:users,id'
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return DB::transaction(function () use ($id, $data) {
            $empleado = Empleado::findOrFail($id);

            if (array_key_exists('tipo_identificacion', $data) && empty($data['tipo_identificacion'])) {
                $data['tipo_identificacion'] = 'CC';
            }

            $empleado->update($data);
            $empleado->load(['empresa', 'cargoRelacion']);

            return $empleado;
        });
    }

    public function eliminar(int $id): void
    {
        DB::transaction(function () use ($id) {
            $empleado = Empleado::findOrFail($id);
            $empleado->delete();
        });
    }
}
