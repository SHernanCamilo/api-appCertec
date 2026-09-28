<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            // Si el log no se puede escribir (p.ej. storage/ con dueño root tras
            // correr artisan como root), NO dejar que el propio Log::error lance
            // otra excepcion y genere un fatal error en cascada que oculta la
            // causa real. Se intenta loguear; si falla, se ignora en silencio.
            try {
                Log::error('Exception caught:', [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'trace'   => $e->getTraceAsString(),
                ]);
            } catch (\Throwable $logError) {
                // No se pudo escribir el log (permisos u otro). Se descarta para
                // no enmascarar el error original con un fallo de logging.
            }
        });
    }
}