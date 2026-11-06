<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Correo\CorreoEnCola;
use App\Correo\Notificador;
use App\Operations\HealthChecks;
use App\Operations\OperationalAlerts;
use App\Repositorio\GobiernoRepositorio;
use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\PruebaApi;

/** A problem opens once, warns someone, reminds daily and leaves a record when it closes. */
final class OperationalAlertsTest extends PruebaApi
{
    private string $backups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backups = sys_get_temp_dir().'/alertas_'.D::sufijo();
        mkdir($this->backups);
        D::conexion()->executeStatement('DELETE FROM SYS_OPERATIONAL_ALERT');
        D::conexion()->executeStatement('DELETE FROM "SYS_EMAIL_OUTBOX" WHERE "EOB_Estado" IN (:a, :b)', ['a' => CorreoEnCola::PENDIENTE, 'b' => CorreoEnCola::FALLIDO]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->backups.'/*') ?: []);
        rmdir($this->backups);
        parent::tearDown();
    }

    private function alerts(string $replica = '1'): OperationalAlerts
    {
        $container = static::getContainer();

        return new OperationalAlerts(D::conexion(), new HealthChecks(D::conexion(), $this->backups, $replica), $container->get(Notificador::class), $container->get(GobiernoRepositorio::class));
    }

    private function backupAged(int $hours): void
    {
        touch($this->backups.'/inventario_'.D::sufijo().'.dmp', time() - $hours * 3600);
    }

    private function audit(string $action): int
    {
        return D::contar('SELECT COUNT(*) FROM "INV_AUDITORIA_SISTEMA" WHERE "AUD_Accion" = :a', ['a' => $action]);
    }

    public function testAStaleBackupWarnsOnceAndClosesWhenARecentOneAppears(): void
    {
        $this->backupAged(10);
        $opened = $this->audit('ALERT_OPENED');
        $closed = $this->audit('ALERT_RESOLVED');
        $alerts = $this->alerts();

        self::assertSame(['open' => 1, 'notified' => 1, 'resolved' => 0], $alerts->evaluate());
        self::assertSame(1, $this->audit('ALERT_OPENED') - $opened);
        self::assertSame(OperationalAlerts::BACKUP_STALE, $alerts->open()[0]['code']);
        self::assertSame(10, $alerts->open()[0]['detail']['hours']);

        self::assertSame(0, $alerts->evaluate()['notified'], 'The same problem does not write again the same day.');

        D::conexion()->executeStatement("UPDATE SYS_OPERATIONAL_ALERT SET ALR_LAST_NOTIFIED = ALR_LAST_NOTIFIED - INTERVAL '25' HOUR");
        self::assertSame(1, $alerts->evaluate()['notified'], 'While it lasts it reminds once a day.');

        $this->backupAged(0);
        self::assertSame(['open' => 0, 'notified' => 0, 'resolved' => 1], $alerts->evaluate());
        self::assertSame([], $alerts->open());
        self::assertSame(1, $this->audit('ALERT_RESOLVED') - $closed);

        // It comes back: it warns again. No backup at all is a problem too.
        array_map('unlink', glob($this->backups.'/*') ?: []);
        self::assertSame(1, $alerts->evaluate()['notified']);
        self::assertSame(-1, $alerts->open()[0]['detail']['hours']);
    }

    public function testBackupsOnlyOnThisMachineAreReported(): void
    {
        $this->backupAged(0);
        $alerts = $this->alerts('');
        $alerts->evaluate();
        self::assertSame([OperationalAlerts::BACKUP_NO_REPLICA], array_column($alerts->open(), 'code'));
    }

    public function testAFailedEmailIsAProblem(): void
    {
        $this->backupAged(0);
        $alerts = $this->alerts();
        D::conexion()->executeStatement('INSERT INTO "SYS_EMAIL_OUTBOX" ("EOB_Id", "EOB_Plantilla", "EOB_Asunto", "EOB_Html", "EOB_Para", "EOB_Cc_Admins", "EOB_Resolver", "EOB_Estado", "EOB_Intentos", "EOB_Proximo_Intento") '
            ."VALUES (LOWER(RAWTOHEX(SYS_GUID())), 'x', 'x', 'x', '[]', 0, 0, :failed, 7, SYSTIMESTAMP)", ['failed' => CorreoEnCola::FALLIDO]);
        $alerts->evaluate();
        self::assertContains(OperationalAlerts::EMAIL_QUEUE_STUCK, array_column($alerts->open(), 'code'));
    }

    public function testOnlyWhoKeepsTheSystemRunningSeesThem(): void
    {
        $system = $this->tokenDe(D::crearUsuario(['rol' => 'SUPER_ADMIN']));
        $technician = $this->tokenDe(D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [D::crearSede('Alerta '.D::sufijo())]]));
        self::assertSame(200, $this->pedir('GET', '/api/v1/gov/alerts', token: $system)[0]);
        self::assertSame(403, $this->pedir('GET', '/api/v1/gov/alerts', token: $technician)[0]);
    }
}
