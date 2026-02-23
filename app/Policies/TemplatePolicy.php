<?php

namespace App\Policies;

use App\Models\Template;
use App\Models\User;

class TemplatePolicy
{
    /**
     * Determinar si el usuario puede ver cualquier plantilla
     *
     * @param User $user
     * @return bool
     */
    public function viewAny(User $user): bool
    {
        // Todos los usuarios autenticados pueden ver plantillas
        return true;
    }

    /**
     * Determinar si el usuario puede ver una plantilla específica
     *
     * @param User $user
     * @param Template $template
     * @return bool
     */
    public function view(User $user, Template $template): bool
    {
        // Todos los usuarios autenticados pueden ver plantillas
        return true;
    }

    /**
     * Determinar si el usuario puede crear plantillas
     *
     * @param User $user
     * @return bool
     */
    public function create(User $user): bool
    {
        // Temporalmente permitir a todos los usuarios autenticados
        // TODO: Implementar verificación de permisos: $user->can('crear_plantillas')
        return true;
    }

    /**
     * Determinar si el usuario puede actualizar una plantilla
     *
     * @param User $user
     * @param Template $template
     * @return bool
     */
    public function update(User $user, Template $template): bool
    {
        // Temporalmente permitir a todos los usuarios autenticados
        // TODO: Implementar verificación de permisos: $user->can('editar_plantillas')
        return true;
    }

    /**
     * Determinar si el usuario puede eliminar una plantilla
     *
     * @param User $user
     * @param Template $template
     * @return bool
     */
    public function delete(User $user, Template $template): bool
    {
        // Temporalmente permitir a todos los usuarios autenticados
        // TODO: Implementar verificación de permisos: $user->can('eliminar_plantillas')
        return true;
    }

    /**
     * Determinar si el usuario puede generar documentos desde plantillas
     * Todos los usuarios autenticados pueden generar documentos
     *
     * @param User $user
     * @return bool
     */
    public function generate(User $user): bool
    {
        // Todos los usuarios autenticados pueden generar documentos
        return true;
    }
}
