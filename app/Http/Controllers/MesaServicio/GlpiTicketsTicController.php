<?php

declare(strict_types=1);

namespace App\Http\Controllers\MesaServicio;

use App\Http\Controllers\Controller;
use App\Services\MesaServicio\GlpiTicketsTicService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class GlpiTicketsTicController extends Controller
{
    public function __construct(private GlpiTicketsTicService $tablero)
    {
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $data = $this->tablero->tablero($request->boolean('fresh'));

            return response()->json([
                'success' => true,
                'data' => $data,
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Error al leer tablero TIC GLPI: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'No se pudieron obtener los tickets de TIC en GLPI.',
            ], 502);
        }
    }
}
