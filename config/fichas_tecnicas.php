<?php

declare(strict_types=1);

/**
 * Configuración del módulo Fichas Técnicas Médicas.
 *
 * Parametriza reglas que en el sistema JADE legacy estaban escritas dentro de
 * los archivos PHP de cada módulo.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | RN-03 — Ventana de envío a autorización
    |--------------------------------------------------------------------------
    |
    | El envío de fichas a autorización se cierra después del día indicado, para
    | respetar el cierre del ciclo de facturación. El borrador se puede seguir
    | editando y guardando; solo se bloquea la transición al flujo de aprobación.
    | La ventana se reabre el día 01 del mes siguiente.
    |
    | `dia_limite_envio = null` desactiva la restricción.
    |
    */
    'dia_limite_envio' => env('FICHAS_DIA_LIMITE_ENVIO', 21),

    'zona_horaria' => env('FICHAS_TIMEZONE', 'America/Bogota'),

    /*
    |--------------------------------------------------------------------------
    | Alertas de vencimiento
    |--------------------------------------------------------------------------
    |
    | Umbrales en días para el semáforo de vigencia del dashboard.
    |
    */
    'vencimiento' => [
        'dias_aviso'   => 30,
        'dias_alerta'  => 15,
        'dias_critico' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Integración con el motor de flujos
    |--------------------------------------------------------------------------
    |
    | `habilitado`: registra cada decisión en `wf_instancias` / `wf_aprobaciones`.
    |   La máquina de estados de la ficha sigue siendo la fuente de verdad; el
    |   motor aporta trazabilidad transversal y notificaciones internas.
    |
    | `estricto`: si es true, un fallo del motor aborta la operación. Por
    |   defecto es false para que una parametrización incompleta del flujo no
    |   bloquee la operación del módulo.
    |
    */
    'workflow' => [
        'habilitado' => env('FICHAS_WORKFLOW_HABILITADO', true),
        'estricto'   => env('FICHAS_WORKFLOW_ESTRICTO', false),
        'modulo'     => 'fichas_tecnicas',
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles del módulo (Spatie)
    |--------------------------------------------------------------------------
    */
    'roles' => [
        'generador'      => 'generador-fichas',
        'autorizador'    => 'autorizador-fichas',
        'aprobador'      => 'aprobador-fichas',
        'parametrizador' => 'parametrizador-fichas',
        'visor'          => 'visor-fichas',
    ],

];
