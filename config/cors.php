<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */


   'paths' => ['api/*', 'auth/*', 'sanctum/csrf-cookie'],
   'allowed_methods' => ['*'],
   'allowed_origins' => [
       'http://localhost:4200', 
       'http://127.0.0.1:4200', 
       'http://192.168.1.9:8000',
       'https://jade.medilaser.com.co',
       'https://review-dose-reasonable-pointed.trycloudflare.com',
   ],
   'allowed_headers' => ['*'],

   // Headers de respuesta legibles desde JavaScript.
   //
   // CORS oculta por defecto TODO header personalizado: sin declararlo aqui,
   // response.headers.get('X-Export-Format') devuelve null en el navegador
   // aunque el servidor lo envie. El frontend (jade.medilaser.com.co) y esta
   // API (jade-api.medilaser.com.co) son origenes distintos, asi que aplica.
   //
   // Sin esto el viewer de vistas asumia 'xlsx' por defecto y le pasaba el
   // NDJSON.gz de `?as=data` a SheetJS, que lo leia como texto y pintaba la
   // grilla con basura binaria.
   'exposed_headers' => [
       'X-Export-Format',
       'X-Export-Rows',
       'Content-Disposition',
   ],
   'max_age' => 0,
   'supports_credentials' => true,

];


//http://localhost:8000/api/auth/login
