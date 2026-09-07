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
use Illuminate\Support\Facades\DB;
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
     * Jerarquía de visibilidad:
     *  - Aprobador / parametrizador : todas las fichas (sin filtro de sucursal).
     *  - Autorizador                : su(s) sucursal(es) asignadas en
     *                                 `fich_autorizador_sucursal` + tipo_alcance:
     *                                     nacional  → sin filtro
     *                                     regional  → por id_empresa
     *                                     sucursal  → lista de id_sucursal
     *  - Generador (cualquier otro) : solo sus propias fichas + su sucursal.
     *
     * Seguridad empresa (tarea 3):
     *   Si el request incluye `id_empresa`, se valida que ese ID pertenezca a
     *   las empresas del usuario autenticado (`seg_empresa_user`). Si no tiene
     *   acceso, se ignora el parámetro y se usa la empresa del contexto/JWT.
     *
     * @return array<string, mixed>
     */
    protected function contextoAlcance(Request $request): array
    {
        $user = auth('api')->user();

        if ($user === null) {
            return [];
        }

        $contexto  = UsuarioContexto::query()->where('user_id', $user->id)->first();
        $empresaJwt = $contexto?->empresa_id ?? $user->id_empresa ?? null;

        // ── Seguridad empresa: validar id_empresa del request ──────────────
        $idEmpresaRequest = $request->integer('id_empresa') ?: null;
        $idEmpresaSegura  = $this->resolverEmpresaSegura($user->id, $idEmpresaRequest, $empresaJwt);

        $filtros = [
            'user_id'    => $user->id,
            'id_empresa' => $idEmpresaSegura,
        ];

        $esAprobador   = $this->tieneAlgunRol($user, [self::ROL_APROBADOR, self::ROL_PARAMETRIZADOR]);
        $esAutorizador = $this->tieneAlgunRol($user, [self::ROL_AUTORIZADOR]);

        // ── Aprobador: alcance total ───────────────────────────────────────
        if ($esAprobador) {
            if ($request->filled('id_sucursal')) {
                $filtros['id_sucursal'] = $request->integer('id_sucursal');
            }

            return $filtros;
        }

        // ── Autorizador: alcance parametrizable ───────────────────────────
        if ($esAutorizador) {
            return $this->alcanceAutorizador($user->id, $filtros, $request);
        }

        // ── Generador: solo lo propio ─────────────────────────────────────
        $filtros['solo_propias'] = true;
        $filtros['id_sucursal']  = $user->id_sucursal;

        return array_filter($filtros, static fn (mixed $v): bool => $v !== null && $v !== 0 && $v !== '');
    }

    /**
     * Verifica que `id_empresa` del request esté en las empresas autorizadas
     * del usuario. Si no está, retorna la empresa del JWT/contexto.
     *
     * @return int|null
     */
    private function resolverEmpresaSegura(int $userId, ?int $idEmpresaRequest, mixed $empresaJwt): ?int
    {
        if ($idEmpresaRequest === null || $idEmpresaRequest === 0) {
            return $empresaJwt ? (int) $empresaJwt : null;
        }

        $tieneAcceso = DB::table('seg_empresa_user')
            ->where('user_id', $userId)
            ->where('empresa_id', $idEmpresaRequest)
            ->exists();

        if (! $tieneAcceso) {
            Log::warning('[FichasAlcance] id_empresa no autorizada ignorada', [
                'user_id'            => $userId,
                'id_empresa_request' => $idEmpresaRequest,
                'empresa_jwt'        => $empresaJwt,
            ]);

            return $empresaJwt ? (int) $empresaJwt : null;
        }

        return $idEmpresaRequest;
    }

    /**
     * Resuelve el alcance de un autorizador desde `fich_autorizador_sucursal`.
     *
     * Reglas (en orden de prioridad):
     *   1. Si tiene alguna fila con tipo_alcance = 'nacional' → sin filtro de empresa/sucursal.
     *   2. Si tiene tipo_alcance = 'regional'                 → filtra por id_empresa.
     *   3. Si tiene tipo_alcance = 'sucursal'                 → lista de id_sucursal.
     *   4. Fallback: usa id_sucursal directo del usuario.
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    private function alcanceAutorizador(int $userId, array $filtros, Request $request): array
    {
        $alcances = DB::table('fich_autorizador_sucursal')
            ->where('id_user', $userId)
            ->where('estado', true)
            ->get(['id_empresa', 'id_sucursal', 'tipo_alcance']);

        // Sin configuración: fallback a una sucursal
        if ($alcances->isEmpty()) {
            $idSuc = $request->integer('id_sucursal') ?: auth('api')->user()?->id_sucursal;
            $filtros['id_sucursal'] = $idSuc;

            return $filtros;
        }

        // Prioridad 1: si tiene algún 'nacional', ve todo
        if ($alcances->where('tipo_alcance', 'nacional')->isNotEmpty()) {
            if ($request->filled('id_sucursal')) {
                $filtros['id_sucursal'] = $request->integer('id_sucursal');
            }

            return $filtros;
        }

        // Prioridad 2: si tiene 'regional', filtra por empresa (sin id_sucursal)
        if ($alcances->where('tipo_alcance', 'regional')->isNotEmpty()) {
            // id_empresa ya está resuelto en $filtros; quitamos sucursal
            unset($filtros['id_sucursal']);

            return $filtros;
        }

        // Prioridad 3: 'sucursal' — lista de IDs asignados
        $sucursales = $alcances
            ->where('tipo_alcance', 'sucursal')
            ->pluck('id_sucursal')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        if (count($sucursales) === 1) {
            $filtros['id_sucursal'] = (int) $sucursales[0];
        } elseif (count($sucursales) > 1) {
            $filtros['id_sucursales'] = $sucursales; // plural → FichFichaService aplica whereIn
        }

        return $filtros;
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
