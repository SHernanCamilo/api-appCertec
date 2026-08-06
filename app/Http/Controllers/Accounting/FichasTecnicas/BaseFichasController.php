<?php

declare(strict_types=1);

namespace App\Http\Controllers\Accounting\FichasTecnicas;

use App\Exceptions\FichasTecnicas\ConflictoProfesionalesException;
use App\Exceptions\FichasTecnicas\TransicionEstadoInvalidaException;
use App\Exceptions\FichasTecnicas\VentanaEnvioCerradaException;
use App\Http\Controllers\Controller;
use App\Models\UsuarioContexto;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Base común de los controladores del módulo.
 *
 * Centraliza el mapeo de excepciones a códigos HTTP y la resolución del
 * contexto (empresa/sucursal/alcance) del usuario autenticado. El legacy
 * repetía el bloque `try/catch` + `Swal.fire` en cada archivo y decidía la
 * visibilidad comparando `$_SESSION['rol']` con cadenas literales.
 */
abstract class BaseFichasController extends Controller
{
    /** Roles del módulo (Spatie). */
    protected const ROL_GENERADOR   = 'generador-fichas';
    protected const ROL_AUTORIZADOR = 'autorizador-fichas';
    protected const ROL_APROBADOR   = 'aprobador-fichas';
    protected const ROL_PARAMETRIZADOR = 'parametrizador-fichas';

    /**
     * Ejecuta la acción traduciendo las excepciones de dominio a HTTP.
     *
     * @param  callable(): mixed  $accion
     */
    protected function ejecutar(callable $accion, string $mensajeError, int $exito = 200): JsonResponse
    {
        try {
            $resultado = $accion();

            if ($resultado instanceof JsonResponse) {
                return $resultado;
            }

            return response()->json([
                'success' => true,
                'data'    => $resultado,
            ], $exito);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $e->errors(),
            ], 422);
        } catch (ConflictoProfesionalesException $e) {
            // 409 Conflict: RN-02, el profesional está comprometido con otra
            // agremiación en el mismo rango de fechas.
            return response()->json([
                'success'    => false,
                'message'    => $e->getMessage(),
                'regla'      => 'RN-02',
                'bloqueos'   => $e->conflictosArray(),
                // Se conserva la clave anterior por compatibilidad del frontend.
                'conflictos' => $e->conflictosArray(),
            ], 409);
        } catch (VentanaEnvioCerradaException $e) {
            // 422: RN-03, la ventana de envío del mes está cerrada.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ] + $e->contexto(), 422);
        } catch (TransicionEstadoInvalidaException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'origen'  => $e->origen->value,
                'destino' => $e->destino->value,
            ], 422);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'El registro solicitado no existe.',
            ], 404);
        } catch (Throwable $e) {
            Log::channel('daily')->error("Fichas Técnicas: {$mensajeError}", [
                'error'   => $e->getMessage(),
                'archivo' => $e->getFile().':'.$e->getLine(),
                'user_id' => auth('api')->id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $mensajeError,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Respuesta estandarizada para resultados paginados.
     */
    protected function paginado(Paginator $paginador): JsonResponse
    {
        $meta = [
            'current_page' => $paginador->currentPage(),
            'per_page'     => $paginador->perPage(),
            'has_more'     => $paginador->hasMorePages(),
        ];

        if (method_exists($paginador, 'total')) {
            $meta['total']     = $paginador->total();
            $meta['last_page'] = $paginador->lastPage();
        }

        return response()->json([
            'success' => true,
            'data'    => $paginador->items(),
            'meta'    => $meta,
        ]);
    }

    /**
     * Contexto de alcance del usuario autenticado.
     *
     * Implementa la regla R15 del legacy sin exponer los filtros al cliente:
     *  - Generador (sin rol de validación): solo sus propias fichas.
     *  - Autorizador: las de su sucursal.
     *  - Aprobador / parametrizador: todas.
     *
     * @return array<string, mixed>
     */
    protected function contextoAlcance(Request $request): array
    {
        $user = auth('api')->user();

        if ($user === null) {
            return [];
        }

        $contexto = UsuarioContexto::query()->where('user_id', $user->id)->first();

        $filtros = [
            'user_id'    => $user->id,
            'id_empresa' => $request->integer('id_empresa') ?: $contexto?->empresa_id,
        ];

        $esAprobador = $this->tieneAlgunRol($user, [self::ROL_APROBADOR, self::ROL_PARAMETRIZADOR]);
        $esAutorizador = $this->tieneAlgunRol($user, [self::ROL_AUTORIZADOR]);

        if ($esAprobador) {
            // Alcance total: solo respeta los filtros explícitos del request.
            if ($request->filled('id_sucursal')) {
                $filtros['id_sucursal'] = $request->integer('id_sucursal');
            }

            return $filtros;
        }

        if ($esAutorizador) {
            $filtros['id_sucursal'] = $request->integer('id_sucursal') ?: $user->id_sucursal;

            return $filtros;
        }

        // Generador: solo lo propio.
        $filtros['solo_propias'] = true;
        $filtros['id_sucursal']  = $user->id_sucursal;

        return array_filter($filtros, static fn (mixed $v): bool => $v !== null && $v !== 0 && $v !== '');
    }

    /**
     * @param  list<string>  $roles
     */
    protected function tieneAlgunRol(mixed $user, array $roles): bool
    {
        try {
            return method_exists($user, 'hasAnyRole') && $user->hasAnyRole($roles);
        } catch (Throwable) {
            return false;
        }
    }

    protected function usuarioId(): int
    {
        return (int) auth('api')->id();
    }
}
