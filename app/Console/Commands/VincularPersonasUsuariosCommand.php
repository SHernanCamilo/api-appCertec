<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cruza config_person_tercero ↔ users por correo y completa huecos.
 *
 * No crea usuarios ni terceros: no todos los terceros son usuarios.
 * Solo llena un lado si está vacío y el otro tiene dato. Nunca pisa valores.
 *
 *   php artisan personas:vincular-usuarios
 *   php artisan personas:vincular-usuarios --apply
 */
class VincularPersonasUsuariosCommand extends Command
{
    protected $signature = 'personas:vincular-usuarios
        {--apply : Ejecuta los UPDATE. Sin este flag solo simula (dry-run)}';

    protected $description = 'Vincula personas/terceros con users por correo y completa datos vacíos entre ambos';

    /** @var array<string, string> persona/tercero => user */
    private const PERSONA_A_USER = [
        'numero_identificacion' => 'numero_identificacion',
        'tipo_identificacion'   => 'tipo_identificacion',
        'telefono'              => 'telefono',
        'direccion'             => 'direccion',
        'nombre'                => 'name',
    ];

    public function handle(): int
    {
        $aplicar = (bool) $this->option('apply');

        $this->info($aplicar
            ? 'Aplicando vinculación personas ↔ usuarios por correo…'
            : 'Simulación (dry-run). Pasa --apply para escribir en la base.');

        $users = DB::table('users')
            ->whereNotNull('email')
            ->whereRaw("TRIM(email) <> ''")
            ->get([
                'id', 'name', 'email', 'tipo_identificacion', 'numero_identificacion',
                'direccion', 'telefono',
            ]);

        $personas = DB::table('config_person_tercero')
            ->whereNotNull('email')
            ->whereRaw("TRIM(email) <> ''")
            ->get([
                'id', 'id_user', 'nombre', 'email', 'tipo_identificacion',
                'numero_identificacion', 'direccion', 'telefono',
            ]);

        [$userPorCorreo, $correosUserDup] = $this->indexarPorCorreo($users);
        [$personaPorCorreo, $correosPersonaDup] = $this->indexarPorCorreo($personas);

        foreach (array_unique(array_merge($correosUserDup, $correosPersonaDup)) as $correoDup) {
            $this->warn("Correo duplicado, se omite: {$correoDup}");
        }

        $docOcupadoPorUser = [];
        foreach ($users as $user) {
            $doc = $this->normalizar($user->numero_identificacion, 'numero_identificacion');
            if ($doc !== null) {
                $docOcupadoPorUser[$doc] = (int) $user->id;
            }
        }

        $omitidosSinMatch = 0;
        $parejas = 0;
        $yaVinculados = 0;
        $vinculos = [];
        $updatesUser = [];
        $updatesPersona = [];
        $conflictos = [];
        $omitidosDocDuplicado = [];

        $correos = array_unique(array_merge(array_keys($userPorCorreo), array_keys($personaPorCorreo)));

        foreach ($correos as $correo) {
            if (in_array($correo, $correosUserDup, true) || in_array($correo, $correosPersonaDup, true)) {
                continue;
            }

            $user = $userPorCorreo[$correo] ?? null;
            $persona = $personaPorCorreo[$correo] ?? null;

            if ($user === null || $persona === null) {
                $omitidosSinMatch++;
                continue;
            }

            $parejas++;
            $userId = (int) $user->id;
            $personaId = (int) $persona->id;
            $idUserActual = (int) ($persona->id_user ?? 0);

            if ($idUserActual === 0) {
                $vinculos[] = [
                    'persona_id' => $personaId,
                    'user_id'    => $userId,
                    'email'      => $correo,
                ];
            } elseif ($idUserActual === $userId) {
                $yaVinculados++;
            } else {
                $conflictos[] = [
                    'tipo'       => 'id_user',
                    'email'      => $correo,
                    'persona_id' => $personaId,
                    'id_user'    => $idUserActual,
                    'user_id'    => $userId,
                    'detalle'    => "Tercero ya apunta a user {$idUserActual}, el correo corresponde a user {$userId}",
                ];
            }

            $userPatch = [];
            $personaPatch = [];

            foreach (self::PERSONA_A_USER as $campoPersona => $campoUser) {
                $valorPersona = $this->normalizar($persona->{$campoPersona} ?? null, $campoPersona);
                $valorUser = $this->normalizar($user->{$campoUser} ?? null, $campoUser);

                if ($valorPersona !== null && $valorUser === null) {
                    if ($campoUser === 'numero_identificacion'
                        && isset($docOcupadoPorUser[$valorPersona])
                        && $docOcupadoPorUser[$valorPersona] !== $userId
                    ) {
                        $omitidosDocDuplicado[] = [
                            'email'      => $correo,
                            'user_id'    => $userId,
                            'ocupado_por'=> $docOcupadoPorUser[$valorPersona],
                            'documento'  => $valorPersona,
                        ];
                        continue;
                    }

                    $userPatch[$campoUser] = $valorPersona;
                    $user->{$campoUser} = $valorPersona;
                    if ($campoUser === 'numero_identificacion') {
                        $docOcupadoPorUser[$valorPersona] = $userId;
                    }
                } elseif ($valorUser !== null && $valorPersona === null) {
                    $personaPatch[$campoPersona] = $valorUser;
                    $persona->{$campoPersona} = $valorUser;
                } elseif ($valorUser !== null && $valorPersona !== null && !$this->equivalentes($valorUser, $valorPersona)) {
                    $conflictos[] = [
                        'tipo'       => $campoPersona,
                        'email'      => $correo,
                        'persona_id' => $personaId,
                        'user_id'    => $userId,
                        'detalle'    => "user={$valorUser} | tercero={$valorPersona}",
                    ];
                }
            }

            if ($userPatch !== []) {
                $updatesUser[] = ['id' => $userId, 'email' => $correo, 'campos' => $userPatch];
            }
            if ($personaPatch !== []) {
                $updatesPersona[] = ['id' => $personaId, 'email' => $correo, 'campos' => $personaPatch];
            }
        }

        $this->newLine();
        $this->table(['Métrica', 'Cantidad'], [
            ['Users con correo', $users->count()],
            ['Personas/terceros con correo', $personas->count()],
            ['Parejas por el mismo correo', $parejas],
            ['Ya tenían id_user correcto', $yaVinculados],
            ['Terceros a vincular (id_user)', count($vinculos)],
            ['Users a completar', count($updatesUser)],
            ['Terceros a completar', count($updatesPersona)],
            ['Sin pareja (no se crean registros)', $omitidosSinMatch],
            ['Conflictos (no se pisan)', count($conflictos)],
            ['Docs no copiados (ya usados por otro user)', count($omitidosDocDuplicado)],
        ]);

        if ($vinculos !== []) {
            $this->info('Vínculos id_user (muestra):');
            $this->table(
                ['persona_id', 'user_id', 'email'],
                array_map(fn (array $r) => [$r['persona_id'], $r['user_id'], $r['email']], array_slice($vinculos, 0, 15))
            );
        }

        if ($updatesUser !== []) {
            $this->info('Users a completar (muestra):');
            $this->table(
                ['user_id', 'email', 'campos'],
                array_map(
                    fn (array $r) => [$r['id'], $r['email'], implode(', ', array_keys($r['campos']))],
                    array_slice($updatesUser, 0, 15)
                )
            );
        }

        if ($updatesPersona !== []) {
            $this->info('Terceros a completar (muestra):');
            $this->table(
                ['persona_id', 'email', 'campos'],
                array_map(
                    fn (array $r) => [$r['id'], $r['email'], implode(', ', array_keys($r['campos']))],
                    array_slice($updatesPersona, 0, 15)
                )
            );
        }

        if ($conflictos !== []) {
            $this->warn('Conflictos (ambos lados tienen dato distinto; no se modifica):');
            $this->table(
                ['tipo', 'email', 'detalle'],
                array_map(
                    fn (array $r) => [$r['tipo'], $r['email'], $r['detalle']],
                    array_slice($conflictos, 0, 15)
                )
            );
        }

        $reporte = [
            'dry_run'              => !$aplicar,
            'vinculos'             => $vinculos,
            'updates_user'         => $updatesUser,
            'updates_persona'      => $updatesPersona,
            'conflictos'           => $conflictos,
            'docs_duplicados'      => $omitidosDocDuplicado,
            'omitidos_sin_match'   => $omitidosSinMatch,
            'ya_vinculados'        => $yaVinculados,
        ];

        $ruta = storage_path('logs/vincular-personas-usuarios-'.now()->format('Ymd-His').'.json');
        file_put_contents($ruta, json_encode($reporte, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line("Reporte: {$ruta}");

        if (!$aplicar) {
            $this->comment('Nada se escribió. Para aplicar: php artisan personas:vincular-usuarios --apply');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($vinculos, $updatesUser, $updatesPersona) {
            $ahora = now();

            foreach ($vinculos as $vinculo) {
                DB::table('config_person_tercero')
                    ->where('id', $vinculo['persona_id'])
                    ->where(function ($q) {
                        $q->whereNull('id_user')->orWhere('id_user', 0);
                    })
                    ->update([
                        'id_user'    => $vinculo['user_id'],
                        'updated_at' => $ahora,
                    ]);
            }

            foreach ($updatesUser as $fila) {
                $fila['campos']['updated_at'] = $ahora;
                DB::table('users')->where('id', $fila['id'])->update($fila['campos']);
            }

            foreach ($updatesPersona as $fila) {
                $fila['campos']['updated_at'] = $ahora;
                DB::table('config_person_tercero')->where('id', $fila['id'])->update($fila['campos']);
            }
        });

        $this->info(sprintf(
            'Listo. Vínculos: %d. Users actualizados: %d. Terceros actualizados: %d.',
            count($vinculos),
            count($updatesUser),
            count($updatesPersona)
        ));

        return self::SUCCESS;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $filas
     * @return array{0: array<string, object>, 1: list<string>}
     */
    private function indexarPorCorreo($filas): array
    {
        $mapa = [];
        $duplicados = [];

        foreach ($filas as $fila) {
            $correo = strtolower(trim((string) $fila->email));
            if ($correo === '') {
                continue;
            }
            if (isset($mapa[$correo])) {
                $duplicados[] = $correo;
                unset($mapa[$correo]);
                continue;
            }
            if (in_array($correo, $duplicados, true)) {
                continue;
            }
            $mapa[$correo] = $fila;
        }

        return [$mapa, array_values(array_unique($duplicados))];
    }

    private function normalizar(mixed $valor, ?string $campo = null): ?string
    {
        if ($valor === null) {
            return null;
        }
        $texto = trim((string) $valor);
        if ($texto === '') {
            return null;
        }
        if ($campo === 'numero_identificacion' && $this->esDocumentoInvalido($texto)) {
            return null;
        }

        return $texto;
    }

    /**
     * Ceros, "0" o "11111111111" no son una cédula real: se tratan como vacío.
     */
    private function esDocumentoInvalido(string $valor): bool
    {
        $digitos = preg_replace('/\D+/', '', $valor) ?? '';

        return $digitos === ''
            || preg_match('/^0+$/', $digitos) === 1
            || preg_match('/^1{5,}$/', $digitos) === 1;
    }

    private function equivalentes(string $a, string $b): bool
    {
        return $this->claveComparacion($a) === $this->claveComparacion($b);
    }

    private function claveComparacion(string $valor): string
    {
        $valor = preg_replace('/\s+/', ' ', trim(mb_strtoupper($valor, 'UTF-8'))) ?? '';
        $sinTildes = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);

        return $sinTildes !== false ? $sinTildes : $valor;
    }
}
