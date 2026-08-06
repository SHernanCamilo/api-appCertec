<?php

declare(strict_types=1);

namespace Tests\Feature\FichasTecnicas;

use App\DTO\FichasTecnicas\CrearFichaDTO;
use App\DTO\FichasTecnicas\DetalleFichaDTO;
use App\Enums\FichasTecnicas\EstadoFicha;
use App\Exceptions\FichasTecnicas\ConflictoProfesionalesException;
use App\Exceptions\FichasTecnicas\TransicionEstadoInvalidaException;
use App\Exceptions\FichasTecnicas\VentanaEnvioCerradaException;
use App\Models\Accounting\FichasTecnicas\FichFicha;
use App\Services\Accounting\FichasTecnicas\FichConsecutivoService;
use App\Services\Accounting\FichasTecnicas\FichFichaService;
use App\Services\Accounting\FichasTecnicas\FichValidacionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Flujo completo de una ficha técnica sobre la base de datos real
 * (triggers, procedimientos y vistas incluidos).
 *
 *   borrador → pendiente_autorizacion → pendiente_revision_financiera
 *            → aprobada → vigente
 *
 * Usa DatabaseTransactions para no requerir `migrate:fresh`: el esquema
 * `fich_*` y sus rutinas ya están instalados por las migraciones.
 */
final class FichFlujoFichaTest extends TestCase
{
    use DatabaseTransactions;

    private FichFichaService $fichas;

    private FichValidacionService $validacion;

    private FichConsecutivoService $consecutivos;

    private int $userId;

    private int $empresaId;

    private int $agremiacionId;

    private int $agremiacionAlternaId;

    private int $objetoId;

    private int $especialidadId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        // RN-03 se desactiva por defecto: cada prueba del flujo debe ser
        // independiente del día del mes en que se ejecute la suite.
        config(['fichas_tecnicas.dia_limite_envio' => null]);

        $this->fichas       = app(FichFichaService::class);
        $this->validacion   = app(FichValidacionService::class);
        $this->consecutivos = app(FichConsecutivoService::class);

        $this->userId    = (int) DB::table('users')->value('id');
        $this->empresaId = (int) DB::table('ent_empresas')->value('id');

        if ($this->userId === 0 || $this->empresaId === 0) {
            $this->markTestSkipped('Se requieren usuarios y empresas en la base para esta prueba.');
        }

        $this->agremiacionId        = $this->nuevaAgremiacion();
        $this->agremiacionAlternaId = $this->nuevaAgremiacion();

        $this->objetoId = (int) DB::table('fich_objetos_contrato')->insertGetId([
            'descripcion' => 'OBJETO TEST', 'estado' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->especialidadId = (int) DB::table('fich_especialidades')->insertGetId([
            'descripcion' => 'ESPECIALIDAD TEST '.uniqid(), 'perfil' => 'CIRUJANO',
            'estado' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function nuevaAgremiacion(): int
    {
        return (int) DB::table('fich_agremiaciones')->insertGetId([
            'nombre' => 'AGREMIACION TEST '.uniqid(),
            'nit'    => (string) random_int(800000000, 899999999),
            'estado' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function nuevoProfesional(): int
    {
        $id = (int) DB::table('fich_profesionales')->insertGetId([
            'documento' => 'T'.uniqid(), 'nombre' => 'PROFESIONAL TEST', 'estado' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('fich_profesional_especialidad')->insert([
            'id_profesional' => $id, 'id_especialidad' => $this->especialidadId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @param  list<int>  $profesionales
     */
    private function crearFicha(
        array $profesionales,
        string $ini,
        string $fin,
        float $valor = 1000000,
        ?int $agremiacionId = null,
    ): FichFicha {
        return $this->fichas->crear(CrearFichaDTO::fromArray([
            'id_agremiacion'     => $agremiacionId ?? $this->agremiacionId,
            'id_objeto_contrato' => $this->objetoId,
            'id_especialidad'    => $this->especialidadId,
            'vlr_contrato'       => $valor,
            'fecha_ini'          => $ini,
            'fecha_fin'          => $fin,
            'profesionales'      => $profesionales,
            'id_user_reg'        => $this->userId,
            'id_empresa'         => $this->empresaId,
            'sucursal_legacy'    => 'NEIVA',
        ]));
    }

    /** Ficha lista para entrar al flujo (con al menos un servicio). */
    private function fichaConServicio(array $profesionales, string $ini, string $fin, ?int $agremiacion = null): FichFicha
    {
        $ficha = $this->crearFicha($profesionales, $ini, $fin, 1000000, $agremiacion);

        $this->fichas->agregarDetalle(
            $ficha->id,
            DetalleFichaDTO::fromArray(['cups' => '470101', 'valor' => 500000]),
            $this->userId
        );

        return $ficha->refresh();
    }

    /** Recorre el flujo completo hasta dejar la ficha aprobada. */
    private function aprobarFicha(FichFicha $ficha): FichFicha
    {
        $this->validacion->enviar($ficha->id, $this->userId);
        $this->validacion->autorizar($ficha->id, $this->userId, 'Autorizado en prueba');

        return $this->validacion->aprobar($ficha->id, $this->userId, 'Aprobado en prueba');
    }

    // ── Creación y contadores ───────────────────────────────────────────

    public function testCreaLaFichaEnBorradorConSusProfesionales(): void
    {
        $prof  = $this->nuevoProfesional();
        $ficha = $this->crearFicha([$prof], '2035-01-01', '2035-12-31');

        $this->assertSame(EstadoFicha::Borrador->id(), $ficha->id_estado);
        $this->assertSame(1, $ficha->total_profesionales, 'El trigger debe contar el profesional');
        $this->assertNull($ficha->consecutivo, 'El consecutivo solo se asigna al aprobar');
        $this->assertSame(0, (int) $ficha->ciclos_flujo);
    }

    /** Los contadores los mantienen los triggers, no el código PHP. */
    public function testLosTriggersMantienenLosContadoresDeDetalle(): void
    {
        $ficha = $this->crearFicha([$this->nuevoProfesional()], '2035-01-01', '2035-12-31');

        $this->fichas->agregarDetalles($ficha->id, DetalleFichaDTO::collection([
            ['cups' => '470101', 'valor' => 300000],
            ['cups' => '890201', 'valor' => 200000],
        ]), $this->userId);

        $ficha->refresh();
        $this->assertSame(2, $ficha->total_detalles);
        $this->assertSame('500000.00', $ficha->valor_total_detalles);

        $detalle = $ficha->detalles()->first();
        $this->fichas->actualizarDetalle(
            $detalle->id,
            DetalleFichaDTO::fromArray(['cups' => '470101', 'valor' => 350000]),
            $this->userId
        );

        $ficha->refresh();
        $this->assertSame('550000.00', $ficha->valor_total_detalles);

        $this->fichas->eliminarDetalle($detalle->id, $this->userId);
        $ficha->refresh();
        $this->assertSame(1, $ficha->total_detalles);
        $this->assertSame('200000.00', $ficha->valor_total_detalles);
    }

    // ── Flujo de aprobación ─────────────────────────────────────────────

    public function testElFlujoCompletoAsignaConsecutivoYTrazabilidad(): void
    {
        $ficha = $this->fichaConServicio([$this->nuevoProfesional()], '2035-01-01', '2035-12-31');

        $ficha = $this->validacion->enviar($ficha->id, $this->userId);
        $this->assertSame(EstadoFicha::PendienteAutorizacion->id(), $ficha->id_estado);
        $this->assertNotNull($ficha->fecha_envio_flujo);
        $this->assertSame(1, (int) $ficha->ciclos_flujo);

        $ficha = $this->validacion->autorizar($ficha->id, $this->userId, 'Autorizado en prueba');
        $this->assertSame(EstadoFicha::PendienteRevisionFinanciera->id(), $ficha->id_estado);
        $this->assertSame($this->userId, $ficha->user_autoriza_id);
        $this->assertNotNull($ficha->fecha_autoriza);

        $ficha = $this->validacion->aprobar($ficha->id, $this->userId, 'Aprobado en prueba');
        $this->assertSame(EstadoFicha::Aprobada->id(), $ficha->id_estado);
        $this->assertNotNull($ficha->consecutivo);
        $this->assertSame($this->userId, $ficha->user_aprueba_id);

        // Un comentario por cada paso del flujo: envío + autorización + aprobación
        $this->assertSame(3, $ficha->comentarios()->count());

        // Bitácora escrita por trigger: creación + 3 cambios de estado
        $historial = $ficha->historialEstados()->get();
        $this->assertGreaterThanOrEqual(4, $historial->count());
    }

    /**
     * Una ficha aprobada cuya vigencia ya arrancó pasa a vigente en el momento
     * de la aprobación, sin esperar al comando programado.
     */
    public function testAprobarUnaFichaConVigenciaIniciadaLaDejaVigente(): void
    {
        $ficha = $this->fichaConServicio(
            [$this->nuevoProfesional()],
            now()->subDay()->toDateString(),
            now()->addYear()->toDateString()
        );

        $ficha = $this->aprobarFicha($ficha);

        $this->assertSame(EstadoFicha::Vigente->id(), $ficha->id_estado);
        $this->assertNotNull($ficha->fecha_vigencia_inicio);
    }

    public function testNoPermiteSaltarLaAutorizacion(): void
    {
        $ficha = $this->fichaConServicio([$this->nuevoProfesional()], '2035-01-01', '2035-12-31');

        $this->expectException(TransicionEstadoInvalidaException::class);
        $this->validacion->aprobar($ficha->id, $this->userId, 'intento de salto');
    }

    public function testNoSePuedeAutorizarUnBorradorSinEnviarlo(): void
    {
        $ficha = $this->fichaConServicio([$this->nuevoProfesional()], '2035-01-01', '2035-12-31');

        $this->expectException(TransicionEstadoInvalidaException::class);
        $this->validacion->autorizar($ficha->id, $this->userId, 'sin enviar');
    }

    public function testLaAutorizacionExigeComentario(): void
    {
        $ficha = $this->fichaConServicio([$this->nuevoProfesional()], '2035-01-01', '2035-12-31');
        $this->validacion->enviar($ficha->id, $this->userId);

        $this->expectException(RuntimeException::class);
        $this->validacion->autorizar($ficha->id, $this->userId, '   ');
    }

    public function testNoSePuedeEnviarUnaFichaSinServicios(): void
    {
        $ficha = $this->crearFicha([$this->nuevoProfesional()], '2035-01-01', '2035-12-31');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/al menos un servicio/');
        $this->validacion->enviar($ficha->id, $this->userId);
    }

    // ── Devolución y reinicio del flujo ─────────────────────────────────

    public function testElRechazoDevuelveLaFichaAlGeneradorYPermiteReenviar(): void
    {
        $ficha = $this->fichaConServicio([$this->nuevoProfesional()], '2035-01-01', '2035-12-31');
        $this->validacion->enviar($ficha->id, $this->userId);

        $ficha = $this->validacion->rechazar($ficha->id, $this->userId, 'Falta soporte del profesional');
        $this->assertSame(EstadoFicha::CorreccionRequerida->id(), $ficha->id_estado);
        $this->assertTrue($ficha->esEditable(), 'Una ficha devuelta debe poder editarse');

        // Corrige y reenvía: se abre un segundo ciclo del flujo.
        $ficha = $this->validacion->reenviar($ficha->id, $this->userId, 'Soporte adjuntado');
        $this->assertSame(EstadoFicha::PendienteAutorizacion->id(), $ficha->id_estado);
        $this->assertSame(2, (int) $ficha->ciclos_flujo);
    }

    public function testElRechazoFinancieroTambienDevuelveACorreccion(): void
    {
        $ficha = $this->fichaConServicio([$this->nuevoProfesional()], '2035-01-01', '2035-12-31');
        $this->validacion->enviar($ficha->id, $this->userId);
        $this->validacion->autorizar($ficha->id, $this->userId, 'ok');

        $ficha = $this->validacion->rechazar($ficha->id, $this->userId, 'Valor fuera de presupuesto');

        $this->assertSame(EstadoFicha::CorreccionRequerida->id(), $ficha->id_estado);
        // El nivel se deduce del estado de origen, no de un flag del cliente.
        $this->assertSame($this->userId, $ficha->user_aprueba_id);
        $this->assertSame('Valor fuera de presupuesto', $ficha->obs_aprueba);
    }

    public function testElRechazoExigeMotivo(): void
    {
        $ficha = $this->fichaConServicio([$this->nuevoProfesional()], '2035-01-01', '2035-12-31');
        $this->validacion->enviar($ficha->id, $this->userId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/motivo/');
        $this->validacion->rechazar($ficha->id, $this->userId, '  ');
    }

    // ── RN-03: ventana de envío ─────────────────────────────────────────

    public function testNoSePuedeEnviarDespuesDelDiaLimite(): void
    {
        config(['fichas_tecnicas.dia_limite_envio' => 21]);
        Carbon::setTestNow(Carbon::create(2035, 3, 22, 9, 0, 0, 'America/Bogota'));

        $ficha = $this->fichaConServicio([$this->nuevoProfesional()], '2035-06-01', '2035-12-31');

        $this->expectException(VentanaEnvioCerradaException::class);
        $this->validacion->enviar($ficha->id, $this->userId);
    }

    public function testSePuedeEnviarDentroDeLaVentana(): void
    {
        config(['fichas_tecnicas.dia_limite_envio' => 21]);
        Carbon::setTestNow(Carbon::create(2035, 3, 21, 9, 0, 0, 'America/Bogota'));

        $ficha = $this->fichaConServicio([$this->nuevoProfesional()], '2035-06-01', '2035-12-31');
        $ficha = $this->validacion->enviar($ficha->id, $this->userId);

        $this->assertSame(EstadoFicha::PendienteAutorizacion->id(), $ficha->id_estado);
    }

    /** Con la ventana cerrada el borrador sigue siendo editable y guardable. */
    public function testLaVentanaCerradaNoImpideEditarElBorrador(): void
    {
        config(['fichas_tecnicas.dia_limite_envio' => 21]);
        Carbon::setTestNow(Carbon::create(2035, 3, 25, 9, 0, 0, 'America/Bogota'));

        $prof  = $this->nuevoProfesional();
        $ficha = $this->fichaConServicio([$prof], '2035-06-01', '2035-12-31');

        $actualizada = $this->fichas->actualizar(
            $ficha->id,
            ['vlr_contrato' => 2500000, 'profesionales' => [$prof]],
            $this->userId
        );

        $this->assertSame('2500000.00', $actualizada->vlr_contrato);
        $this->assertSame(EstadoFicha::Borrador->id(), $actualizada->id_estado);
    }

    // ── RN-01 / RN-02: conflictos de profesionales ──────────────────────

    /** RN-02: el profesional está comprometido con OTRA agremiación. */
    public function testBloqueaAlProfesionalComprometidoConOtraAgremiacion(): void
    {
        $prof = $this->nuevoProfesional();

        $this->aprobarFicha(
            $this->fichaConServicio([$prof], '2035-01-01', '2035-12-31', $this->agremiacionId)
        );

        $this->expectException(ConflictoProfesionalesException::class);
        $this->crearFicha([$prof], '2035-06-01', '2036-05-31', 1000000, $this->agremiacionAlternaId);
    }

    /** RN-01: misma agremiación → solo alerta informativa, no bloquea. */
    public function testPermiteRecontratarEnLaMismaAgremiacionConAlerta(): void
    {
        $prof = $this->nuevoProfesional();

        $this->aprobarFicha(
            $this->fichaConServicio([$prof], '2035-01-01', '2035-12-31', $this->agremiacionId)
        );

        $segunda = $this->crearFicha([$prof], '2035-06-01', '2036-05-31', 1000000, $this->agremiacionId);

        $this->assertSame(EstadoFicha::Borrador->id(), $segunda->id_estado);

        $alertas = $this->fichas->alertasPendientes();
        $this->assertNotEmpty($alertas, 'Debe reportar la alerta informativa RN-01');
        $this->assertSame('ALERTA', $alertas[0]['tipo_conflicto']);
    }

    public function testPermiteElMismoProfesionalSinSolapeDeFechas(): void
    {
        $prof = $this->nuevoProfesional();

        $this->aprobarFicha(
            $this->fichaConServicio([$prof], '2035-01-01', '2035-12-31', $this->agremiacionId)
        );

        // Sin solape: ni alerta ni bloqueo, aunque cambie de agremiación.
        $segunda = $this->crearFicha([$prof], '2036-01-01', '2036-12-31', 1000000, $this->agremiacionAlternaId);

        $this->assertSame(EstadoFicha::Borrador->id(), $segunda->id_estado);
        $this->assertEmpty($this->fichas->alertasPendientes());
    }

    /**
     * El legacy no excluía la propia ficha en la validación, así que editar un
     * borrador chocaba consigo mismo.
     */
    public function testLaEdicionNoChocaConLaPropiaFicha(): void
    {
        $prof  = $this->nuevoProfesional();
        $ficha = $this->crearFicha([$prof], '2035-01-01', '2035-12-31');

        $actualizada = $this->fichas->actualizar(
            $ficha->id,
            ['vlr_contrato' => 2000000, 'profesionales' => [$prof]],
            $this->userId
        );

        $this->assertSame('2000000.00', $actualizada->vlr_contrato);
    }

    // ── Consecutivos ────────────────────────────────────────────────────

    public function testElConsecutivoUsaElMaximoSufijoNoElConteo(): void
    {
        $prefijo = (string) DB::table('ent_empresas')->where('id', $this->empresaId)->value('prefijo');
        $anio    = (int) now()->format('Y');

        // Simula un hueco en la secuencia (ficha cancelada en el legacy)
        $ficha = $this->crearFicha([$this->nuevoProfesional()], '2035-01-01', '2035-12-31');
        DB::table('fich_fichas')->where('id', $ficha->id)->update(['consecutivo' => "{$prefijo}-{$anio}-50"]);

        $siguiente = $this->consecutivos->siguienteParaFicha($prefijo, $anio);

        $this->assertSame("{$prefijo}-{$anio}-51", $siguiente);
    }

    public function testRechazaUnConsecutivoManualYaAsignado(): void
    {
        $primera = $this->aprobarFicha(
            $this->fichaConServicio([$this->nuevoProfesional()], '2035-01-01', '2035-12-31')
        );

        $segunda = $this->fichaConServicio([$this->nuevoProfesional()], '2037-01-01', '2037-12-31');
        $this->validacion->enviar($segunda->id, $this->userId);
        $this->validacion->autorizar($segunda->id, $this->userId, 'ok');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ya está asignado/');
        $this->validacion->aprobar($segunda->id, $this->userId, 'ok', $primera->consecutivo);
    }

    // ── Modificación de fichas formalizadas (nueva versión / OS) ─────────

    public function testSolicitarModificacionCreaUnaNuevaVersionYReiniciaElFlujo(): void
    {
        $prof  = $this->nuevoProfesional();
        $ficha = $this->fichaConServicio([$prof], '2035-01-01', '2035-12-31');

        $this->fichas->agregarDetalle(
            $ficha->id,
            DetalleFichaDTO::fromArray(['cups' => '890201', 'valor' => 200000]),
            $this->userId
        );

        $ficha = $this->aprobarFicha($ficha->refresh());
        $this->assertTrue($ficha->permiteSolicitarModificacion());

        $os = $this->validacion->solicitarModificacion(
            $ficha->id,
            $this->userId,
            'Ajuste de tarifas por resolución',
            ['fecha_ini' => '2036-01-01', 'fecha_fin' => '2036-12-31', 'vlr_contrato' => 1500000],
            $this->fichas
        );

        $this->assertSame($ficha->id, $os->id_padre);
        $this->assertSame(2, $os->version);
        $this->assertSame(EstadoFicha::OsBorrador->id(), $os->id_estado);
        $this->assertSame(2, $os->total_detalles, 'Debe clonar los servicios del padre');
        $this->assertSame(1, $os->total_profesionales, 'Debe clonar los profesionales del padre');
        $this->assertSame('Ajuste de tarifas por resolución', $os->motivo_modificacion);

        // La versión anterior conserva su vigencia mientras la nueva se aprueba.
        $ficha->refresh();
        $this->assertSame(EstadoFicha::Aprobada->id(), $ficha->id_estado);
        $this->assertNull($ficha->reemplazada_por_id);

        // La nueva versión recorre el flujo completo de nuevo.
        $os = $this->validacion->enviar($os->id, $this->userId);
        $this->assertSame(EstadoFicha::OsPendienteAutorizacion->id(), $os->id_estado);

        $os = $this->validacion->autorizar($os->id, $this->userId, 'ok');
        $this->assertSame(EstadoFicha::OsPendienteRevisionFinanciera->id(), $os->id_estado);

        $os = $this->validacion->aprobar($os->id, $this->userId, 'ok');
        $this->assertSame(EstadoFicha::OsAprobada->id(), $os->id_estado);
        $this->assertStringEndsWith('-2', (string) $os->consecutivo);

        // Al aprobarse, la versión anterior queda enlazada.
        $ficha->refresh();
        $this->assertSame($os->id, $ficha->reemplazada_por_id);
    }

    public function testLaModificacionExigeMotivo(): void
    {
        $ficha = $this->aprobarFicha(
            $this->fichaConServicio([$this->nuevoProfesional()], '2035-01-01', '2035-12-31')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/motivo/');
        $this->validacion->solicitarModificacion($ficha->id, $this->userId, '   ', [], $this->fichas);
    }

    public function testNoSePuedeModificarUnBorrador(): void
    {
        $ficha = $this->fichaConServicio([$this->nuevoProfesional()], '2035-01-01', '2035-12-31');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no admite solicitud de modificación/');
        $this->validacion->solicitarModificacion($ficha->id, $this->userId, 'cambio', [], $this->fichas);
    }

    public function testNoSePuedeModificarUnaFichaYaReemplazada(): void
    {
        $ficha = $this->aprobarFicha(
            $this->fichaConServicio([$this->nuevoProfesional()], '2035-01-01', '2035-12-31')
        );

        $os = $this->validacion->solicitarModificacion(
            $ficha->id,
            $this->userId,
            'Primer ajuste',
            ['fecha_ini' => '2036-01-01', 'fecha_fin' => '2036-12-31'],
            $this->fichas
        );

        $this->validacion->enviar($os->id, $this->userId);
        $this->validacion->autorizar($os->id, $this->userId, 'ok');
        $this->validacion->aprobar($os->id, $this->userId, 'ok');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/versión posterior que la reemplaza/u');
        $this->validacion->solicitarModificacion($ficha->id, $this->userId, 'Segundo ajuste', [], $this->fichas);
    }

    public function testLaCadenaDeVersionesSeConsultaDesdeCualquierVersion(): void
    {
        $ficha = $this->aprobarFicha(
            $this->fichaConServicio([$this->nuevoProfesional()], '2035-01-01', '2035-12-31')
        );

        $os = $this->validacion->solicitarModificacion(
            $ficha->id,
            $this->userId,
            'Ajuste',
            ['fecha_ini' => '2036-01-01', 'fecha_fin' => '2036-12-31'],
            $this->fichas
        );

        $desdeOriginal = $this->fichas->cadenaDeVersiones($ficha->id);
        $desdeOs       = $this->fichas->cadenaDeVersiones($os->id);

        $this->assertSame($ficha->id, $desdeOriginal['raiz_id']);
        $this->assertSame($ficha->id, $desdeOs['raiz_id']);
        $this->assertSame(2, $desdeOriginal['total_versiones']);
        $this->assertSame($desdeOriginal['total_versiones'], $desdeOs['total_versiones']);
    }

    // ── Cancelación e inmutabilidad ─────────────────────────────────────

    /** Eliminación lógica, nunca DELETE físico. */
    public function testLaCancelacionEsLogica(): void
    {
        $ficha = $this->crearFicha([$this->nuevoProfesional()], '2035-01-01', '2035-12-31');

        $cancelada = $this->fichas->cancelar($ficha->id, $this->userId, 'Prueba');

        $this->assertSame(EstadoFicha::Cancelada->id(), $cancelada->id_estado);
        $this->assertDatabaseHas('fich_fichas', ['id' => $ficha->id]);
    }

    public function testUnaFichaAprobadaNoAdmiteModificacionesDirectas(): void
    {
        $ficha = $this->aprobarFicha(
            $this->fichaConServicio([$this->nuevoProfesional()], '2035-01-01', '2035-12-31')
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no admite modificaciones/');
        $this->fichas->agregarDetalle(
            $ficha->id,
            DetalleFichaDTO::fromArray(['valor' => 500]),
            $this->userId
        );
    }

    public function testUnaFichaEnAutorizacionNoAdmiteModificaciones(): void
    {
        $ficha = $this->fichaConServicio([$this->nuevoProfesional()], '2035-01-01', '2035-12-31');
        $this->validacion->enviar($ficha->id, $this->userId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no admite modificaciones/');
        $this->fichas->agregarDetalle(
            $ficha->id,
            DetalleFichaDTO::fromArray(['valor' => 500]),
            $this->userId
        );
    }

    /** La base debe rechazar fecha_fin < fecha_ini (CHECK chk_ffic_vigencia). */
    public function testLaBaseDeDatosImpideVigenciasInvertidas(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('fich_fichas')->insert([
            'id_agremiacion'     => $this->agremiacionId,
            'id_objeto_contrato' => $this->objetoId,
            'id_especialidad'    => $this->especialidadId,
            'vlr_contrato'       => 1000,
            'fecha_ini'          => '2035-12-31',
            'fecha_fin'          => '2035-01-01',
            'id_estado'          => EstadoFicha::Borrador->id(),
            'id_user_reg'        => $this->userId,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }

    public function testLosScopesDeBandejaFiltranPorEstado(): void
    {
        $borrador = $this->crearFicha([$this->nuevoProfesional()], '2035-01-01', '2035-12-31');

        $this->assertTrue(
            FichFicha::query()->borradores()->pluck('id')->contains($borrador->id)
        );

        $this->assertFalse(
            FichFicha::query()->finalizadas()->pluck('id')->contains($borrador->id)
        );

        $enviada = $this->fichaConServicio([$this->nuevoProfesional()], '2035-01-01', '2035-12-31');
        $this->validacion->enviar($enviada->id, $this->userId);

        $this->assertTrue(
            FichFicha::query()->porAutorizar()->pluck('id')->contains($enviada->id)
        );
        $this->assertFalse(
            FichFicha::query()->porAprobar()->pluck('id')->contains($enviada->id)
        );
    }
}
