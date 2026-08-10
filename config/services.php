<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
        'tenant' => env('MICROSOFT_TENANT_ID', 'common'), // 'common' para multi-tenant
    ],

    'glpi' => [
        'base_url' => env('GLPI_BASE_URL', 'http://localhost/glpi/apirest.php'),
        'user_token' => env('GLPI_USER_TOKEN'),
        'app_token' => env('GLPI_APP_TOKEN'),
        'timeout' => env('GLPI_TIMEOUT', 30),
    ],

    'festivos' => [
        'key' => env('FESTIVOS_API_KEY'),
        'provider' => env('FESTIVOS_PROVIDER', 'festivos_com_co'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Microsoft Graph API — Permisos de Aplicación (Client Credentials)
    |--------------------------------------------------------------------------
    |
    | Usado para acceder a OneDrive/SharePoint sin delegación de usuario.
    | Requiere App Registration en Azure con:
    |   - API Permissions: Sites.ReadWrite.All (Application) + Admin Consent
    |   - O alternativamente: Files.ReadWrite.All (Application) + Admin Consent
    |
    | drive_id: ID del drive de SharePoint o OneDrive donde guardar archivos.
    |   Para obtenerlo: GET /sites/{siteId}/drive → response.id
    |
    | site_id: ID del SharePoint site (alternativa a drive_id).
    |   Para obtenerlo: GET /sites/{hostname}:/{path} → response.id
    |
    */
    'microsoft_graph' => [
        'tenant_id' => env('GRAPH_TENANT_ID', env('MICROSOFT_MEDILASER_TENANT_ID')),
        'client_id' => env('GRAPH_CLIENT_ID', env('MICROSOFT_CLIENT_ID')),
        'client_secret' => env('GRAPH_CLIENT_SECRET', env('MICROSOFT_CLIENT_SECRET')),
        'drive_id' => env('GRAPH_DRIVE_ID', ''),
        'site_id' => env('GRAPH_SITE_ID', ''),
        'base_path' => env('GRAPH_BASE_PATH', 'Anticipos'),
    ],

];
