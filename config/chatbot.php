<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenRouter API
    |--------------------------------------------------------------------------
    */

    'api_key' => env('OPENROUTER_API_KEY', ''),
    'model'   => env('OPENROUTER_MODEL', 'openrouter/free'),
    'api_url' => env('OPENROUTER_API_URL', 'https://openrouter.ai/api/v1/chat/completions'),

    // Timeout para requests a OpenRouter (segundos)
    'timeout' => (int) env('OPENROUTER_TIMEOUT', 60),

    // Máximo de tokens de respuesta
    'max_tokens' => (int) env('OPENROUTER_MAX_TOKENS', 2048),

    // Temperatura (0 = determinístico, 1 = creativo)
    'temperature' => (float) env('OPENROUTER_TEMPERATURE', 0.3),

    /*
    |--------------------------------------------------------------------------
    | Seguridad
    |--------------------------------------------------------------------------
    */

    // Máximo de mensajes por conversación (evita contextos infinitos)
    'max_messages_per_conversation' => 50,

    // Máximo de conversaciones activas por usuario
    'max_active_conversations' => 10,

    // Máximo de requests al bot por usuario por hora
    'rate_limit_per_hour' => 30,

    // Máximo de filas que el bot puede consultar de Fabric
    'max_query_rows' => 100,

    // Máximo de caracteres en mensaje del usuario
    'max_message_length' => 1000,

    /*
    |--------------------------------------------------------------------------
    | Fabric Gateway (para consultas del bot)
    |--------------------------------------------------------------------------
    */

    // Timeout para consultas a Fabric desde el bot (más bajo que el viewer)
    'fabric_query_timeout' => 30,

    // Solo lectura: el bot nunca modifica datos
    'fabric_read_only' => true,

];
