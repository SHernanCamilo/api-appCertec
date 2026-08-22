<?php

declare(strict_types=1);

namespace App\Http\Controllers\ChatBot;

use App\Http\Controllers\Controller;
use App\Services\ChatBot\ChatBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller del módulo ChatBot IA.
 *
 * Endpoints:
 *   POST   /api/chatbot/message          → Enviar mensaje al bot
 *   GET    /api/chatbot/conversations     → Historial de conversaciones
 *   GET    /api/chatbot/conversations/{id} → Mensajes de una conversación
 *   DELETE /api/chatbot/conversations/{id} → Cerrar conversación
 *   GET    /api/chatbot/catalog           → Ver catálogo de vistas disponibles
 */
class ChatBotController extends Controller
{
    private ChatBotService $chatBot;

    public function __construct()
    {
        $this->chatBot = new ChatBotService();
    }

    /**
     * Envía un mensaje al bot y recibe respuesta.
     *
     * POST /api/chatbot/message
     *
     * Body:
     * {
     *   "message": "¿Cuántos activos hay en la sede Bogotá?",
     *   "conversation_id": null  // null = nueva conversación
     * }
     */
    public function message(Request $request): JsonResponse
    {
        $request->validate([
            'message'         => 'required|string|max:1000',
            'conversation_id' => 'nullable|integer|exists:chatbot_conversations,id',
        ]);

        $user    = auth()->user();
        $message = trim($request->input('message'));
        $convId  = $request->input('conversation_id');

        $result = $this->chatBot->processMessage($user, $message, $convId);

        $status = $result['success'] ? 200 : 422;

        return response()->json($result, $status);
    }

    /**
     * Lista las conversaciones del usuario.
     *
     * GET /api/chatbot/conversations
     */
    public function conversations(Request $request): JsonResponse
    {
        $user = auth()->user();
        $conversations = $this->chatBot->getConversations($user);

        return response()->json([
            'success' => true,
            'data'    => $conversations,
        ]);
    }

    /**
     * Obtiene los mensajes de una conversación.
     *
     * GET /api/chatbot/conversations/{id}
     */
    public function conversationMessages(int $id): JsonResponse
    {
        $user     = auth()->user();
        $messages = $this->chatBot->getMessages($user, $id);

        return response()->json([
            'success' => true,
            'data'    => $messages,
        ]);
    }

    /**
     * Cierra (desactiva) una conversación.
     *
     * DELETE /api/chatbot/conversations/{id}
     */
    public function deleteConversation(int $id): JsonResponse
    {
        $user = auth()->user();

        $updated = \Illuminate\Support\Facades\DB::table('chatbot_conversations')
            ->where('id', $id)
            ->where('user_id', $user->id)
            ->update(['activa' => false, 'updated_at' => now()]);

        if ($updated === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Conversación no encontrada.',
            ], 404);
        }

        return response()->json(['success' => true, 'message' => 'Conversación cerrada.']);
    }

    /**
     * Muestra el catálogo de vistas disponibles para el usuario actual.
     * Útil para que el frontend muestre qué puede preguntar.
     *
     * GET /api/chatbot/catalog
     */
    public function catalog(): JsonResponse
    {
        $user = auth()->user();

        $catalogo = \Illuminate\Support\Facades\DB::table('chatbot_knowledge_views')
            ->where('activo', true)
            ->get(['schema_name', 'view_name', 'descripcion', 'ejemplo_preguntas']);

        // Filtrar por esquemas del usuario
        $gateway  = new \App\Services\Fabric\GraphFabricGatewayService();
        $esquemas = $gateway->getEsquemasPermitidos($user);

        $filtrado = $catalogo->filter(fn ($v) => in_array($v->schema_name, $esquemas, true))
            ->map(fn ($v) => [
                'schema'            => $v->schema_name,
                'vista'             => $v->view_name,
                'descripcion'       => $v->descripcion,
                'ejemplo_preguntas' => json_decode($v->ejemplo_preguntas, true),
            ])
            ->values();

        return response()->json([
            'success'  => true,
            'esquemas' => $esquemas,
            'data'     => $filtrado,
        ]);
    }
}
