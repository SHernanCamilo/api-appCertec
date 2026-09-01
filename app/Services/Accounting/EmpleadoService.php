<?php

namespace App\Services\Accounting;

use App\Models\Empleado;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EmpleadoService
{
    private const RELACIONES = [
        'empresa',
        'cargoRelacion',
        'usuarioCrea:id,name,email',
        'usuarioActualiza:id,name,email',
        'usuario:id,name,email,numero_identificacion,tipo_identificacion,telefono,direccion',
    ];

    public function listar(array $filters = [])
    {
        $query = Empleado::with(self::RELACIONES);

        if (! empty($filters['id_empresa'])) {
            $query->where('id_empresa', $filters['id_empresa']);
        }

        if (! empty($filters['id_cargo'])) {
            $query->where('id_cargo', $filters['id_cargo']);
        }

        if (array_key_exists('estado', $filters)) {
            $query->where('estado', filter_var($filters['estado'], FILTER_VALIDATE_BOOLEAN));
        }

        $buscar = trim($filters['buscar'] ?? '');

        if (strlen($buscar) >= 3) {
            // Usar FULLTEXT en nombre si está disponible (mucho más rápido que LIKE %...%)
            // Fallback a LIKE solo para número de identificación y email
            $query->where(function ($q) use ($buscar) {
                $q->whereRaw('MATCH(nombre) AGAINST(? IN BOOLEAN MODE)', ['"'.$buscar.'"'])
                    ->orWhere('numero_identificacion', 'like', $buscar.'%')
                    ->orWhere('email', 'like', $buscar.'%');
            });
        }

        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = min(max($perPage, 5), 100);

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function crear(array $data): Empleado
    {
        $data = $this->normalizar($data);

        $validator = Validator::make($data, [
            'id_empresa' => 'required|exists:ent_empresas,id',
            'id_cargo' => 'nullable|exists:config_cargo,id_cargo',
            'numero_identificacion' => 'required|string|max:50|unique:config_person_tercero,numero_identificacion',
            'nombre' => 'required|string|max:255',
            'email' => 'nullable|email|max:255|unique:config_person_tercero,email',
            'tipo_identificacion' => 'nullable|in:CC,CE,NIT,TI,PP,PEP',
            'unidad' => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'contrato' => 'nullable|string|max:150',
            'fecha_inicio_contrato' => 'nullable|date',
            'fecha_fin_contrato' => 'nullable|date|after_or_equal:fecha_inicio_contrato',
            'estado' => 'nullable|boolean',
            'caso_glpi' => 'nullable|string|max:100',
            'id_user' => 'nullable|exists:users,id',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return DB::transaction(function () use ($data) {
            if (empty($data['tipo_identificacion'])) {
                $data['tipo_identificacion'] = 'CC';
            }

            $userId = auth('api')->id();
            $data['usuario_crea_id'] = $userId;
            $data['usuario_actualiza_id'] = $userId;

            $empleado = Empleado::create($data);
            $empleado->load(self::RELACIONES);

            return $empleado;
        });
    }

    public function actualizar(int $id, array $data): Empleado
    {
        $data = $this->normalizar($data);

        $validator = Validator::make($data, [
            'id_empresa' => 'sometimes|required|exists:ent_empresas,id',
            'id_cargo' => 'nullable|exists:config_cargo,id_cargo',
            'numero_identificacion' => 'sometimes|required|string|max:50|unique:config_person_tercero,numero_identificacion,'.$id,
            'nombre' => 'sometimes|required|string|max:255',
            'email' => 'nullable|email|max:255|unique:config_person_tercero,email,'.$id,
            'tipo_identificacion' => 'sometimes|required|in:CC,CE,NIT,TI,PP,PEP',
            'unidad' => 'nullable|string|max:100',
            'direccion' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:20',
            'contrato' => 'nullable|string|max:150',
            'fecha_inicio_contrato' => 'nullable|date',
            'fecha_fin_contrato' => 'nullable|date|after_or_equal:fecha_inicio_contrato',
            'estado' => 'nullable|boolean',
            'caso_glpi' => 'nullable|string|max:100',
            'id_user' => 'nullable|exists:users,id',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return DB::transaction(function () use ($id, $data) {
            $empleado = Empleado::findOrFail($id);

            if (array_key_exists('tipo_identificacion', $data) && empty($data['tipo_identificacion'])) {
                $data['tipo_identificacion'] = 'CC';
            }

            $data['usuario_actualiza_id'] = auth('api')->id();

            $empleado->update($data);
            $empleado->load(self::RELACIONES);

            return $empleado;
        });
    }

    public function obtener(int $id): Empleado
    {
        return Empleado::with(self::RELACIONES)->findOrFail($id);
    }

    private function normalizar(array $data): array
    {
        unset($data['usuario_crea_id'], $data['usuario_actualiza_id']);

        foreach (['email', 'contrato', 'unidad', 'direccion', 'telefono', 'caso_glpi'] as $campo) {
            if (array_key_exists($campo, $data) && $data[$campo] === '') {
                $data[$campo] = null;
            }
        }

        if (array_key_exists('id_cargo', $data) && ($data['id_cargo'] === '' || $data['id_cargo'] === 0)) {
            $data['id_cargo'] = null;
        }

        if (array_key_exists('id_user', $data) && ($data['id_user'] === '' || $data['id_user'] === 0)) {
            $data['id_user'] = null;
        }

        return $data;
    }

    public function eliminar(int $id): void
    {
        DB::transaction(function () use ($id) {
            $empleado = Empleado::findOrFail($id);
            $empleado->delete();
        });
    }
}
