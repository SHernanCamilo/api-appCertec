<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTemplateRequest;
use App\Http\Requests\UpdateTemplateRequest;
use App\Models\Template;
use App\Repositories\TemplateRepository;
use App\Services\TemplateValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class TemplateController extends Controller
{
    protected TemplateRepository $repository;
    protected TemplateValidator $validator;

    public function __construct(TemplateRepository $repository, TemplateValidator $validator)
    {
        $this->repository = $repository;
        $this->validator = $validator;
    }

    /**
     * Listar todas las plantillas
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $category = $request->query('category');
            $perPage = $request->query('per_page', 15);

            if ($request->has('paginate') && $request->query('paginate') === 'true') {
                $templates = $this->repository->paginate((int)$perPage, $category);
            } else {
                $templates = $category 
                    ? $this->repository->findByCategory($category)
                    : $this->repository->findAll();
            }

            return response()->json([
                'success' => true,
                'data' => $templates
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'code' => 'TEMPLATES_FETCH_ERROR',
                    'message' => 'Error al obtener las plantillas',
                    'details' => $e->getMessage(),
                    'timestamp' => now()->toIso8601String(),
                    'path' => $request->path()
                ]
            ], 500);
        }
    }

    /**
     * Obtener una plantilla por ID
     *
     * @param int $id
     * @param Request $request
     * @return JsonResponse
     */
    public function show(int $id, Request $request): JsonResponse
    {
        try {
            $template = $this->repository->findById($id);

            if (!$template) {
                return response()->json([
                    'error' => [
                        'code' => 'TEMPLATE_NOT_FOUND',
                        'message' => 'Plantilla no encontrada',
                        'timestamp' => now()->toIso8601String(),
                        'path' => $request->path()
                    ]
                ], 404);
            }

            // Verificar autorización
            $this->authorize('view', $template);

            return response()->json([
                'success' => true,
                'data' => $template
            ], 200);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'No tienes permiso para ver esta plantilla',
                    'timestamp' => now()->toIso8601String(),
                    'path' => $request->path()
                ]
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'code' => 'TEMPLATE_FETCH_ERROR',
                    'message' => 'Error al obtener la plantilla',
                    'details' => $e->getMessage(),
                    'timestamp' => now()->toIso8601String(),
                    'path' => $request->path()
                ]
            ], 500);
        }
    }

    /**
     * Crear una nueva plantilla
     *
     * @param StoreTemplateRequest $request
     * @return JsonResponse
     */
    public function store(StoreTemplateRequest $request): JsonResponse
    {
        try {
            // Verificar autorización
            $this->authorize('create', Template::class);

            // Validar contenido y sintaxis de variables
            $validation = $this->validator->validateContent($request->input('content'));
            
            if (!$validation['valid']) {
                return response()->json([
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'Error de validación en el contenido de la plantilla',
                        'details' => $validation['errors'],
                        'timestamp' => now()->toIso8601String(),
                        'path' => $request->path()
                    ]
                ], 400);
            }

            // Crear plantilla
            $data = $request->validated();
            $data['created_by'] = Auth::id();

            $template = $this->repository->create($data);
            $template->load('creator:id,name,email');

            return response()->json([
                'success' => true,
                'message' => 'Plantilla creada exitosamente',
                'data' => $template
            ], 201);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'No tienes permiso para crear plantillas',
                    'timestamp' => now()->toIso8601String(),
                    'path' => $request->path()
                ]
            ], 403);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'code' => 'TEMPLATE_CREATE_ERROR',
                    'message' => 'Error al crear la plantilla',
                    'details' => $e->getMessage(),
                    'timestamp' => now()->toIso8601String(),
                    'path' => $request->path()
                ]
            ], 500);
        }
    }

    /**
     * Actualizar una plantilla existente
     *
     * @param UpdateTemplateRequest $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(UpdateTemplateRequest $request, int $id): JsonResponse
    {
        try {
            $template = $this->repository->findById($id);
            
            if (!$template) {
                return response()->json([
                    'error' => [
                        'code' => 'TEMPLATE_NOT_FOUND',
                        'message' => 'Plantilla no encontrada',
                        'timestamp' => now()->toIso8601String(),
                        'path' => $request->path()
                    ]
                ], 404);
            }

            // Verificar autorización
            $this->authorize('update', $template);

            // Validar contenido si se está actualizando
            if ($request->has('content')) {
                $validation = $this->validator->validateContent($request->input('content'));
                
                if (!$validation['valid']) {
                    return response()->json([
                        'error' => [
                            'code' => 'VALIDATION_ERROR',
                            'message' => 'Error de validación en el contenido de la plantilla',
                            'details' => $validation['errors'],
                            'timestamp' => now()->toIso8601String(),
                            'path' => $request->path()
                        ]
                    ], 400);
                }
            }

            $template = $this->repository->update($id, $request->validated());

            return response()->json([
                'success' => true,
                'message' => 'Plantilla actualizada exitosamente',
                'data' => $template
            ], 200);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'No tienes permiso para editar esta plantilla',
                    'timestamp' => now()->toIso8601String(),
                    'path' => $request->path()
                ]
            ], 403);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => [
                    'code' => 'TEMPLATE_NOT_FOUND',
                    'message' => 'Plantilla no encontrada',
                    'timestamp' => now()->toIso8601String(),
                    'path' => $request->path()
                ]
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'code' => 'TEMPLATE_UPDATE_ERROR',
                    'message' => 'Error al actualizar la plantilla',
                    'details' => $e->getMessage(),
                    'timestamp' => now()->toIso8601String(),
                    'path' => $request->path()
                ]
            ], 500);
        }
    }

    /**
     * Eliminar una plantilla (soft delete)
     *
     * @param int $id
     * @param Request $request
     * @return JsonResponse
     */
    public function destroy(int $id, Request $request): JsonResponse
    {
        try {
            $template = $this->repository->findById($id);
            
            if (!$template) {
                return response()->json([
                    'error' => [
                        'code' => 'TEMPLATE_NOT_FOUND',
                        'message' => 'Plantilla no encontrada',
                        'timestamp' => now()->toIso8601String(),
                        'path' => $request->path()
                    ]
                ], 404);
            }

            // Verificar autorización
            $this->authorize('delete', $template);

            $this->repository->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Plantilla eliminada exitosamente'
            ], 200);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'error' => [
                    'code' => 'UNAUTHORIZED',
                    'message' => 'No tienes permiso para eliminar esta plantilla',
                    'timestamp' => now()->toIso8601String(),
                    'path' => $request->path()
                ]
            ], 403);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => [
                    'code' => 'TEMPLATE_NOT_FOUND',
                    'message' => 'Plantilla no encontrada',
                    'timestamp' => now()->toIso8601String(),
                    'path' => $request->path()
                ]
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'code' => 'TEMPLATE_DELETE_ERROR',
                    'message' => 'Error al eliminar la plantilla',
                    'details' => $e->getMessage(),
                    'timestamp' => now()->toIso8601String(),
                    'path' => $request->path()
                ]
            ], 500);
        }
    }

    /**
     * Filtrar plantillas por categoría
     *
     * @param string $category
     * @param Request $request
     * @return JsonResponse
     */
    public function byCategory(string $category, Request $request): JsonResponse
    {
        try {
            $templates = $this->repository->findByCategory($category);

            return response()->json([
                'success' => true,
                'data' => $templates
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => [
                    'code' => 'TEMPLATES_FETCH_ERROR',
                    'message' => 'Error al obtener las plantillas por categoría',
                    'details' => $e->getMessage(),
                    'timestamp' => now()->toIso8601String(),
                    'path' => $request->path()
                ]
            ], 500);
        }
    }
}
