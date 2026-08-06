<?php

declare(strict_types=1);

namespace App\Services\Accounting\FichasTecnicas;

use App\DTO\FichasTecnicas\CrearFichaDTO;
use App\DTO\FichasTecnicas\DetalleFichaDTO;
use App\Enums\FichasTecnicas\EstadoFicha;
use App\Models\Accounting\FichasTecnicas\FichDetalle;
use App\Models\Accounting\FichasTecnicas\FichFicha;
use App\Models\Accounting\FichasTecnicas\FichObservacion;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * CRUD y consultas de fichas técnicas.
 *
 * Sustituye la lógica dispersa de `generador/acciones/*.php` y las 12
 * funciones `listar*()` duplicadas en `config/config_*.php`.
 */
final class FichFichaService
{
    /**
     * Alertas informativas (RN-01) detectadas en la última escritura.
     *
     * Se conservan para que el controlador las devuelva al cliente junto con la
     * ficha creada o actualizada, sin necesidad de repetir la consulta.
     *
     * @var list<\App\DTO\FichasTecnicas\ConflictoProfesionalDTO>
     */
    private array $alertasUltimaValidacion = [];

    public function __construct(
        private readonly FichConflictoService $conflictos,
        private readonly FichAuditoriaService $auditoria,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function alertasPendientes(): array
    {
        return array_map(
            static fn ($alerta) => $alerta->toArray(),
            $this->alertasUltimaValidacion
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Consultas
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Listado paginado con filtros.
     *
     * @param  array<string, mixed>  $filtros
     */
    public function listar(array $filtros = []): Paginator
    {
        $query = FichFicha::query()->paraListado();

        $this->aplicarFiltroBandeja($query, (string) ($filtros['bandeja'] ?? ''));
        $this->aplicarFiltroAlcance($query, $filtros);

        if (! empty($filtros['id_agremiacion'])) {
            $query->where('id_agremiacion', (int) $filtros['id_agremiacion']);
        }

        if (! empty($filtros['id_especialidad'])) {
            $query->where('id_especialidad', (int) $filtros['id_especialidad']);
        }

        if (! empty($filtros['id_estado'])) {
            $query->whereIn('id_estado', array_map('intval', (array) $filtros['id_estado']));
        }

        if (! empty($filtros['desde'])) {
            $query->whereDate('fecha_ini', '>=', (string) $filtros['desde']);
        }

        if (! empty($filtros['hasta'])) {
            $query->whereDate('fecha_fin', '<=', (string) $filtros['hasta']);
        }

        $buscar = trim((string) ($filtros['buscar'] ?? ''));
        if ($buscar !== '') {
            $query->where(function (Builder $q) use ($buscar): void {
                $q->where('consecutivo', 'like', "%{$buscar}%")
                    ->orWhereHas('agremiacion', fn (Builder $a) => $a->where('nombre', 'like', "%{$buscar}%"))
                    ->orWhereHas('especialidad', fn (Builder $e) => $e->where('descripcion', 'like', "%{$buscar}%"));
            });
        }

        $perPage = (int) ($filtros['per_page'] ?? 20);
        $perPage = min(max($perPage, 5), 200);

        return $query->orderByDesc('id')->paginate($perPage);
    }

    /** Ficha completa con todas sus relaciones (vista de detalle y PDF). */
    public function obtener(int $id): FichFicha
    {
        return FichFicha::query()
            ->with([
                'estado',
                'agremiacion',
                'especialidad',
                'objetoContrato',
                'empresa',
                'sucursal',
                'generador:id,name,email',
                'autorizador:id,name,email',
                'aprobador:id,name,email',
                'profesionales',
                'detalles.obsItem',
                'detalles.tipoServicioRelacion',
                'observaciones',
                'comentarios.usuario:id,name,email',
                'padre:id,consecutivo,fecha_ini,fecha_fin,vlr_contrato,id_estado',
                'versiones:id,id_padre,consecutivo,version,id_estado,fecha_ini,fecha_fin',
                'reemplazadaPor:id,consecutivo,version,id_estado,fecha_ini,fecha_fin',
            ])
            ->findOrFail($id);
    }

    /**
     * Detalles enriquecidos desde la vista SQL (CUPS, homólogo y observación
     * resueltos en un solo JOIN, como necesita el PDF).
     *
     * @return Collection<int, object>
     */
    public function detallesEnriquecidos(int $idFicha): \Illuminate\Support\Collection
    {
        return DB::table('v_fich_detalles_completo')
            ->where('id_ficha', $idFicha)
            ->orderBy('cups')
            ->get();
    }

    /**
     * Cadena completa de versiones de una ficha, con su vigencia.
     *
     * Sube hasta la ficha raíz y baja por todas sus actualizaciones, de forma
     * que la respuesta es la misma independientemente de qué versión se consulte.
     *
     * @return array<string, mixed>
     */
    public function cadenaDeVersiones(int $idFicha): array
    {
        $ficha = FichFicha::query()->findOrFail($idFicha);

        // La raíz es la ficha original del árbol de versiones.
        $raizId = $ficha->id_padre ?? $ficha->id;

        $versiones = FichFicha::query()
            ->with(['estado:id,codigo,descripcion,color_hex', 'generador:id,name'])
            ->where('id', $raizId)
            ->orWhere('id_padre', $raizId)
            ->orderBy('version')
            ->get([
                'id', 'consecutivo', 'id_padre', 'reemplazada_por_id', 'version',
                'id_estado', 'fecha_ini', 'fecha_fin', 'vlr_contrato',
                'fecha_reg', 'fecha_aprueba', 'fecha_vigencia_inicio',
                'obs_os', 'motivo_modificacion', 'ciclos_flujo', 'id_user_reg',
            ]);

        $vigente = $versiones->first(
            static fn (FichFicha $f): bool => in_array($f->estadoEnum(), EstadoFicha::vigentesEstados(), true)
                && $f->reemplazada_por_id === null
        );

        return [
            'raiz_id'         => $raizId,
            'total_versiones' => $versiones->count(),
            'version_vigente' => $vigente?->id,
            'consultada'      => $ficha->id,
            'versiones'       => $versiones,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Escritura
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Crea una ficha en estado borrador con sus profesionales.
     *
     * Equivalente a `generador/acciones/insertar.php`, pero transaccional:
     * el legacy insertaba la ficha, luego los profesionales y, si esto último
     * fallaba, ejecutaba un `DELETE FROM ficha` manual como compensación.
     */
    public function crear(CrearFichaDTO $dto): FichFicha
    {
        // RN-02 bloquea si algún profesional está comprometido con otra
        // agremiación; RN-01 solo devuelve alertas informativas.
        $this->alertasUltimaValidacion = $this->conflictos->validar(
            $dto->profesionales,
            $dto->fechaIni->toDateString(),
            $dto->fechaFin->toDateString(),
            null,
            $dto->idAgremiacion
        );

        return DB::transaction(function () use ($dto): FichFicha {
            $this->auditoria->marcarUsuario($dto->idUserReg, 'Creación de la ficha');

            $estadoInicial = $dto->esActualizacion()
                ? EstadoFicha::OsBorrador
                : EstadoFicha::Borrador;

            $atributos = $dto->toModelAttributes() + [
                'id_estado' => $estadoInicial->id(),
                'fecha_reg' => now(),
                'version'   => 1,
            ];

            if ($dto->esActualizacion()) {
                $padre = FichFicha::query()->findOrFail($dto->idPadre);
                $atributos['version'] = $this->siguienteVersion((int) $padre->id);
            }

            $ficha = FichFicha::query()->create($atributos);

            if ($dto->profesionales !== []) {
                $ficha->profesionales()->attach($dto->profesionales);
            }

            return $ficha->refresh();
        });
    }

    /**
     * Actualiza la cabecera de una ficha editable.
     *
     * @param  array<string, mixed>  $data
     */
    public function actualizar(int $id, array $data, int $usuarioId): FichFicha
    {
        $ficha = FichFicha::query()->findOrFail($id);

        $this->garantizarEditable($ficha);

        $fechaIni = (string) ($data['fecha_ini'] ?? $ficha->fecha_ini->toDateString());
        $fechaFin = (string) ($data['fecha_fin'] ?? $ficha->fecha_fin->toDateString());

        $profesionales = isset($data['profesionales'])
            ? array_values(array_unique(array_map('intval', (array) $data['profesionales'])))
            : $ficha->profesionales()->pluck('fich_profesionales.id')->map('intval')->all();

        // Se excluye la propia ficha: el legacy no lo hacía y bloqueaba su propia edición.
        $this->alertasUltimaValidacion = $this->conflictos->validar(
            $profesionales,
            $fechaIni,
            $fechaFin,
            $ficha->id,
            (int) ($data['id_agremiacion'] ?? $ficha->id_agremiacion)
        );

        return DB::transaction(function () use ($ficha, $data, $profesionales, $usuarioId): FichFicha {
            $this->auditoria->marcarUsuario($usuarioId, 'Actualización de la ficha');

            $ficha->fill(array_intersect_key($data, array_flip([
                'id_agremiacion',
                'id_objeto_contrato',
                'id_especialidad',
                'vlr_contrato',
                'fecha_ini',
                'fecha_fin',
                'id_sucursal',
                'sucursal_legacy',
                'obs_os',
                'novedad',
            ])));

            $ficha->save();

            if (isset($data['profesionales'])) {
                $ficha->profesionales()->sync($profesionales);
            }

            return $ficha->refresh();
        });
    }

    /**
     * Cancelación lógica (legacy: estado 7 "ELIMINADA", nunca DELETE físico).
     */
    public function cancelar(int $id, int $usuarioId, ?string $motivo = null): FichFicha
    {
        $ficha   = FichFicha::query()->findOrFail($id);
        $actual  = $ficha->estadoEnum();
        $destino = $actual->estadoAlCancelar();

        if (! $actual->puedeTransicionarA($destino)) {
            throw new RuntimeException(
                "No se puede cancelar una ficha en estado \"{$actual->label()}\"."
            );
        }

        return DB::transaction(function () use ($ficha, $usuarioId, $motivo, $destino): FichFicha {
            $this->auditoria->marcarUsuario($usuarioId, $motivo ?? 'Ficha cancelada');
            $ficha->update(['id_estado' => $destino->id()]);

            return $ficha->refresh();
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Detalles (paso 2 del generador)
    // ─────────────────────────────────────────────────────────────────────

    public function agregarDetalle(int $idFicha, DetalleFichaDTO $dto, int $usuarioId): FichDetalle
    {
        $ficha = FichFicha::query()->findOrFail($idFicha);
        $this->garantizarEditable($ficha);

        $this->auditoria->marcarUsuario($usuarioId, 'Servicio agregado');

        return FichDetalle::query()->create(
            $dto->toModelAttributes() + ['id_ficha' => $ficha->id]
        );
    }

    /**
     * Inserta varios servicios en una sola transacción.
     *
     * @param  list<DetalleFichaDTO>  $detalles
     * @return list<FichDetalle>
     */
    public function agregarDetalles(int $idFicha, array $detalles, int $usuarioId): array
    {
        $ficha = FichFicha::query()->findOrFail($idFicha);
        $this->garantizarEditable($ficha);

        return DB::transaction(function () use ($ficha, $detalles, $usuarioId): array {
            $this->auditoria->marcarUsuario($usuarioId, 'Servicios agregados');

            $creados = [];
            foreach ($detalles as $dto) {
                $creados[] = FichDetalle::query()->create(
                    $dto->toModelAttributes() + ['id_ficha' => $ficha->id]
                );
            }

            return $creados;
        });
    }

    public function actualizarDetalle(int $idDetalle, DetalleFichaDTO $dto, int $usuarioId): FichDetalle
    {
        $detalle = FichDetalle::query()->with('ficha')->findOrFail($idDetalle);
        $this->garantizarEditable($detalle->ficha);

        $this->auditoria->marcarUsuario($usuarioId, 'Servicio modificado');
        $detalle->update($dto->toModelAttributes());

        return $detalle->refresh();
    }

    public function eliminarDetalle(int $idDetalle, int $usuarioId): void
    {
        $detalle = FichDetalle::query()->with('ficha')->findOrFail($idDetalle);
        $this->garantizarEditable($detalle->ficha);

        $this->auditoria->marcarUsuario($usuarioId, 'Servicio eliminado');
        $detalle->delete();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Observaciones (paso 3 del generador)
    // ─────────────────────────────────────────────────────────────────────

    public function agregarObservacion(int $idFicha, string $texto, int $usuarioId): FichObservacion
    {
        $ficha = FichFicha::query()->findOrFail($idFicha);
        $this->garantizarEditable($ficha);

        return FichObservacion::query()->create([
            'id_ficha'        => $ficha->id,
            'desc_obs'        => $texto,
            'usuario_crea_id' => $usuarioId,
        ]);
    }

    public function eliminarObservacion(int $idObservacion): void
    {
        $observacion = FichObservacion::query()->with('ficha')->findOrFail($idObservacion);
        $this->garantizarEditable($observacion->ficha);
        $observacion->delete();
    }

    // ─────────────────────────────────────────────────────────────────────
    // Profesionales
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  list<int>  $idsProfesionales
     */
    public function sincronizarProfesionales(int $idFicha, array $idsProfesionales, int $usuarioId): FichFicha
    {
        $ficha = FichFicha::query()->findOrFail($idFicha);
        $this->garantizarEditable($ficha);

        $ids = array_values(array_unique(array_map('intval', $idsProfesionales)));

        $this->alertasUltimaValidacion = $this->conflictos->validar(
            $ids,
            $ficha->fecha_ini->toDateString(),
            $ficha->fecha_fin->toDateString(),
            $ficha->id,
            (int) $ficha->id_agremiacion
        );

        return DB::transaction(function () use ($ficha, $ids, $usuarioId): FichFicha {
            $this->auditoria->marcarUsuario($usuarioId, 'Profesionales actualizados');
            $ficha->profesionales()->sync($ids);

            return $ficha->refresh();
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Actualizaciones (OS)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Crea una nueva versión (OS) a partir de una ficha aprobada o vigente.
     *
     * Es el mecanismo de modificación de fichas ya formalizadas: en lugar de
     * editar en sitio, se clona la ficha, se aplican los cambios y la nueva
     * versión recorre el flujo de aprobación completo. La versión anterior
     * conserva su vigencia y su trazabilidad hasta que la nueva sea aprobada.
     *
     * El legacy replicaba este proceso a lo largo de cuatro formularios
     * (`form-os1` … `form-os4`) con inserciones sueltas. Aquí es una sola
     * operación atómica.
     *
     * @param  array<string, mixed>  $cambios
     */
    public function crearActualizacion(int $idFichaPadre, array $cambios, int $usuarioId): FichFicha
    {
        $padre = FichFicha::query()->with(['detalles', 'profesionales'])->findOrFail($idFichaPadre);

        if (! $padre->estadoEnum()->permiteSolicitarModificacion()) {
            throw new RuntimeException(sprintf(
                'Solo se puede crear una actualización a partir de una ficha aprobada o vigente. '
                .'La ficha está en estado "%s".',
                $padre->estadoEnum()->label()
            ));
        }

        if ($padre->reemplazada_por_id !== null) {
            throw new RuntimeException(
                'Esta ficha ya fue reemplazada por una versión posterior. '
                .'Solicite la modificación sobre la versión vigente.'
            );
        }

        if (trim((string) ($cambios['obs_os'] ?? '')) === '') {
            throw new RuntimeException('La descripción del cambio (obs_os) es obligatoria en una actualización.');
        }

        $fechaIni = (string) ($cambios['fecha_ini'] ?? $padre->fecha_ini->toDateString());
        $fechaFin = (string) ($cambios['fecha_fin'] ?? $padre->fecha_fin->toDateString());

        $profesionales = isset($cambios['profesionales'])
            ? array_values(array_unique(array_map('intval', (array) $cambios['profesionales'])))
            : $padre->profesionales->pluck('id')->map('intval')->all();

        $idAgremiacion = (int) ($cambios['id_agremiacion'] ?? $padre->id_agremiacion);

        // Se excluye la ficha padre: es la que se está reemplazando.
        $this->alertasUltimaValidacion = $this->conflictos->validar(
            $profesionales,
            $fechaIni,
            $fechaFin,
            $padre->id,
            $idAgremiacion
        );

        return DB::transaction(function () use ($padre, $cambios, $fechaIni, $fechaFin, $profesionales, $usuarioId): FichFicha {
            $this->auditoria->marcarUsuario($usuarioId, 'Actualización (OS) generada');

            $version = $this->siguienteVersion((int) $padre->id);

            $nueva = FichFicha::query()->create([
                'id_padre'           => $padre->id,
                'version'            => $version,
                'id_empresa'         => $padre->id_empresa,
                'id_sucursal'        => $padre->id_sucursal,
                'sucursal_legacy'    => $padre->sucursal_legacy,
                'id_agremiacion'     => (int) ($cambios['id_agremiacion'] ?? $padre->id_agremiacion),
                'id_objeto_contrato' => (int) ($cambios['id_objeto_contrato'] ?? $padre->id_objeto_contrato),
                'id_especialidad'    => (int) ($cambios['id_especialidad'] ?? $padre->id_especialidad),
                'vlr_contrato'       => $cambios['vlr_contrato'] ?? $padre->vlr_contrato,
                'fecha_ini'          => $fechaIni,
                'fecha_fin'          => $fechaFin,
                'id_estado'          => EstadoFicha::OsBorrador->id(),
                'id_user_reg'        => $usuarioId,
                'fecha_reg'          => now(),
                'ciclos_flujo'       => 0,
                'obs_os'             => (string) $cambios['obs_os'],
                'motivo_modificacion' => isset($cambios['motivo_modificacion'])
                    ? (string) $cambios['motivo_modificacion']
                    : (string) $cambios['obs_os'],
            ]);

            // Clonar los servicios de la ficha padre salvo que se envíen nuevos.
            $detalles = isset($cambios['detalles'])
                ? DetalleFichaDTO::collection((array) $cambios['detalles'])
                : $padre->detalles->map(fn (FichDetalle $d): DetalleFichaDTO => DetalleFichaDTO::fromArray(
                    $d->only([
                        'tipo_liquidacion', 'tipo_servicio', 'id_tipo_servicio', 'cups',
                        'grupo', 'subgrupo', 'forma_pago', 'homologo', 'variacion',
                        'valor', 'id_obs_item',
                    ])
                ))->all();

            foreach ($detalles as $dto) {
                FichDetalle::query()->create($dto->toModelAttributes() + ['id_ficha' => $nueva->id]);
            }

            if ($profesionales !== []) {
                $nueva->profesionales()->attach($profesionales);
            }

            return $nueva->refresh();
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helpers privados
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Número de versión de la siguiente actualización de una ficha.
     *
     * La ficha original es la versión 1, por lo que la primera OS es la 2.
     * Coincide con el sufijo del consecutivo que genera
     * `sp_fich_siguiente_version_os` en el momento de la aprobación.
     */
    private function siguienteVersion(int $idPadre): int
    {
        return (int) FichFicha::query()->where('id_padre', $idPadre)->count() + 2;
    }

    private function garantizarEditable(FichFicha $ficha): void
    {
        $estado = $ficha->estadoEnum();

        if (! $estado->esEditable()) {
            throw new RuntimeException(
                "La ficha está en estado \"{$estado->label()}\" y no admite modificaciones."
            );
        }
    }

    /** Traduce el nombre de bandeja del frontend a un scope del modelo. */
    private function aplicarFiltroBandeja(Builder $query, string $bandeja): void
    {
        match ($bandeja) {
            'borradores'      => $query->borradores(),
            'procesando'      => $query->enProceso(),
            'por-autorizar'   => $query->porAutorizar(),
            'por-aprobar'     => $query->porAprobar(),
            // La bandeja de devoluciones conserva el nombre histórico para no
            // romper los enlaces del frontend.
            'rechazados',
            'correccion-requerida' => $query->rechazadas(),
            'aprobadas'       => $query->aprobadas(),
            'finalizadas',
            'vigentes'        => $query->vigentes(),
            'vencidas'        => $query->vencidas(),
            'proximas-vencer' => $query->proximasAVencer(),
            'canceladas'      => $query->canceladas(),
            default           => null,
        };
    }

    /**
     * Alcance de visibilidad (regla R15 del legacy):
     *  - Generador: solo sus propias fichas.
     *  - Autorizador: las de su sucursal.
     *  - Aprobador / administrador: todas.
     *
     * @param  array<string, mixed>  $filtros
     */
    private function aplicarFiltroAlcance(Builder $query, array $filtros): void
    {
        if (! empty($filtros['solo_propias']) && ! empty($filtros['user_id'])) {
            $query->delGenerador((int) $filtros['user_id']);
        }

        if (! empty($filtros['id_empresa'])) {
            $query->deEmpresa((int) $filtros['id_empresa']);
        }

        if (! empty($filtros['id_sucursal'])) {
            $query->deSucursal((int) $filtros['id_sucursal']);
        } elseif (! empty($filtros['sucursal_legacy'])) {
            $query->deSucursal((string) $filtros['sucursal_legacy']);
        }
    }
}
