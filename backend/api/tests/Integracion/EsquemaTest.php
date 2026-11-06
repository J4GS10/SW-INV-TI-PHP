<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Db\ContextoRls;
use App\Db\SesionOracleMiddleware;
use App\Esquema\CapaSeguridad;
use App\Esquema\CuentaInicial;
use App\Esquema\EjecutorDdl;
use App\Esquema\MigradorEsquema;
use App\Esquema\PermisosEjecucion;
use App\Esquema\RecalculoHuellas;
use App\Esquema\SemillaMinima;
use App\Seguridad\CifradoCampos;
use App\Seguridad\Contrasenas;
use App\Tests\Soporte\DatosPrueba as D;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as ErrorDbal;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Migrador de esquema contra la Oracle de pruebas (creada vacía y migrada por
 * herramientas/oracle_pruebas.sh): idempotencia, reparación de la capa de
 * seguridad, mínimo privilegio del usuario de ejecución y datos mínimos.
 */
final class EsquemaTest extends KernelTestCase
{
    private Connection $conexion;
    private CapaSeguridad $seguridad;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->conexion = static::getContainer()->get(Connection::class);
        $this->seguridad = static::getContainer()->get(CapaSeguridad::class);
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(ContextoRls::class)->reset();
        parent::tearDown();
    }

    public function testElEsquemaEstaAlDiaYReinstalarNoCambiaNada(): void
    {
        $migrador = static::getContainer()->get(MigradorEsquema::class);

        self::assertSame([], $migrador->verificar());
        self::assertSame([], $migrador->migrar(), 'La línea base ya está registrada.');
        self::assertSame([], $this->seguridad->instalar());
        self::assertSame('Migraciones\Version20261029000000', $this->conexion->fetchOne('SELECT version FROM "SYS_MIGRACION_ESQUEMA"'));
    }

    public function testUnaPoliticaQuitadaYUnPredicadoAlteradoSeReponen(): void
    {
        $predicado = (string) $this->conexion->fetchOne('SELECT "VPD_Predicado" FROM "INV_VPD_PREDICADO" WHERE "VPD_Tabla" = \'INV_ACTIVO\'');
        try {
            $this->conexion->executeStatement("BEGIN DBMS_RLS.DROP_POLICY(USER, 'INV_CONSUMIBLE', 'INV_RLS_LECTURA'); END;");
            $this->conexion->executeStatement('UPDATE "INV_VPD_PREDICADO" SET "VPD_Predicado" = \'1 = 1\' WHERE "VPD_Tabla" = \'INV_ACTIVO\'');
            $this->conexion->executeStatement('INSERT INTO "INV_VPD_PREDICADO" VALUES (\'INV_SEDE\', \'LECTURA\', \'1 = 1\')');

            $faltas = $this->seguridad->verificar();
            self::assertContains('política INV_RLS_LECTURA de INV_CONSUMIBLE ausente o distinta', $faltas);
            self::assertContains('predicado INV_ACTIVO/LECTURA distinto', $faltas);

            $cambios = $this->seguridad->instalar();
            self::assertEqualsCanonicalizing([
                'predicado INV_ACTIVO/LECTURA actualizado', 'predicado INV_SEDE/LECTURA quitado', 'creada la política INV_RLS_LECTURA de INV_CONSUMIBLE',
            ], $cambios);
        } finally {
            $this->seguridad->instalar();
        }
        self::assertSame([], $this->seguridad->verificar());
        self::assertSame($predicado, $this->conexion->fetchOne('SELECT "VPD_Predicado" FROM "INV_VPD_PREDICADO" WHERE "VPD_Tabla" = \'INV_ACTIVO\''));
    }

    public function testUnDisparadorAlteradoODeshabilitadoSeReinstala(): void
    {
        $ddl = static::getContainer()->get(EjecutorDdl::class);
        try {
            $ddl->ejecutar('CREATE OR REPLACE TRIGGER trg_sin_vaciar BEFORE TRUNCATE ON SCHEMA BEGIN NULL; END;');
            $ddl->ejecutar('ALTER TRIGGER TRG_APROBACION_SIN_BORRADO DISABLE');

            self::assertContains('TRIGGER TRG_SIN_VACIAR distinto del esperado', $this->seguridad->verificar());
            self::assertContains('disparador TRG_APROBACION_SIN_BORRADO deshabilitado', $this->seguridad->verificar());
            self::assertEqualsCanonicalizing(['actualizado TRIGGER TRG_SIN_VACIAR', 'habilitado TRIGGER TRG_APROBACION_SIN_BORRADO'], $this->seguridad->instalar());
        } finally {
            $this->seguridad->instalar();
        }
        // La inmutabilidad vuelve a regir: el kardex no se vacía.
        try {
            $this->conexion->executeStatement('TRUNCATE TABLE "INV_KARDEX"');
            self::fail('El kardex no debe poder vaciarse.');
        } catch (ErrorDbal $e) {
            self::assertStringContainsString('ORA-20005', $e->getMessage());
        }
    }

    public function testReinstalarElContextoNoBorraElUsuarioDeLasSesionesAbiertas(): void
    {
        $usuario = D::crearUsuario(['rol' => 'TECNICO']);
        $rls = static::getContainer()->get(ContextoRls::class);
        $rls->fijarUsuario($usuario['id']);

        // Otra sesión (la del migrador) reinstala contexto, paquetes y políticas.
        (new EjecutorDdl(D::conexion()))->ejecutar("CREATE OR REPLACE CONTEXT INVENTARIO_CTX USING INVENTARIO.INV_CTX");
        self::assertSame([], $this->seguridad->instalar());

        self::assertSame($usuario['id'], $this->conexion->fetchOne("SELECT SYS_CONTEXT('INVENTARIO_CTX', 'USUARIO') FROM DUAL"));
        // El VPD sigue filtrando en esta sesión sin ORA-04068 (nada se recompiló): sin sedes no ve activos.
        self::assertSame(0, (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_ACTIVO"'));
    }

    public function testElUsuarioDeEjecucionSoloTieneDmlSobreLasTablasDelNegocio(): void
    {
        $permisos = static::getContainer()->get(PermisosEjecucion::class);
        self::assertSame([], $permisos->conceder('INVENTARIO_APP'));

        $this->conexion->executeStatement('GRANT SELECT ON "INV_KARDEX_SELLO" TO INVENTARIO_APP');
        $this->conexion->executeStatement('REVOKE DELETE ON "INV_ACTIVO" FROM INVENTARIO_APP');
        self::assertEqualsCanonicalizing(['concedido DELETE sobre INV_ACTIVO', 'revocado SELECT sobre INV_KARDEX_SELLO'], $permisos->conceder('INVENTARIO_APP'));
        self::assertSame([], $permisos->conceder('INVENTARIO_APP'));

        $app = $this->conexionApp();
        self::assertGreaterThanOrEqual(0, (int) $app->fetchOne('SELECT COUNT(*) FROM "INV_ACTIVO"'));
        self::assertSame('0', (string) $app->fetchOne('SELECT COUNT(*) FROM "INV_KARDEX" WHERE 1 = 0'));
        // Los predicados se leen (primera capa del alcance y verificación del VPD), nunca se modifican.
        self::assertSame(31, (int) $app->fetchOne('SELECT COUNT(*) FROM "INV_VPD_PREDICADO"'));
        $rls = new ContextoRls($app, static::getContainer()->get(\App\Config\Ajustes::class), new \Psr\Log\NullLogger());
        self::assertSame([true, 'ok'], $rls->verificar(), 'Con el usuario de ejecución la capa VPD se detecta activa.');
        foreach ([
            'UPDATE "INV_VPD_PREDICADO" SET "VPD_Predicado" = \'1 = 1\'' => 'ORA-01031',
            'DELETE FROM "INV_VPD_PREDICADO"' => 'ORA-01031',
            'UPDATE "INV_KARDEX_SELLO" SET "SEL_Secuencia" = 0' => 'ORA-00942',
            'DELETE FROM "SYS_MIGRACION_ESQUEMA"' => 'ORA-00942',
            'CREATE TABLE "X_INTRUSO" ("A" NUMBER)' => 'ORA-01031',
            "BEGIN DBMS_RLS.DROP_POLICY('INVENTARIO', 'INV_ACTIVO', 'INV_RLS_LECTURA'); END;" => 'PLS-00201',
        ] as $sql => $error) {
            try {
                $app->executeStatement($sql);
                self::fail("El usuario de ejecución no debería poder: {$sql}");
            } catch (ErrorDbal $e) {
                self::assertStringContainsString($error, $e->getMessage(), $sql);
            }
        }
        $app->close();
    }

    public function testCambiarElNombreDeLaCuentaRaizNoRompeElDespliegue(): void
    {
        $cuenta = static::getContainer()->get(CuentaInicial::class);
        $sufijo = bin2hex(random_bytes(4));
        self::assertTrue($cuenta->asegurar("raiz_{$sufijo}", "raiz_{$sufijo}@prueba.local", 'Zx9!kLm2Qw7rTT', 'Empresa')['creada']);
        $otro = bin2hex(random_bytes(4));
        self::assertTrue($cuenta->asegurar("raiz_{$otro}", "raiz_{$otro}@prueba.local", 'Zx9!kLm2Qw7rTT', 'Empresa')['creada']);
    }

    public function testLaSemillaYLaCuentaRaizSonIdempotentes(): void
    {
        $semilla = static::getContainer()->get(SemillaMinima::class);
        $cuenta = static::getContainer()->get(CuentaInicial::class);
        $usuario = 'raiz_'.D::sufijo();
        $this->conexion->beginTransaction();
        try {
            $semilla->sembrar();
            self::assertSame(0, $semilla->sembrar(), 'La segunda siembra no crea nada.');
            self::assertSame(1, (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_MOTIVO_MOVIMIENTO" WHERE "MMO_Codigo" = \'PERDIDA_ROBO\' '
                .'AND "MMO_Pide_Detalle" = 1 AND JSON_VALUE("MMO_Aplica_A", \'$[0]\') = \'DEVOLUCION\''));
            self::assertSame(1, (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_ESTADO_OPERATIVO" WHERE "EOP_Nombre" = \'En Tránsito\''));

            $creada = $cuenta->asegurar($usuario, "{$usuario}@pruebas.local", null, 'Empresa de pruebas');
            self::assertTrue($creada['creada']);
            self::assertNotNull($creada['contrasena_generada']);
            $fila = $this->conexion->fetchAssociative('SELECT "USU_Rol", "USU_Debe_Cambiar_Password", "USU_Password_Hash" FROM "INV_USUARIO" WHERE "USU_Username" = :u', ['u' => $usuario]);
            self::assertIsArray($fila);
            self::assertSame(['SUPER_ADMIN', '1'], [$fila['USU_Rol'], (string) $fila['USU_Debe_Cambiar_Password']]);
            self::assertTrue(Contrasenas::verificar($creada['contrasena_generada'], (string) $fila['USU_Password_Hash']));
            self::assertSame(['creada' => false, 'contrasena_generada' => null], $cuenta->asegurar($usuario, 'otro@pruebas.local', 'Otra.Clave#2026', 'X'));
        } finally {
            $this->conexion->rollBack();
        }
    }

    public function testLasHuellasDeLicenciasSeRecalculanConLaClaveActual(): void
    {
        $huellas = static::getContainer()->get(RecalculoHuellas::class);
        $cifrado = static::getContainer()->get(CifradoCampos::class);
        $serial = 'SER-'.D::sufijo();
        $this->conexion->beginTransaction();
        try {
            $this->conexion->executeStatement('INSERT INTO "INV_SOFTWARE" ("SOF_Nombre", "SOF_Fabricante") VALUES (:n, \'Pruebas\')', ['n' => "Sw {$serial}"]);
            $sw = (int) $this->conexion->fetchOne('SELECT MAX("SOF_Software") FROM "INV_SOFTWARE" WHERE "SOF_Nombre" = :n', ['n' => "Sw {$serial}"]);
            $this->conexion->executeStatement('INSERT INTO "INV_LICENCIA" ("LIC_Cantidad_Total", "LIC_Cantidad_Usada", "SOF_Software") VALUES (1, 0, :s)', ['s' => $sw]);
            $lic = (int) $this->conexion->fetchOne('SELECT MAX("LIC_Licencia") FROM "INV_LICENCIA" WHERE "SOF_Software" = :s', ['s' => $sw]);
            // Huella calculada con una clave HMAC anterior (rotada).
            $this->conexion->executeStatement('INSERT INTO "INV_LICENCIA_CLAVE" ("LIC_Licencia", "LCL_Estado", "LCL_Serial", "LCL_Clave_Hash") '
                .'VALUES (:l, \'DISPONIBLE\', :s, :h)', ['l' => $lic, 's' => $serial, 'h' => hash_hmac('sha256', "serial:{$serial}", 'clave-anterior')]);

            $r = $huellas->recalcular();
            self::assertGreaterThanOrEqual(1, $r['actualizadas']);
            // El serial se normaliza en mayúsculas antes de calcular la huella.
            self::assertSame($cifrado->huella('serial:'.mb_strtoupper($serial)), $this->conexion->fetchOne(
                'SELECT "LCL_Clave_Hash" FROM "INV_LICENCIA_CLAVE" WHERE "LCL_Serial" = :s', ['s' => $serial]));
            self::assertSame(0, $huellas->recalcular()['actualizadas'], 'Idempotente.');
        } finally {
            $this->conexion->rollBack();
        }
    }

    /** Sesión como el usuario de ejecución (mismo esquema por CURRENT_SCHEMA, como la API en producción). */
    private function conexionApp(): Connection
    {
        $e = static fn (string $k, string $d = ''): string => (string) ($_SERVER[$k] ?? $_ENV[$k] ?? $d);
        $app = DriverManager::getConnection([
            'driver' => 'oci8', 'host' => $e('ORACLE_HOST'), 'port' => (int) $e('ORACLE_PORT', '1521'),
            'servicename' => $e('ORACLE_SERVICE_NAME', 'XEPDB1'), 'user' => 'INVENTARIO_APP', 'password' => $e('ORACLE_PASSWORD'),
            'charset' => 'AL32UTF8', 'driverOptions' => ['exclusive' => true],
        ]);
        foreach (SesionOracleMiddleware::sentencias('INVENTARIO', 'INVENTARIO_APP') as $sql) {
            $app->executeStatement($sql);
        }

        return $app;
    }
}
