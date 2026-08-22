<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ChatBot\ChatBotController;

/**
 * Rutas del módulo ChatBot IA.
 *
 * Prefijo: /api/chatbot
 * Middleware: auth:api
 *
 * El bot respeta los permisos GG-BD-* del usuario autenticado.
 * Solo puede consultar vistas registradas en chatbot_knowledge_views.
 */
Route::middleware(['auth:api'])->prefix('chatbot')->group(function () {

    // Enviar mensaje al bot
    Route::post('/message', [ChatBotController::class, 'message']);

    // Historial de conversaciones
    Route::get('/conversations', [ChatBotController::class, 'conversations']);

    // Mensajes de una conversación
    Route::get('/conversations/{id}', [ChatBotController::class, 'conversationMessages'])
        ->where('id', '[0-9]+');

    // Cerrar conversación
    Route::delete('/conversations/{id}', [ChatBotController::class, 'deleteConversation'])
        ->where('id', '[0-9]+');

    // Catálogo de vistas disponibles para el usuario
    Route::get('/catalog', [ChatBotController::class, 'catalog']);
});
