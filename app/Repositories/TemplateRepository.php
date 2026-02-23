<?php

namespace App\Repositories;

use App\Models\Template;
use Illuminate\Database\Eloquent\Collection;

class TemplateRepository
{
    /**
     * Obtener todas las plantillas (sin incluir eliminadas)
     *
     * @return Collection
     */
    public function findAll(): Collection
    {
        return Template::with('creator:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Buscar plantilla por ID
     *
     * @param int $id
     * @return Template|null
     */
    public function findById(int $id): ?Template
    {
        return Template::with('creator:id,name,email')->find($id);
    }

    /**
     * Buscar plantillas por categoría
     *
     * @param string $category
     * @return Collection
     */
    public function findByCategory(string $category): Collection
    {
        return Template::with('creator:id,name,email')
            ->byCategory($category)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Crear una nueva plantilla
     *
     * @param array $data
     * @return Template
     */
    public function create(array $data): Template
    {
        return Template::create($data);
    }

    /**
     * Actualizar una plantilla existente
     *
     * @param int $id
     * @param array $data
     * @return Template
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function update(int $id, array $data): Template
    {
        $template = Template::findOrFail($id);
        $template->update($data);
        $template->refresh();
        
        return $template->load('creator:id,name,email');
    }

    /**
     * Eliminar una plantilla (soft delete)
     *
     * @param int $id
     * @return bool
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function delete(int $id): bool
    {
        $template = Template::findOrFail($id);
        return $template->delete();
    }

    /**
     * Buscar plantillas con paginación
     *
     * @param int $perPage
     * @param string|null $category
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function paginate(int $perPage = 15, ?string $category = null)
    {
        $query = Template::with('creator:id,name,email');
        
        if ($category) {
            $query->byCategory($category);
        }
        
        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Buscar plantillas por usuario creador
     *
     * @param int $userId
     * @return Collection
     */
    public function findByCreator(int $userId): Collection
    {
        return Template::with('creator:id,name,email')
            ->byCreator($userId)
            ->orderBy('created_at', 'desc')
            ->get();
    }
}
