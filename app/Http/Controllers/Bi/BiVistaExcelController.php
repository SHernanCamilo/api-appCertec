<?php

namespace App\Http\Controllers\Bi;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BiVistaExcelController extends Controller
{
    public function openOnline(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:20480',
            'file_name' => 'nullable|string|max:120',
        ]);

        $appUrl = rtrim((string) config('app.url'), '/');
        if ($this->isLocalHost($appUrl)) {
            return response()->json([
                'success' => false,
                'message' => 'Excel Online requiere una URL pública HTTPS del API (APP_URL). localhost no es accesible para Microsoft Office.',
                'hint' => 'Configura APP_URL con un dominio público o túnel (ngrok) y ejecuta php artisan storage:link.',
            ], 422);
        }

        $uploadedFile = $request->file('file');
        $baseName = Str::slug((string) $request->input('file_name', 'vista-bi'), '_');
        $baseName = $baseName !== '' ? $baseName : 'vista-bi';
        $storedName = $baseName . '_' . now()->format('Ymd_His') . '_' . Str::uuid() . '.xlsx';

        Storage::disk('public')->makeDirectory('bi-vistas-temp');
        Storage::disk('public')->putFileAs('bi-vistas-temp', $uploadedFile, $storedName);

        $relativePath = 'bi-vistas-temp/' . $storedName;
        $publicUrl = $this->toPublicHttpsUrl(Storage::disk('public')->url($relativePath));
        $officeUrl = 'https://view.officeapps.live.com/op/view.aspx?src=' . urlencode($publicUrl);
        $officeEmbedUrl = 'https://view.officeapps.live.com/op/embed.aspx?src=' . urlencode($publicUrl);

        return response()->json([
            'success' => true,
            'message' => 'Archivo publicado para Excel Online',
            'data' => [
                'office_url' => $officeUrl,
                'office_embed_url' => $officeEmbedUrl,
                'file_url' => $publicUrl,
                'expires_in' => 3600,
            ],
        ]);
    }

    private function isLocalHost(string $appUrl): bool
    {
        $host = parse_url($appUrl, PHP_URL_HOST);

        if (!$host) {
            return true;
        }

        $host = strtolower($host);

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_starts_with($host, '192.168.')
            || str_starts_with($host, '10.');
    }

    private function toPublicHttpsUrl(string $url): string
    {
        if (str_starts_with($url, 'http://')) {
            return 'https://' . substr($url, 7);
        }

        return $url;
    }
}
