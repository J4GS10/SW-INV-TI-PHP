<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Config\Ajustes;
use App\Correo\ColaCorreos;
use App\Correo\ConfigCorreo;
use App\Correo\Cortacircuitos;
use App\Correo\EntregaCorreo;
use App\Correo\ErrorEnvioCorreo;
use App\Correo\Notificador;
use App\Correo\ProcesadorCola;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Integracion\FuenteIntegracion;
use App\Notificaciones\ResolutorDestinatarios;
use App\Tareas\EjecutorTareas;
use App\Tareas\MantenimientoSeguridad;
use App\Tareas\ProgramaTareas;
use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Mailpit;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Scheduler\Trigger\PeriodicalTrigger;

/**
 * Tareas en segundo plano contra Oracle real, Mailpit y Redis: purga y
 * mantenimiento de seguridad, cola de correos (entrega, retenidos por
 * credenciales, reintentos y fallidos), cerrojos entre procesos simultáneos
 * (claves lock:job:* compartidas con el backend anterior) y el worker del
 * Scheduler de punta a punta.
 */
final class TareasTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        Mailpit::vaciar();
        D::conexion()->executeStatement('DELETE FROM "SYS_INTEGRACION_CORREO"');
        D::conexion()->executeStatement('DELETE FROM "INV_REGLA_NOTIFICACION"');
        // Cola vacía: lo pendiente de otras pruebas no interfiere con los conteos.
        D::conexion()->executeStatement("UPDATE \"SYS_EMAIL_OUTBOX\" SET \"EOB_Estado\" = 'ENVIADO' WHERE \"EOB_Estado\" <> 'ENVIADO'");
    }

    public function testElProgramaTieneLasCuatroTareasConSusIntervalos(): void
    {
        $intervalos = [];
        foreach ($this->servicio(ProgramaTareas::class)->getSchedule()->getRecurringMessages() as $m) {
            $trigger = $m->getTrigger();
            self::assertInstanceOf(PeriodicalTrigger::class, $trigger);
            $intervalos[(string) $trigger] = true;
        }
        self::assertEqualsCanonicalizing(['email_outbox', 'purge_security', 'security_housekeeping', 'ad_sync', 'close_inventory_counts', 'operational_alerts'],
            array_keys($this->servicio(EjecutorTareas::class)->tareas()));
        self::assertEqualsCanonicalizing(['every 15 seconds', 'every 21600 seconds', 'every 60 seconds', 'every 300 seconds'], array_keys($intervalos));
    }

    public function testPurgaYMantenimientoDeSeguridad(): void
    {
        $c = D::conexion();
        $u = D::crearUsuario();
        $ahora = Reloj::ahora();
        $c->executeStatement("INSERT INTO \"SYS_TOKEN_REVOCADO\" (\"TRV_Jti\", \"TRV_Tipo\", \"TRV_Fecha_Revocacion\", \"TRV_Expira\") VALUES (:j, 'access', :f, :f)",
            ['j' => 'vencido-'.D::sufijo(), 'f' => Reloj::aBd($ahora->modify('-2 days'))]);
        $r = $this->servicio(EjecutorTareas::class)->ejecutar('purge_security');
        self::assertTrue($r['ejecutada']);
        self::assertGreaterThanOrEqual(1, $r['resultado']['tokens_revocados_eliminados']);

        // Sesión que agotó su vida absoluta y cuenta sin inicio de sesión en más de 90 días.
        $sesion = Ident::nuevo();
        $c->executeStatement("INSERT INTO \"SYS_SESION\" (\"SES_Sesion\", \"USU_Usuario\", \"SES_Creada_En\", \"SES_Ultima_Actividad\", \"SES_Expira\", \"SES_Metodo\") VALUES (:s, :u, :c, :c, :e, 'password')",
            ['s' => $sesion, 'u' => $u['id'], 'c' => Reloj::aBd($ahora->modify('-2 days')), 'e' => Reloj::aBd($ahora->modify('-1 day'))]);
        $inactiva = D::crearUsuario();
        $c->executeStatement('UPDATE "INV_USUARIO" SET "USU_Ultimo_Login" = :f WHERE "USU_Usuario" = :u', ['f' => Reloj::aBd($ahora->modify('-120 days')), 'u' => $inactiva['id']]);
        $r = $this->servicio(EjecutorTareas::class)->ejecutar('security_housekeeping');
        self::assertNull($r['error']);
        self::assertGreaterThanOrEqual(1, $r['resultado']['sesiones_expiradas']);
        self::assertGreaterThanOrEqual(1, $r['resultado']['cuentas_desactivadas']);
        self::assertSame('expirada', $c->fetchOne('SELECT "SES_Motivo_Cierre" FROM "SYS_SESION" WHERE "SES_Sesion" = :s', ['s' => $sesion]));
        self::assertSame('0', (string) $c->fetchOne('SELECT "USU_Estado" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $inactiva['id']]));
        self::assertSame('1', (string) $c->fetchOne('SELECT "USU_Estado" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $u['id']]));
        self::assertSame(1, D::contar("SELECT COUNT(*) FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Accion\" = 'ACCOUNT_AUTO_DISABLED' AND \"AUD_Snapshot_JSON\" LIKE :u",
            ['u' => '%'.$inactiva['username'].'%']));
        // El aviso al titular pasa por la cola.
        $this->servicio(ProcesadorCola::class)->drenar();
        self::assertSame('[Inventario] Su cuenta fue desactivada por inactividad', Mailpit::para($inactiva['correo'])[0]['Subject'] ?? null);
    }

    public function testRetencionDeLaBitacoraUsaElModoPurgaDeOracle(): void
    {
        $c = D::conexion();
        $marca = 'RETENCION_'.D::sufijo();
        $c->executeStatement('INSERT INTO "INV_AUDITORIA_SISTEMA" ("AUD_Auditoria", "AUD_Fecha_Hora", "AUD_Accion", "AUD_Entidad_Afectada") VALUES (:id, :f, :a, :e)',
            ['id' => Ident::nuevo(), 'f' => Reloj::aBd(Reloj::ahora()->modify('-800 days')), 'a' => $marca, 'e' => 'PRUEBA']);
        $ajustes = Ajustes::desdeEntorno([...$_SERVER, ...$_ENV, 'AUDIT_RETENTION_DAYS' => '30']);
        $mantenimiento = new MantenimientoSeguridad($this->conexionApp(), $this->servicio(\App\Organizacion\Identidades::class),
            $this->servicio(\App\Repositorio\GobiernoRepositorio::class), $this->servicio(Notificador::class), $ajustes);

        self::assertGreaterThanOrEqual(1, $mantenimiento->aplicarRetencion());
        self::assertSame(0, D::contar('SELECT COUNT(*) FROM "INV_AUDITORIA_SISTEMA" WHERE "AUD_Accion" = :a', ['a' => $marca]));
        self::assertStringContainsString('"retencion_dias":365', (string) $c->fetchOne(
            "SELECT \"AUD_Snapshot_JSON\" FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Accion\" = 'AUDIT_RETENTION_PURGE' ORDER BY \"AUD_Fecha_Hora\" DESC FETCH FIRST 1 ROWS ONLY"),
            'Mínimo de 365 días aunque se configuren menos.');
        // Fuera del modo PURGA el disparador sigue impidiendo borrar la bitácora.
        $this->expectException(\Doctrine\DBAL\Exception::class);
        $this->conexionApp()->executeStatement("DELETE FROM \"INV_AUDITORIA_SISTEMA\" WHERE \"AUD_Accion\" = 'AUDIT_RETENTION_PURGE'");
    }

    public function testColaResuelveDestinatariosUnaVezYEntregaPorSmtp(): void
    {
        $c = D::conexion();
        $persona = D::crearUsuario();
        $c->executeStatement("INSERT INTO \"INV_REGLA_NOTIFICACION\" (\"RNO_Evento\", \"RNO_Activa\", \"RNO_Notificar_Afectado\", \"RNO_Notificar_Jefe\", \"RNO_Copiar_Admins\", \"RNO_Correos_Extra\") VALUES ('asignacion', 1, 1, 0, 0, 'extra@pruebas.local')");
        $this->servicio(Notificador::class)->enviar('asignacion', ['persona_nombre' => 'Ana', 'codigo' => 'ACT-1'], [$persona['correo']]);
        $id = (string) $c->fetchOne("SELECT \"EOB_Id\" FROM \"SYS_EMAIL_OUTBOX\" WHERE \"EOB_Estado\" = 'PENDIENTE' AND \"EOB_Plantilla\" = 'asignacion' ORDER BY \"EOB_Creado_En\" DESC FETCH FIRST 1 ROWS ONLY");

        self::assertSame(1, $this->servicio(ProcesadorCola::class)->drenar());
        $fila = $c->fetchAssociative('SELECT * FROM "SYS_EMAIL_OUTBOX" WHERE "EOB_Id" = :id', ['id' => $id]);
        self::assertIsArray($fila);
        self::assertSame(['ENVIADO', '0', '1', null], [$fila['EOB_Estado'], (string) $fila['EOB_Resolver'], (string) $fila['EOB_Intentos'], $fila['EOB_Ultimo_Error']]);
        self::assertSame([$persona['correo'], 'extra@pruebas.local'], json_decode((string) $fila['EOB_Para'], true), 'Destinatarios fijados según la regla.');
        self::assertCount(1, Mailpit::para('extra@pruebas.local'));

        // Regla inactiva: el correo se da por enviado sin destinatarios.
        $c->executeStatement("UPDATE \"INV_REGLA_NOTIFICACION\" SET \"RNO_Activa\" = 0 WHERE \"RNO_Evento\" = 'asignacion'");
        $this->servicio(Notificador::class)->enviar('asignacion', ['persona_nombre' => 'Ana', 'codigo' => 'ACT-1'], [$persona['correo']]);
        $this->servicio(ProcesadorCola::class)->drenar();
        self::assertSame(1, D::contar("SELECT COUNT(*) FROM \"SYS_EMAIL_OUTBOX\" WHERE \"EOB_Ultimo_Error\" = 'SIN_DESTINATARIOS' AND \"EOB_Creado_En\" > SYSTIMESTAMP - INTERVAL '1' MINUTE"));
    }

    public function testCredencialesRechazadasRetienenSinGastarIntentosYLosFallosSeReprogramanHastaFallido(): void
    {
        $c = D::conexion();
        $id = $this->encolar('retenido@pruebas.local');
        $this->procesador(new ErrorEnvioCorreo('AUTH_FAILED', '535 credenciales'))->procesarVencidos();
        $f = $this->fila($id);
        self::assertSame(['PENDIENTE', '0', 'AUTH_FAILED: 535 credenciales'], [$f['EOB_Estado'], (string) $f['EOB_Intentos'], $f['EOB_Ultimo_Error']]);
        self::assertGreaterThan(Reloj::ahora()->modify('+14 minutes'), Reloj::desdeBd((string) $f['EOB_Proximo_Intento']));
        self::assertSame(0, $this->procesador(null)->procesarVencidos(), 'Retenido: no se vuelve a intentar hasta la pausa.');
        self::assertGreaterThanOrEqual(1, $this->servicio(ColaCorreos::class)->reanudarRetenidos());
        self::assertLessThanOrEqual(Reloj::ahora(), Reloj::desdeBd((string) $this->fila($id)['EOB_Proximo_Intento']));

        // Error de entrega: espera creciente (1 min tras el primer intento)…
        $this->procesador(new ErrorEnvioCorreo('CONNECTION_FAILED', 'sin red'))->procesarVencidos();
        $f = $this->fila($id);
        self::assertSame(['PENDIENTE', '1', 'ErrorEnvioCorreo: CONNECTION_FAILED: sin red'], [$f['EOB_Estado'], (string) $f['EOB_Intentos'], $f['EOB_Ultimo_Error']]);
        self::assertGreaterThan(Reloj::ahora()->modify('+50 seconds'), Reloj::desdeBd((string) $f['EOB_Proximo_Intento']));
        // …y agotados los intentos (OUTBOX_MAX_ATTEMPTS = 7) queda FALLIDO.
        $c->executeStatement('UPDATE "SYS_EMAIL_OUTBOX" SET "EOB_Intentos" = 6, "EOB_Proximo_Intento" = :a WHERE "EOB_Id" = :id', ['a' => Reloj::aBd(Reloj::ahora()), 'id' => $id]);
        $this->procesador(new ErrorEnvioCorreo('CONNECTION_FAILED', 'sin red'))->procesarVencidos();
        self::assertSame(['FALLIDO', '7'], [$this->fila($id)['EOB_Estado'], (string) $this->fila($id)['EOB_Intentos']]);

        // Con el cortacircuitos de correo activo no se reclama nada.
        $this->encolar('pausa@pruebas.local');
        $cortacircuitos = $this->servicio(Cortacircuitos::class);
        $cortacircuitos->disparar('smtp', 'prueba');
        try {
            self::assertSame(0, $this->servicio(ProcesadorCola::class)->procesarVencidos());
        } finally {
            $cortacircuitos->reiniciar();
        }
        self::assertSame(1, $this->servicio(ProcesadorCola::class)->procesarVencidos());
        self::assertCount(1, Mailpit::para('pausa@pruebas.local'));
    }

    public function testCerrojosEntreProcesosSimultaneosConLasClavesDelBackendActual(): void
    {
        $redis = $this->redis();
        $redis->del('lock:job:purge_security', 'lock:job:security_housekeeping');
        $resultados = $this->procesosSimultaneos(array_fill(0, 6, 'purge_security'));
        self::assertSame(1, \count(array_filter($resultados, static fn (array $r): bool => $r['ejecutada'])), json_encode($resultados, \JSON_THROW_ON_ERROR));
        $ttl = $redis->ttl('lock:job:purge_security');
        self::assertGreaterThan(21500, $ttl, 'Cerrojo por el intervalo menos 5 s, como el backend anterior.');
        self::assertLessThanOrEqual(21595, $ttl);
        self::assertSame('1', $redis->get('lock:job:purge_security'));

        // Un cerrojo tomado por el backend anterior (mismo formato) también excluye al worker PHP.
        $redis->set('lock:job:security_housekeeping', '1', ['nx', 'ex' => 60]);
        self::assertFalse($this->procesosSimultaneos(['security_housekeeping'])[0]['ejecutada']);
        $redis->del('lock:job:purge_security', 'lock:job:security_housekeeping');
    }

    public function testVariosWorkersDrenanLaColaSinEnviarDosVecesNingunCorreo(): void
    {
        $destinos = [];
        for ($i = 0; $i < 45; ++$i) {
            $destinos[] = "lote{$i}-".D::sufijo().'@pruebas.local';
            $this->encolar(end($destinos));
        }
        $resultados = $this->procesosSimultaneos(array_fill(0, 5, 'email_outbox'));
        self::assertSame(45, array_sum(array_map(static fn (array $r): int => (int) ($r['resultado']['intentados'] ?? 0), $resultados)),
            'Cada correo lo reclamó un solo worker.');
        foreach ($destinos as $destino) {
            self::assertCount(1, Mailpit::para($destino), $destino);
        }
        self::assertSame(0, D::contar("SELECT COUNT(*) FROM \"SYS_EMAIL_OUTBOX\" WHERE \"EOB_Estado\" <> 'ENVIADO'"));
    }

    /**
     * Fase 8: una tarea exclusiva que falla suelta su cerrojo (se reintenta en el siguiente turno, no
     * tras todo el intervalo: 6 h para la purga) y no marca el latido (el healthcheck ve el fallo).
     */
    public function testUnaTareaQueFallaSueltaSuCerrojoYNoMarcaElLatido(): void
    {
        $cerrojo = new class implements \App\Tareas\Cerrojo {
            /** @var list<string> */
            public array $liberados = [];

            public function adquirir(string $clave, int $segundos): bool
            {
                return true;
            }

            public function liberar(string $clave): void
            {
                $this->liberados[] = $clave;
            }
        };
        $falla = new class implements \App\Tareas\TareaExclusiva {
            public function nombre(): string
            {
                return 'prueba_falla';
            }

            public function cadaSegundos(): int
            {
                return 3600;
            }

            public function ejecutar(): ?array
            {
                throw new \RuntimeException('Oracle no responde');
            }
        };
        $archivo = sys_get_temp_dir().'/latido-'.bin2hex(random_bytes(4));
        $ejecutor = new EjecutorTareas([$falla], $cerrojo, new \App\Tareas\LatidoWorker($archivo), new \Psr\Log\NullLogger());
        $ejecutor(new \App\Tareas\EjecutarTarea('prueba_falla'));
        self::assertSame(['lock:job:prueba_falla'], $cerrojo->liberados);
        self::assertFileDoesNotExist($archivo, 'Un worker cuyas tareas fallan no se declara sano.');
    }

    public function testSincronizacionPeriodicaConElIntervaloDeLaInterfazYSuCerrojo(): void
    {
        \App\Tests\Soporte\SambaAd::retirarPersonas();
        $cerrojo = new class implements \App\Tareas\Cerrojo {
            /** @var list<array{0: string, 1: int}> */
            public array $pedidos = [];
            public bool $libre = true;

            public function adquirir(string $clave, int $segundos): bool
            {
                $this->pedidos[] = [$clave, $segundos];

                return $this->libre;
            }

            public function liberar(string $clave): void
            {
            }
        };
        $fuente = $this->servicio(FuenteIntegracion::class);
        $tarea = new \App\Tareas\SincronizacionPeriodicaAd($fuente, $this->servicio(\App\Directorio\SincronizacionDirectorio::class), $cerrojo);
        $atrasar = static function (\App\Tareas\SincronizacionPeriodicaAd $t, int $segundos): void {
            $p = new \ReflectionProperty($t, 'ultima');
            $p->setValue($t, $p->getValue($t) - $segundos);
        };

        // Sin AD habilitado o sin intervalo (0 = solo manual): no hace nada.
        self::assertNull($tarea->ejecutar());
        \App\Tests\Soporte\SambaAd::configurar(['INT_AD_Intervalo_Min' => 0]);
        $fuente->invalidar();
        $atrasar($tarea, 7200);
        self::assertNull($tarea->ejecutar());

        // Intervalo de 2 min leído de la configuración en cada vuelta (sin reiniciar).
        D::conexion()->executeStatement('UPDATE "SYS_INTEGRACION_CORREO" SET "INT_AD_Intervalo_Min" = 2');
        $fuente->invalidar();
        self::assertSame(['creados' => 2, 'desactivados' => 0], $tarea->ejecutar());
        self::assertSame([['lock:job:ad_sync', 115]], $cerrojo->pedidos, 'Cerrojo por el intervalo menos 5 s.');
        self::assertNull($tarea->ejecutar(), 'Aún no pasa el intervalo.');
        $atrasar($tarea, 121);
        $cerrojo->libre = false;
        self::assertNull($tarea->ejecutar(), 'Otra réplica (o el backend anterior) ya la ejecutó en este intervalo.');
        self::assertCount(2, $cerrojo->pedidos);
        D::conexion()->executeStatement('DELETE FROM "SYS_INTEGRACION_CORREO"');
    }

    public function testElWorkerDelSchedulerEntregaLaColaDePuntaAPunta(): void
    {
        $id = $this->encolar('worker@pruebas.local');
        $proceso = proc_open(['php', 'bin/console', 'messenger:consume', 'scheduler_tareas', '--time-limit=25', '--no-interaction'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias, \dirname(__DIR__, 2), $this->entornoHijo());
        self::assertIsResource($proceso);
        stream_get_contents($tuberias[1]);
        stream_get_contents($tuberias[2]);
        proc_close($proceso);
        self::assertSame('ENVIADO', $this->fila($id)['EOB_Estado']);
        self::assertCount(1, Mailpit::para('worker@pruebas.local'));
    }

    private function encolar(string $para): string
    {
        $id = Ident::nuevo();
        $ahora = Reloj::aBd(Reloj::ahora());
        D::conexion()->executeStatement(
            'INSERT INTO "SYS_EMAIL_OUTBOX" ("EOB_Id", "EOB_Plantilla", "EOB_Asunto", "EOB_Html", "EOB_Para", "EOB_Cc_Admins", "EOB_Resolver", '
            ."\"EOB_Estado\", \"EOB_Intentos\", \"EOB_Proximo_Intento\", \"EOB_Creado_En\") VALUES (:id, 'prueba', 'Prueba de la cola', '<p>Hola</p>', :para, 0, 0, 'PENDIENTE', 0, :a, :a)",
            ['id' => $id, 'para' => json_encode([$para]), 'a' => $ahora],
        );

        return $id;
    }

    /** @return array<string, mixed> */
    private function fila(string $id): array
    {
        $fila = D::conexion()->fetchAssociative('SELECT * FROM "SYS_EMAIL_OUTBOX" WHERE "EOB_Id" = :id', ['id' => $id]);
        self::assertIsArray($fila);

        return $fila;
    }

    /** Procesador real con una entrega que falla con el error indicado (o entrega a Mailpit si es null). */
    private function procesador(?ErrorEnvioCorreo $error): ProcesadorCola
    {
        $entrega = null === $error ? $this->servicio(EntregaCorreo::class) : new class($error) implements EntregaCorreo {
            public function __construct(private readonly ErrorEnvioCorreo $error)
            {
            }

            public function entregar(ConfigCorreo $config, array $para, string $asunto, string $html, ?string $responderA = null, bool $usarCortacircuitos = true): void
            {
                throw $this->error;
            }
        };

        return new ProcesadorCola($this->servicio(ColaCorreos::class), $entrega, $this->servicio(FuenteIntegracion::class),
            $this->servicio(Cortacircuitos::class), $this->servicio(ResolutorDestinatarios::class), $this->servicio(Ajustes::class), new NullLogger());
    }

    /**
     * Lanza a la vez un proceso `app:tareas:ejecutar <tarea>` por elemento (con
     * Redis) y devuelve la salida JSON de cada uno.
     *
     * @param list<string> $tareas
     *
     * @return list<array{ejecutada: bool, resultado: array<string, mixed>|null, error: string|null}>
     */
    private function procesosSimultaneos(array $tareas): array
    {
        $procesos = [];
        foreach ($tareas as $tarea) {
            $p = proc_open(['php', 'bin/console', 'app:tareas:ejecutar', $tarea], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuberias,
                \dirname(__DIR__, 2), $this->entornoHijo());
            self::assertIsResource($p);
            $procesos[] = [$p, $tuberias];
        }
        $resultados = [];
        foreach ($procesos as [$p, $tuberias]) {
            $salida = (string) stream_get_contents($tuberias[1]);
            $errores = (string) stream_get_contents($tuberias[2]);
            proc_close($p);
            $json = json_decode(trim((string) strrchr("\n".trim($salida), "\n")), true);
            self::assertIsArray($json, $salida.$errores);
            $resultados[] = $json;
        }

        return $resultados;
    }

    /** @return array<string, string> */
    private function entornoHijo(): array
    {
        return [...array_filter(getenv(), 'is_string'), 'APP_ENV' => 'test', 'REDIS_URL' => (string) ($_SERVER['REDIS_PRUEBAS_URL'] ?? $_ENV['REDIS_PRUEBAS_URL'])];
    }

    private function redis(): \Redis
    {
        $url = parse_url((string) ($_SERVER['REDIS_PRUEBAS_URL'] ?? $_ENV['REDIS_PRUEBAS_URL']));
        $redis = new \Redis();
        $redis->connect((string) ($url['host'] ?? 'redis-pruebas'), (int) ($url['port'] ?? 6379), 2.0);

        return $redis;
    }

    /** Conexión de la aplicación (la del contenedor), no la auxiliar de las pruebas. */
    private function conexionApp(): \Doctrine\DBAL\Connection
    {
        return $this->servicio(\Doctrine\DBAL\Connection::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $clase
     *
     * @return T
     */
    private function servicio(string $clase): object
    {
        /** @var T */
        return static::getContainer()->get($clase);
    }
}
