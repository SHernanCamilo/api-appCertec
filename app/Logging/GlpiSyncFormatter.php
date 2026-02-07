<?php

namespace App\Logging;

use Monolog\Logger;
use Monolog\Formatter\LineFormatter;
use Illuminate\Log\Logger as IlluminateLogger;

class GlpiSyncFormatter
{
    /**
     * Customize the given logger instance.
     */
    public function __invoke($logger)
    {
        // Si es un logger de Illuminate, obtener el logger de Monolog
        if ($logger instanceof IlluminateLogger) {
            $monologLogger = $logger->getLogger();
        } else {
            $monologLogger = $logger;
        }

        foreach ($monologLogger->getHandlers() as $handler) {
            $handler->setFormatter(new LineFormatter(
                "[%datetime%] ActivosGLPI.%level_name%: %message% %context% %extra%\n",
                'Y-m-d H:i:s',
                true,
                true
            ));
        }
    }
}