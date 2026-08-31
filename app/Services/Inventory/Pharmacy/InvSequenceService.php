<?php

namespace App\Services\Inventory\Pharmacy;

use App\Services\SecuenciaNumericaService;
use App\Models\Modulo;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Wrapper del sistema centralizado de secuencias numéricas (SecuenciaNumericaService)
 * adaptado para los documentos del módulo de Inventario.
 *
 * Resuelve automáticamente la empresa y sucursal del usuario autenticado
 * para generar consecutivos con el prefijo correcto de sucursal.
 */
class InvSequenceService
{
    public function __construct(
        private readonly SecuenciaNumericaService $secuenciaService
    ) {}

    /**
     * Genera el siguiente consecutivo para un tipo de documento de inventario.
     *
     * @param string $codigoModulo   Código del módulo en seg_modulos (ej: 'INVENTARIO')
     * @param int    $userId         ID del usuario autenticado (para resolver empresa/sucursal)
     * @param string|null $codigoProceso  Código del subproceso (ej: 'PEDIDO', 'ORDEN_COMPRA', 'RECEPCION')
     * @return string Consecutivo generado
     */
    public function generateSequence(string $codigoModulo, int $userId, ?string $codigoProceso = null, ?int $sucursalId = null): string
    {
        $user = User::find($userId);
        if (!$user) {
            throw new \RuntimeException("Usuario con ID {$userId} no encontrado.");
        }

        // Resolver empresa del usuario (primera empresa asociada o la del pivot)
        $empresaId = $this->resolveEmpresaId($user);
        if (!$empresaId) {
            throw new \RuntimeException("El usuario no tiene una empresa asignada.");
        }

        // Resolver sucursal: se prioriza la sucursal explícita (la que el usuario
        // eligió al sincronizar/crear). Esto evita que una OC de una sucursal quede
        // con el consecutivo de otra. Si no se indicó, se cae a la sucursal principal
        // del usuario.
        $sucursalId = $sucursalId ?: $user->id_sucursal;
        if (!$sucursalId) {
            throw new \RuntimeException("No se indicó sucursal y el usuario no tiene una sucursal asignada. Seleccione una sucursal para generar el consecutivo.");
        }

        // Obtener el ID del módulo por su código
        $moduloId = $this->resolveModuloId($codigoModulo);

        // Obtener el ID del proceso (submódulo) si se indica
        $procesoId = null;
        if ($codigoProceso) {
            $procesoId = $this->resolveModuloId($codigoProceso);
        }

        Log::info("Generando secuencia: módulo={$codigoModulo}(ID:{$moduloId}), proceso={$codigoProceso}, empresa={$empresaId}, sucursal={$sucursalId}");

        // Delegar al servicio centralizado que maneja lockForUpdate, patrones, etc.
        return $this->secuenciaService->generar($empresaId, $moduloId, $procesoId, $sucursalId);
    }

    /**
     * Previsualiza el siguiente consecutivo sin incrementar el contador.
     */
    public function preview(string $codigoModulo, int $userId, ?string $codigoProceso = null): string
    {
        $user = User::find($userId);
        if (!$user) {
            throw new \RuntimeException("Usuario no encontrado.");
        }

        $empresaId  = $this->resolveEmpresaId($user);
        $sucursalId = $user->id_sucursal;
        $moduloId   = $this->resolveModuloId($codigoModulo);
        $procesoId  = $codigoProceso ? $this->resolveModuloId($codigoProceso) : null;

        return $this->secuenciaService->previsualizar($empresaId, $moduloId, $procesoId, $sucursalId);
    }

    /**
     * Resuelve el ID de empresa del usuario.
     */
    private function resolveEmpresaId(User $user): ?int
    {
        // Intentar desde la relación empresas (pivot seg_empresa_user)
        $empresa = $user->empresas()->first();
        if ($empresa) {
            return $empresa->id;
        }

        return null;
    }

    /**
     * Resuelve el ID de un módulo/proceso por su código en seg_modulos.
     */
    private function resolveModuloId(string $codigo): int
    {
        $modulo = Modulo::where('codigo', $codigo)->first();
        if (!$modulo) {
            throw new \RuntimeException("No se encontró el módulo/proceso con código '{$codigo}' en seg_modulos.");
        }
        return $modulo->id;
    }
}