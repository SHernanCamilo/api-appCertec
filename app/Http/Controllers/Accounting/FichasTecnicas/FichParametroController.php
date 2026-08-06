<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting\FichasTecnicas;

use App\Services\Accounting\FichasTecnicas\FichParametroService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD genérico de los catálogos maestros del módulo.
 *
 * Un solo controlador cubre agremiaciones, profesionales, especialidades,
 * tipos de servicio, objetos de contrato, observaciones y homólogos,
 * reemplazando los ~20 archivos de `parametrizador/`.
 */
class FichParametroController extends BaseFichasController
{
    public function __construct(private readonly FichParametroService $parametros)
    {
    }

    public function index(Request $request, string $catalogo): JsonResponse
    {
        return $this->ejecutar(
            fn (): JsonResponse => $this->paginado($this->parametros->listar($catalogo, $request->all())),
            'Error al obtener el catálogo'
        );
    }

    public function store(Request $request, string $catalogo): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->parametros->crear($catalogo, $request->all()),
            'Error al crear el registro del catálogo',
            201
        );
    }

    public function update(Request $request, string $catalogo, int $id): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->parametros->actualizar($catalogo, $id, $request->all()),
            'Error al actualizar el registro del catálogo'
        );
    }

    /** Activa/desactiva (regla R17: nunca se borra físicamente). */
    public function cambiarEstado(Request $request, string $catalogo, int $id): JsonResponse
    {
        $datos = $request->validate(['estado' => ['required', 'boolean']]);

        return $this->ejecutar(
            fn () => $this->parametros->cambiarEstado($catalogo, $id, (bool) $datos['estado']),
            'Error al cambiar el estado del registro'
        );
    }

    /** Opciones de todos los catálogos para los selects del formulario. */
    public function opciones(): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->parametros->opcionesFormulario(),
            'Error al obtener las opciones de los catálogos'
        );
    }

    /** Profesionales de una especialidad (cascada del paso 1). */
    public function profesionalesPorEspecialidad(int $idEspecialidad): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->parametros->profesionalesPorEspecialidad($idEspecialidad),
            'Error al obtener los profesionales de la especialidad'
        );
    }

    /** Observaciones aplicables a un tipo de servicio (cascada del paso 2). */
    public function observacionesPorTipoServicio(int $idTipoServicio): JsonResponse
    {
        return $this->ejecutar(
            fn () => $this->parametros->observacionesPorTipoServicio($idTipoServicio),
            'Error al obtener las observaciones del tipo de servicio'
        );
    }

    /** Asigna especialidades a un profesional (legacy asig_esp.php). */
    public function asignarEspecialidades(Request $request, int $idProfesional): JsonResponse
    {
        $datos = $request->validate([
            'especialidades'   => ['present', 'array'],
            'especialidades.*' => ['integer', 'exists:fich_especialidades,id'],
        ]);

        return $this->ejecutar(
            fn () => $this->parametros->asignarEspecialidades($idProfesional, $datos['especialidades']),
            'Error al asignar las especialidades al profesional'
        );
    }

    /** Asigna tipos de servicio a una observación predefinida. */
    public function asignarTiposServicio(Request $request, int $idObsItem): JsonResponse
    {
        $datos = $request->validate([
            'tipos_servicio'   => ['present', 'array'],
            'tipos_servicio.*' => ['integer', 'exists:fich_tipos_servicio,id'],
        ]);

        return $this->ejecutar(
            fn () => $this->parametros->asignarTiposServicioAObservacion($idObsItem, $datos['tipos_servicio']),
            'Error al asignar los tipos de servicio a la observación'
        );
    }
}
