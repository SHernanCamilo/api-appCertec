<?php

declare(strict_types=1);

namespace App\Services\Fabric\Export;

/**
 * Resultado de un export ya escrito en disco.
 */
final readonly class ExportResult
{
    public function __construct(
        public string $path,
        public string $filename,
        /** 'xlsx' o 'csv' */
        public string $format,
        public int $rows,
        public int $bytes,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === 0;
    }

    /**
     * Tamaño legible para mostrar en el frontend.
     */
    public function humanSize(): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $size  = (float) $this->bytes;
        $unit  = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return round($size, 2) . ' ' . $units[$unit];
    }
}
