<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Esquema\DefinicionSeguridad;
use App\Esquema\FuentePlsql;
use App\Esquema\ObjetoPlsql;
use App\Esquema\PoliticasVpd;
use App\Esquema\PoliticaVpd;
use App\Esquema\ScriptSql;
use PHPUnit\Framework\TestCase;

/** Caja blanca del migrador de esquema: scripts, normalización PL/SQL y definiciones. */
final class EsquemaTest extends TestCase
{
    public function testElScriptSeDivideEnSentenciasRespetandoLiterales(): void
    {
        $script = ScriptSql::desdeTexto(<<<'SQL'
            -- comentario inicial
            CREATE TABLE "T1"
               ("A" VARCHAR2(10) DEFAULT 'x;y' NOT NULL ENABLE
               ) ;

            ALTER TABLE "T1" ADD CONSTRAINT "CK" CHECK ("A" IN ('a;
            b', 'c')) ENABLE;
            CREATE INDEX "IX" ON "T1" ("A");
            SQL);

        self::assertCount(3, $script->sentencias());
        self::assertStringEndsWith(')', $script->sentencias()[0], 'Sin el «;» final (oci8 no lo acepta).');
        self::assertStringContainsString("'a;\n", $script->sentencias()[1]);
        self::assertSame(['T1'], $script->tablasCreadas());
    }

    public function testUnScriptSinTerminarSeRechaza(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        ScriptSql::desdeTexto('CREATE TABLE "T" ("A" NUMBER)');
    }

    public function testLaLineaBaseTieneElEsquemaCompletoDeProduccion(): void
    {
        $script = ScriptSql::desdeArchivo(\dirname(__DIR__, 2).'/migrations/linea_base.sql');

        self::assertCount(631, $script->sentencias());
        self::assertCount(78, $script->tablasCreadas());
        self::assertContains('INV_VPD_PREDICADO', $script->tablasCreadas());
        self::assertContains('INV_KARDEX_SELLO', $script->tablasCreadas());
        foreach ($script->sentencias() as $sql) {
            self::assertDoesNotMatchRegularExpression('/"INVENTARIO"\.|DBMS_RLS|CREATE (OR REPLACE )?(EDITIONABLE )?(PACKAGE|TRIGGER)/', $sql);
        }
    }

    public function testLaFuenteSeComparaSinComentariosNiEspaciosYConLaCabeceraCanonica(): void
    {
        $instalada = "TRIGGER \"INVENTARIO\".\"TRG_X\"\n  BEFORE DELETE ON \"T\" -- comentario\n BEGIN\n  /* otro */ NULL; END;";
        $esperada = 'TRIGGER trg_x BEFORE DELETE ON "T" BEGIN NULL; END;';

        self::assertSame(FuentePlsql::normalizar($esperada), FuentePlsql::normalizar($instalada));
        // Un literal con «--» no es un comentario: cambiarlo sí cuenta.
        self::assertNotSame(FuentePlsql::normalizar("x := '--a';"), FuentePlsql::normalizar("x := '--b';"));
        self::assertSame("x := 'it''s -- no'; y", FuentePlsql::normalizar("x := 'it''s -- no'; -- sí\n y"));
    }

    public function testElObjetoPlsqlComparaContraUserSource(): void
    {
        $objeto = new ObjetoPlsql(ObjetoPlsql::CUERPO, 'INV_CTX', "CREATE OR REPLACE PACKAGE BODY INV_CTX AS\n  c CONSTANT NUMBER := 1;\nEND INV_CTX;");

        self::assertTrue($objeto->equivaleA("PACKAGE BODY INV_CTX AS\n\tc CONSTANT NUMBER := 1;  -- igual\nEND INV_CTX;\n"));
        self::assertFalse($objeto->equivaleA("PACKAGE BODY INV_CTX AS c CONSTANT NUMBER := 2; END INV_CTX;"));
        self::assertFalse($objeto->equivaleA(null));
        $this->expectException(\InvalidArgumentException::class);
        new ObjetoPlsql('VIEW', 'V', 'CREATE VIEW V AS SELECT 1 FROM DUAL');
    }

    public function testLasPoliticasCubrenLasTablasConAlcancePorSede(): void
    {
        $politicas = (new PoliticasVpd('INVENTARIO_CTX'))->todas();

        self::assertCount(31, $politicas);
        $nombres = array_map(static fn (PoliticaVpd $p): string => "{$p->tabla}/{$p->operacion}", $politicas);
        self::assertSame($nombres, array_values(array_unique($nombres)));
        foreach ($politicas as $p) {
            self::assertStringNotContainsString('{ctx}', $p->predicado);
            self::assertStringStartsWith(PoliticaVpd::PREFIJO, $p->nombre());
        }
        $insercion = array_values(array_filter($politicas, static fn (PoliticaVpd $p): bool => PoliticaVpd::INSERCION === $p->operacion))[0];
        self::assertSame(['SEL' => 'NO', 'INS' => 'YES', 'UPD' => 'NO', 'DEL' => 'NO'], $insercion->banderas());
        self::assertStringContainsString("SYS_CONTEXT('INVENTARIO_CTX', 'USUARIO')", $insercion->predicado);
        $this->expectException(\InvalidArgumentException::class);
        new PoliticasVpd("X') OR 1=1 --");
    }

    public function testElJsonDelKardexExcluyeElHashYOrdenaLasColumnas(): void
    {
        $d = new DefinicionSeguridad('INVENTARIO', ['KDX_Hash', 'USU_Usuario', 'KDX_Detalle', 'ACT_Activo'], ['SUPER_ADMIN']);
        $cuerpo = array_values(array_filter($d->objetos(), static fn (ObjetoPlsql $o): bool => 'INV_KARDEX_PKG' === $o->nombre && ObjetoPlsql::CUERPO === $o->tipo))[0];

        self::assertStringContainsString("JSON_OBJECT('ACT_Activo' VALUE k.\"ACT_Activo\", 'KDX_Detalle' VALUE k.\"KDX_Detalle\" FORMAT JSON, "
            ."'USU_Usuario' VALUE k.\"USU_Usuario\" ABSENT ON NULL RETURNING CLOB)", $cuerpo->ddl);
        self::assertStringNotContainsString("'KDX_Hash'", $cuerpo->ddl);
        self::assertSame('CREATE OR REPLACE CONTEXT INVENTARIO_CTX USING INVENTARIO.INV_CTX', $d->ddlContexto());
        self::assertCount(13 + \count(\App\Db\LogicalDelete::TABLES), $d->objetos());
    }

    public function testLosRegistrosDeNegocioSoloAdmitenBorradoLogico(): void
    {
        $d = new DefinicionSeguridad('INVENTARIO', ['KDX_Hash'], ['SUPER_ADMIN']);
        $disparadores = array_values(array_filter($d->objetos(), static fn (ObjetoPlsql $o): bool => str_starts_with($o->nombre, 'TRG_BL_')));

        self::assertCount(\count(\App\Db\LogicalDelete::TABLES), $disparadores);
        $licencia = array_values(array_filter($disparadores, static fn (ObjetoPlsql $o): bool => 'TRG_BL_INV_LICENCIA' === $o->nombre))[0];
        self::assertStringContainsString('BEFORE DELETE ON "INV_LICENCIA" FOR EACH ROW', $licencia->ddl);
        self::assertStringContainsString("SYS_CONTEXT('INVENTARIO_CTX', 'USUARIO') IS NOT NULL", $licencia->ddl);
        self::assertStringContainsString('RAISE_APPLICATION_ERROR(-'.DefinicionSeguridad::ERR_BORRADO_FISICO, $licencia->ddl);
    }
}
