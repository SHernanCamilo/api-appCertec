<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreScheduledTaskRequest;
use App\Http\Requests\UpdateScheduledTaskRequest;
use App\Http\Resources\ScheduledTaskCollection;
use App\Http\Resources\ScheduledTaskResource;
use App\Models\ScheduledTask;
use App\Services\TaskSchedulerService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class TaskSchedulerController extends Controller
{
    protected TaskSchedulerService $taskScheduler;

    public function __construct(TaskSchedulerService $taskScheduler)
    {
        $this->taskScheduler = $taskScheduler;
    }

    /**
     * Display a listing of the resource.
     * GET /api/v1/scheduled-tasks
     */
    public function index(Request $request): ScheduledTaskCollection
    {
        $query = ScheduledTask::query()->with('creator');

        // Filtros
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Ordenamiento
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Paginación
        $perPage = $request->get('per_page', 15);
        $tasks = $query->paginate($perPage);

        return new ScheduledTaskCollection($tasks);
    }

    /**
     * Store a newly created resource in storage.
     * POST /api/v1/scheduled-tasks
     */
    public function store(StoreScheduledTaskRequest $request): JsonResponse
    {
        try {
            // Verificar si es tarea recurrente
            if ($request->is_recurring) {
                $task = $this->taskScheduler->scheduleRecurringTask(
                    name: $request->name,
                    type: $request->type,
                    recurrenceType: $request->recurrence_type,
                    recurrenceValue: $request->recurrence_value ?? [],
                    parameters: $request->parameters ?? [],
                    description: $request->description,
                    createdBy: auth()->id()
                );
            } else {
                $scheduledAt = $request->scheduled_at 
                    ? Carbon::parse($request->scheduled_at) 
                    : null;

                $task = $this->taskScheduler->scheduleTask(
                    name: $request->name,
                    type: $request->type,
                    parameters: $request->parameters ?? [],
                    scheduledAt: $scheduledAt,
                    description: $request->description,
                    createdBy: auth()->id()
                );
            }

            return response()->json([
                'message' => 'Tarea programada creada exitosamente',
                'data' => new ScheduledTaskResource($task->load('creator')),
            ], 201);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'error' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('Error al crear tarea programada', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Error al crear la tarea programada',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     * GET /api/v1/scheduled-tasks/{id}
     */
    public function show(int $id): JsonResponse
    {
        $task = ScheduledTask::with('creator')->find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Tarea no encontrada',
            ], 404);
        }

        return response()->json([
            'data' => new ScheduledTaskResource($task),
        ]);
    }

    /**
     * Update the specified resource in storage.
     * PUT/PATCH /api/v1/scheduled-tasks/{id}
     */
    public function update(UpdateScheduledTaskRequest $request, int $id): JsonResponse
    {
        $task = ScheduledTask::find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Tarea no encontrada',
            ], 404);
        }

        // No permitir actualizar tareas en ejecución o completadas
        if (in_array($task->status, [ScheduledTask::STATUS_RUNNING, ScheduledTask::STATUS_COMPLETED])) {
            return response()->json([
                'message' => 'No se puede actualizar una tarea en ejecución o completada',
            ], 422);
        }

        try {
            $data = $request->only(['name', 'description', 'scheduled_at', 'parameters']);
            
            if (isset($data['scheduled_at'])) {
                $data['scheduled_at'] = Carbon::parse($data['scheduled_at']);
            }

            $task->update($data);

            return response()->json([
                'message' => 'Tarea actualizada exitosamente',
                'data' => new ScheduledTaskResource($task->fresh()->load('creator')),
            ]);

        } catch (\Exception $e) {
            Log::error('Error al actualizar tarea programada', [
                'task_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error al actualizar la tarea',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     * DELETE /api/v1/scheduled-tasks/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $task = ScheduledTask::find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Tarea no encontrada',
            ], 404);
        }

        // No permitir eliminar tareas en ejecución
        if ($task->status === ScheduledTask::STATUS_RUNNING) {
            return response()->json([
                'message' => 'No se puede eliminar una tarea en ejecución',
            ], 422);
        }

        try {
            $task->delete();

            return response()->json([
                'message' => 'Tarea eliminada exitosamente',
            ]);

        } catch (\Exception $e) {
            Log::error('Error al eliminar tarea programada', [
                'task_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error al eliminar la tarea',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Execute task immediately
     * POST /api/v1/scheduled-tasks/{id}/execute
     */
    public function execute(int $id): JsonResponse
    {
        $task = ScheduledTask::find($id);

        if (!$task) {
            return response()->json([
                'message' => 'Tarea no encontrada',
            ], 404);
        }

        try {
            $this->taskScheduler->executeNow($id);

            return response()->json([
                'message' => 'Tarea ejecutada exitosamente',
                'data' => new ScheduledTaskResource($task->fresh()->load('creator')),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al ejecutar la tarea',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Cancel a pending task
     * POST /api/v1/scheduled-tasks/{id}/cancel
     */
    public function cancel(int $id): JsonResponse
    {
        try {
            $this->taskScheduler->cancelTask($id);

            $task = ScheduledTask::with('creator')->find($id);

            return response()->json([
                'message' => 'Tarea cancelada exitosamente',
                'data' => new ScheduledTaskResource($task),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al cancelar la tarea',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Retry a failed task
     * POST /api/v1/scheduled-tasks/{id}/retry
     */
    public function retry(int $id): JsonResponse
    {
        try {
            $this->taskScheduler->retryTask($id);

            $task = ScheduledTask::with('creator')->find($id);

            return response()->json([
                'message' => 'Tarea reintentada exitosamente',
                'data' => new ScheduledTaskResource($task),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al reintentar la tarea',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get dashboard statistics
     * GET /api/v1/scheduled-tasks/stats/dashboard
     */
    public function dashboardStats(): JsonResponse
    {
        try {
            $stats = $this->taskScheduler->getDashboardStats();

            return response()->json([
                'data' => $stats,
            ]);

        } catch (\Exception $e) {
            Log::error('Error al obtener estadísticas', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error al obtener estadísticas',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get available task types
     * GET /api/v1/scheduled-tasks/types
     */
    public function types(): JsonResponse
    {
        $types = config('scheduled-tasks.types', []);

        $formattedTypes = collect($types)->map(function ($config, $key) {
            return [
                'key' => $key,
                'name' => $config['name'],
                'description' => $config['description'],
                'max_attempts' => $config['max_attempts'],
                'timeout' => $config['timeout'],
                'parameters' => $config['parameters'] ?? [],
            ];
        })->values();

        return response()->json([
            'data' => $formattedTypes,
        ]);
    }

    /**
     * Toggle active status of recurring task
     * POST /api/v1/scheduled-tasks/{id}/toggle
     */
    public function toggle(int $id): JsonResponse
    {
        try {
            $task = ScheduledTask::find($id);

            if (!$task) {
                return response()->json([
                    'message' => 'Tarea no encontrada',
                ], 404);
            }

            if (!$task->is_recurring) {
                return response()->json([
                    'message' => 'Solo se pueden activar/desactivar tareas recurrentes',
                ], 422);
            }

            $this->taskScheduler->toggleRecurringTask($id, !$task->is_active);

            return response()->json([
                'message' => $task->is_active ? 'Tarea desactivada' : 'Tarea activada',
                'data' => new ScheduledTaskResource($task->fresh()->load('creator')),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error al cambiar estado de la tarea',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get recurrence types
     * GET /api/v1/scheduled-tasks/recurrence-types
     */
    public function recurrenceTypes(): JsonResponse
    {
        $types = [
            [
                'key' => 'every_minute',
                'name' => 'Cada minuto',
                'description' => 'Se ejecuta cada minuto',
                'requires_config' => false,
            ],
            [
                'key' => 'every_5_minutes',
                'name' => 'Cada 5 minutos',
                'description' => 'Se ejecuta cada 5 minutos',
                'requires_config' => false,
            ],
            [
                'key' => 'every_15_minutes',
                'name' => 'Cada 15 minutos',
                'description' => 'Se ejecuta cada 15 minutos',
                'requires_config' => false,
            ],
            [
                'key' => 'every_30_minutes',
                'name' => 'Cada 30 minutos',
                'description' => 'Se ejecuta cada 30 minutos',
                'requires_config' => false,
            ],
            [
                'key' => 'hourly',
                'name' => 'Cada hora',
                'description' => 'Se ejecuta cada hora',
                'requires_config' => false,
            ],
            [
                'key' => 'daily',
                'name' => 'Diariamente',
                'description' => 'Se ejecuta todos los días a una hora específica',
                'requires_config' => true,
                'config_fields' => ['time'],
            ],
            [
                'key' => 'weekly',
                'name' => 'Semanalmente',
                'description' => 'Se ejecuta un día específico de la semana',
                'requires_config' => true,
                'config_fields' => ['day_of_week', 'time'],
            ],
            [
                'key' => 'monthly',
                'name' => 'Mensualmente',
                'description' => 'Se ejecuta un día específico del mes',
                'requires_config' => true,
                'config_fields' => ['day', 'time'],
            ],
            [
                'key' => 'custom_days',
                'name' => 'Días personalizados',
                'description' => 'Se ejecuta en días específicos de la semana',
                'requires_config' => true,
                'config_fields' => ['days', 'time'],
            ],
        ];

        return response()->json([
            'data' => $types,
        ]);
    }
}
