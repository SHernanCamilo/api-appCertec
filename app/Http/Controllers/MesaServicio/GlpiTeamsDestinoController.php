<?php

declare(strict_types=1);

namespace App\Http\Controllers\MesaServicio;

use App\Http\Controllers\Controller;
use App\Models\MesaServicio\GlpiTeamsDestino;
use App\Services\MesaServicio\GlpiTicketsTicService;
use App\Services\MesaServicio\Teams\GlpiTeamsNotificador;
use App\Services\MesaServicio\Teams\GlpiTeamsProgramacion;
use App\Services\MesaServicio\Teams\GraphTeamsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class GlpiTeamsDestinoController extends Controller
{
    public function __construct(
        private GlpiTeamsNotificador $notificador,
        private GraphTeamsClient $graph,
        private GlpiTicketsTicService $tablero,
        private GlpiTeamsProgramacion $programacion,
    ) {
        $this->middleware(function ($request, $next) {
            $user = auth('api')->user();
            $user?->loadMissing(['rolesCustom.perfiles.permisos']);
            foreach ($user?->rolesCustom ?? [] as $rol) {
                foreach ($rol->perfiles as $perfil) {
                    foreach ($perfil->permisos as $permiso) {
                        if ($permiso->estado && $permiso->codigo === 'mesa-glpi-tablero-notificar') {
                            return $next($request);
                        }
                    }
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para realizar esta acción',
            ], 403);
        });
    }

    public function index(): JsonResponse
    {
        $destinos = GlpiTeamsDestino::query()
            ->orderBy('nombre')
            ->get()
            ->map(fn (GlpiTeamsDestino $destino): array => $this->presentar($destino))
            ->values();

        return response()->json([
            'success' => true,
            'data' => $destinos,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $destino = new GlpiTeamsDestino();
        $this->aplicar($destino, $this->validar($request), true);

        return response()->json([
            'success' => true,
            'message' => 'Destino de Teams guardado.',
            'data' => $this->presentar($destino),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $destino = $this->buscar($id);
        $this->aplicar($destino, $this->validar($request), false);

        return response()->json([
            'success' => true,
            'message' => 'Destino de Teams actualizado.',
            'data' => $this->presentar($destino),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $destino = $this->buscar($id);
        $this->programacion->retirar($destino);
        $destino->delete();

        return response()->json([
            'success' => true,
            'message' => 'Destino de Teams eliminado.',
        ]);
    }

    public function enviar(int $id): JsonResponse
    {
        $destino = $this->buscar($id);

        try {
            $estado = $this->notificador->enviarAhora($destino);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo enviar la notificación a Teams.',
            ], 502);
        }

        $destino->refresh();

        return response()->json([
            'success' => true,
            'message' => $estado === 'omitido'
                ? 'No había casos para '.$destino->nombre.'.'
                : 'Notificación enviada a '.$destino->nombre.'.',
            'data' => $this->presentar($destino),
        ]);
    }

    public function vistaPrevia(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre' => ['nullable', 'string', 'max:120'],
            'modo' => ['required', 'in:resumen,detalle'],
            'grupos' => ['nullable', 'array', 'max:40'],
            'grupos.*' => ['string', 'max:150'],
        ]);

        try {
            $mensaje = $this->notificador->vista([
                'nombre' => $datos['nombre'] ?? 'Vista previa',
                'modo' => $datos['modo'],
                'grupos' => $datos['grupos'] ?? [],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo armar la vista previa.',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'titulo' => $mensaje['titulo'],
                'texto' => $mensaje['texto'],
                'casos' => $mensaje['casos'],
            ],
        ]);
    }

    public function grupos(): JsonResponse
    {
        try {
            $data = $this->tablero->tablero(false);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'No se pudieron leer los grupos del tablero.',
            ], 502);
        }

        $grupos = [];
        foreach ($data['grupos_tecnicos'] ?? [] as $grupo) {
            if (! is_array($grupo)) {
                continue;
            }
            $nombre = trim((string) ($grupo['nombre'] ?? ''));
            if ($nombre === '' || strcasecmp($nombre, 'null') === 0) {
                continue;
            }
            $grupos[] = [
                'nombre' => $nombre,
                'nivel' => (int) ($grupo['nivel'] ?? 1),
                'total' => (int) ($grupo['total'] ?? 0),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $grupos,
        ]);
    }

    public function chats(): JsonResponse
    {
        try {
            $chats = $this->graph->chats();
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $chats,
        ]);
    }

    public function equipos(): JsonResponse
    {
        try {
            $equipos = $this->graph->equipos();
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $equipos,
        ]);
    }

    public function canales(string $teamId): JsonResponse
    {
        try {
            $canales = $this->graph->canales($teamId);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $canales,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:120'],
            'activo' => ['sometimes', 'boolean'],
            'modo' => ['required', 'in:resumen,detalle'],
            'grupos' => ['nullable', 'array', 'max:40'],
            'grupos.*' => ['string', 'max:150'],
            'intervalo_minutos' => ['required', 'integer', 'in:5,15,30,60'],
            'programacion' => ['required', 'in:intervalo,hora'],
            'hora' => ['nullable', 'date_format:H:i', 'required_if:programacion,hora'],
            'omitir_si_vacio' => ['sometimes', 'boolean'],
            'webhook_url' => ['nullable', 'string', 'max:2000'],
            'quitar_webhook' => ['sometimes', 'boolean'],
            'team_id' => ['nullable', 'string', 'max:80'],
            'team_nombre' => ['nullable', 'string', 'max:200'],
            'channel_id' => ['nullable', 'string', 'max:255'],
            'channel_nombre' => ['nullable', 'string', 'max:200'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function aplicar(GlpiTeamsDestino $destino, array $datos, bool $nuevo): void
    {
        $entrada = (string) ($datos['webhook_url'] ?? '');
        $chatEntrada = GraphTeamsClient::chatIdDesdeTexto($entrada);
        $chatCanal = GraphTeamsClient::chatIdDesdeTexto((string) ($datos['channel_id'] ?? ''));
        $webhookNuevo = $chatEntrada !== null ? null : $this->normalizarWebhook($entrada);
        $quitar = $chatEntrada !== null || filter_var($datos['quitar_webhook'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $teamId = trim((string) ($datos['team_id'] ?? ''));
        $channelId = $chatEntrada ?? trim((string) ($datos['channel_id'] ?? ''));
        if ($chatEntrada !== null || ($teamId === '' && $chatCanal !== null)) {
            $teamId = '';
            $channelId = $chatEntrada ?? (string) $chatCanal;
            $datos['team_nombre'] = 'Chat';
            $datos['channel_nombre'] = $this->textoOpcional($datos['channel_nombre'] ?? null) ?? 'Chat de Teams';
        }
        $conservaWebhook = ! $nuevo && ! $quitar && $webhookNuevo === null && filled($destino->webhook_cifrado);
        $tendraWebhook = $webhookNuevo !== null || $conservaWebhook;
        $tendraCanal = $chatEntrada !== null || $chatCanal !== null || ($teamId !== '' && $channelId !== '');

        if (! $tendraWebhook && ! $tendraCanal) {
            throw ValidationException::withMessages([
                'webhook_url' => 'Indica el webhook del canal o el equipo y el canal de Teams.',
            ]);
        }

        $destino->fill([
            'nombre' => trim((string) $datos['nombre']),
            'activo' => array_key_exists('activo', $datos) ? (bool) $datos['activo'] : true,
            'modo' => $datos['modo'],
            'grupos' => $this->listaGrupos($datos['grupos'] ?? []),
            'intervalo_minutos' => $this->programacion->intervalo((int) $datos['intervalo_minutos']),
            'programacion' => $datos['programacion'] === 'hora' ? 'hora' : 'intervalo',
            'hora' => $datos['programacion'] === 'hora' ? $this->programacion->hora($datos['hora'] ?? null) : null,
            'omitir_si_vacio' => array_key_exists('omitir_si_vacio', $datos) ? (bool) $datos['omitir_si_vacio'] : true,
            'team_id' => $teamId !== '' ? $teamId : null,
            'team_nombre' => $this->textoOpcional($datos['team_nombre'] ?? null),
            'channel_id' => $channelId !== '' ? $channelId : null,
            'channel_nombre' => $this->textoOpcional($datos['channel_nombre'] ?? null),
        ]);

        if ($webhookNuevo !== null) {
            $destino->asignarWebhook($webhookNuevo);
        } elseif ($quitar) {
            $destino->asignarWebhook(null);
        }

        if ($nuevo) {
            $destino->created_by = auth()->id();
        }

        $destino->save();
        $this->programacion->sincronizar($destino);
    }

    private function normalizarWebhook(mixed $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        $partes = parse_url($url);
        $scheme = strtolower((string) ($partes['scheme'] ?? ''));
        $host = (string) ($partes['host'] ?? '');
        if ($scheme !== 'https' || $host === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ValidationException::withMessages([
                'webhook_url' => 'La URL del webhook debe ser https válida.',
            ]);
        }

        return $url;
    }

    /**
     * @return list<string>
     */
    private function listaGrupos(mixed $grupos): array
    {
        if (! is_array($grupos)) {
            return [];
        }

        $lista = [];
        foreach ($grupos as $grupo) {
            $texto = trim((string) $grupo);
            if ($texto !== '' && strcasecmp($texto, 'null') !== 0) {
                $lista[] = $texto;
            }
        }

        return array_values(array_unique($lista));
    }

    private function textoOpcional(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private function buscar(int $id): GlpiTeamsDestino
    {
        $destino = GlpiTeamsDestino::query()->find($id);
        if (! $destino) {
            abort(response()->json([
                'success' => false,
                'message' => 'Destino de Teams no encontrado.',
            ], 404));
        }

        return $destino;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentar(GlpiTeamsDestino $destino): array
    {
        return [
            'id' => $destino->id,
            'nombre' => $destino->nombre,
            'activo' => (bool) $destino->activo,
            'modo' => $destino->modo,
            'grupos' => array_values($destino->grupos ?? []),
            'intervalo_minutos' => (int) $destino->intervalo_minutos,
            'programacion' => $destino->programacion === 'hora' ? 'hora' : 'intervalo',
            'hora' => $destino->hora,
            'scheduled_task_id' => $destino->scheduled_task_id ? (int) $destino->scheduled_task_id : null,
            'omitir_si_vacio' => (bool) $destino->omitir_si_vacio,
            'tiene_webhook' => filled($destino->webhook_cifrado),
            'team_id' => $destino->team_id,
            'team_nombre' => $destino->team_nombre,
            'channel_id' => $destino->channel_id,
            'channel_nombre' => $destino->channel_nombre,
            'ultima_notificacion_at' => $destino->ultima_notificacion_at?->toIso8601String(),
            'ultimo_estado' => $destino->ultimo_estado,
            'ultimo_error' => $destino->ultimo_error,
        ];
    }
}
