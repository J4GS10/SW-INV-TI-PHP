<?php

declare(strict_types=1);

namespace App\Tests\Resistencia;

use App\Seguridad\Tokens;
use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Inventario as I;
use App\Tests\Soporte\PeticionesSimultaneas;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Resistencia y durabilidad contra la imagen de PRODUCCIÓN (FrankenPHP en modo
 * worker, varios procesos) y Oracle real: peticiones verdaderamente simultáneas
 * (curl_multi) sobre los puntos de carrera del dominio, y carga sostenida. Tras
 * cada ráfaga se comprueban los invariantes en la base (stock nunca negativo,
 * cupos nunca excedidos, códigos únicos, una sola custodia, kardex íntegro).
 *
 *   bash herramientas/resistencia.sh
 */
#[Group('resistencia')]
final class ConcurrenciaTest extends KernelTestCase
{
    use PeticionesSimultaneas;

    private string $url;
    private int $sede;
    private string $token;

    protected function setUp(): void
    {
        $this->url = (string) getenv('RESISTENCIA_URL');
        if ('' === $this->url) {
            self::markTestSkipped('RESISTENCIA_URL no definida (usar herramientas/resistencia.sh).');
        }
        self::bootKernel();
        I::estados();
        I::motivosBaja();
        $this->sede = D::crearSede('Carga '.D::sufijo());
        $this->token = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->sede]]));
    }

    /** @param array{username: string} $u */
    private function tokenDe(array $u): string
    {
        return static::getContainer()->get(Tokens::class)->crearAcceso($u['username'], 'x');
    }

    /**
     * @param array<string, mixed>|null $cuerpo
     *
     * @return array<string, mixed>
     */
    private function uno(string $metodo, string $ruta, ?array $cuerpo = null): array
    {
        $r = $this->rafaga([[$metodo, $ruta, $cuerpo]])[0];
        self::assertLessThan(300, $r['estado'], "{$metodo} {$ruta}: ".json_encode($r['cuerpo']));

        return $r['cuerpo'];
    }

    private function activo(int $tipo, int $modelo): string
    {
        return $this->uno('POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'RS-'.D::sufijo(), 'ACT_Fecha_Compra' => '2026-01-01', 'MOD_Modelo' => $modelo,
            'TAC_Tipo_Activo' => $tipo, 'EOP_Estado_Operativo' => I::estado('En Bodega'), 'SED_Sede' => $this->sede])['ACT_Activo'];
    }

    public function testSalidasSimultaneasNuncaDejanStockNegativo(): void
    {
        $c = $this->uno('POST', '/api/v1/consumibles', ['CON_Nombre' => 'Carga '.D::sufijo(), 'CON_Stock_Actual' => 10, 'CON_Costo_Unitario' => 5, 'SED_Sede' => $this->sede]);
        $id = $c['CON_Consumible'];
        $r = $this->rafaga(array_fill(0, 30, ['POST', "/api/v1/consumibles/{$id}/salida", ['MOC_Cantidad' => 1]]));
        self::assertSame([201 => 10, 409 => 20], self::porEstado($r), 'Exactamente 10 salidas de 30 (stock 10).');
        self::assertSame(0, D::contar('SELECT "CON_Stock_Actual" FROM "INV_CONSUMIBLE" WHERE "CON_Consumible" = :c', ['c' => $id]));
        $saldos = array_map('intval', D::conexion()->fetchFirstColumn("SELECT \"KDX_Saldo\" FROM \"INV_KARDEX\" WHERE \"CON_Consumible\" = :c AND \"KDX_Tipo\" = 'CONSUMIBLE_SALIDA' "
            .'ORDER BY "KDX_Secuencia"', ['c' => $id]));
        self::assertSame(range(9, 0), $saldos, 'El kardex del consumible es una serie exacta 9..0, sin saltos ni duplicados.');
        self::assertSame(10, D::contar("SELECT COUNT(*) FROM \"INV_MOVIMIENTO_CONSUMIBLE\" WHERE \"CON_Consumible\" = :c AND \"MOC_Tipo\" = 'SALIDA'", ['c' => $id]));
    }

    public function testCuposDeLicenciaNoSeExcedenBajoCarga(): void
    {
        $tipo = I::tipo('COMPUTO');
        $modelo = I::modelo($tipo);
        I::formaConteo('EQUIPO');
        $lic = $this->uno('POST', '/api/v1/soft/licencias', ['SOF_Software' => I::software(), 'TLI_Tipo_Licencia' => I::tipoLicencia(), 'LIC_Cantidad_Total' => 5]);
        $activos = array_map(fn (): string => $this->activo($tipo, $modelo), range(1, 20));
        $r = $this->rafaga(array_map(static fn (string $a): array => ['POST', '/api/v1/soft/instalaciones', ['ACT_Activo' => $a, 'LIC_Licencia' => $lic['LIC_Licencia']]], $activos));
        self::assertSame([201 => 5, 409 => 15], self::porEstado($r));
        self::assertSame(5, D::contar('SELECT "LIC_Cantidad_Usada" FROM "INV_LICENCIA" WHERE "LIC_Licencia" = :l', ['l' => $lic['LIC_Licencia']]));
        self::assertSame(5, D::contar('SELECT COUNT(*) FROM "INV_INSTALACION" WHERE "LIC_Licencia" = :l AND "INS_Estado" = 1', ['l' => $lic['LIC_Licencia']]));
    }

    public function testAltasSimultaneasRecibenCodigosCorrelativosUnicos(): void
    {
        $tipo = I::tipo('COMPUTO');
        $modelo = I::modelo($tipo);
        $r = $this->rafaga(array_map(fn (int $i): array => ['POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => "PAR-{$i}-".D::sufijo(),
            'ACT_Fecha_Compra' => '2026-01-01', 'MOD_Modelo' => $modelo, 'TAC_Tipo_Activo' => $tipo, 'EOP_Estado_Operativo' => I::estado('En Bodega'),
            'SED_Sede' => $this->sede]], range(1, 25)));
        self::assertSame([201 => 25], self::porEstado($r), json_encode(array_slice(array_column($r, 'cuerpo'), 0, 3)));
        $codigos = array_map(static fn (array $x): string => $x['cuerpo']['ACT_Codigo_Interno'], $r);
        self::assertCount(25, array_unique($codigos), 'Ningún código repetido.');
        $numeros = array_map(static fn (string $c): int => (int) substr($c, -5), $codigos);
        sort($numeros);
        self::assertSame(range(1, 25), $numeros, 'Correlativos sin huecos (1..25).');

        // La misma serie enviada a la vez: solo una entra.
        $serie = 'DUP-'.D::sufijo();
        $dup = $this->rafaga(array_fill(0, 8, ['POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => $serie, 'ACT_Fecha_Compra' => '2026-01-01',
            'MOD_Modelo' => $modelo, 'TAC_Tipo_Activo' => $tipo, 'EOP_Estado_Operativo' => I::estado('En Bodega'), 'SED_Sede' => $this->sede]]));
        self::assertSame(1, self::porEstado($dup)[201] ?? 0, 'Una sola alta con la misma serie: '.json_encode(self::porEstado($dup)));
        self::assertSame(1, D::contar('SELECT COUNT(*) FROM "INV_ACTIVO" WHERE "ACT_Serie_Fabricante" = :s', ['s' => $serie]));
    }

    public function testAsignacionesSimultaneasDelMismoEquipoDejanUnaSolaCustodia(): void
    {
        foreach (['Asignación'] as $t) {
            if (0 === D::contar('SELECT COUNT(*) FROM "INV_TIPO_MOVIMIENTO" WHERE "TMO_Nombre" = :n', ['n' => $t])) {
                D::conexion()->executeStatement('INSERT INTO "INV_TIPO_MOVIMIENTO" ("TMO_Nombre") VALUES (:n)', ['n' => $t]);
            }
        }
        $tmo = (int) D::conexion()->fetchOne("SELECT MIN(\"TMO_Tipo_Movimiento\") FROM \"INV_TIPO_MOVIMIENTO\" WHERE \"TMO_Nombre\" = 'Asignación'");
        $tipo = I::tipo('COMPUTO');
        $activo = $this->activo($tipo, I::modelo($tipo));
        $area = I::area($this->sede);
        $personas = array_map(fn (): string => D::crearPersona($this->sede, 'P'), range(1, 10));
        $uuid = static fn (string $h): string => strtolower(substr($h, 0, 8).'-'.substr($h, 8, 4).'-'.substr($h, 12, 4).'-'.substr($h, 16, 4).'-'.substr($h, 20));
        $r = $this->rafaga(array_map(static fn (string $p): array => ['POST', '/api/v1/trazabilidad/movimientos', ['ACT_Activo' => $activo, 'PER_Persona' => $uuid($p),
            'ARE_Area' => $area, 'TMO_Tipo_Movimiento' => $tmo, 'MOV_Motivo' => 'Carga']], $personas));
        self::assertSame(1, self::porEstado($r)[201] ?? 0, json_encode(self::porEstado($r)));
        self::assertSame(1, D::contar('SELECT COUNT(*) FROM "INV_MOVIMIENTO" WHERE "ACT_Activo" = :a AND "MOV_Fecha_Devolucion" IS NULL', ['a' => str_replace('-', '', $activo)]));
        self::assertSame(1, D::contar("SELECT COUNT(*) FROM \"INV_KARDEX\" WHERE \"ACT_Activo\" = :a AND \"KDX_Tipo\" = 'ASIGNACION'", ['a' => str_replace('-', '', $activo)]));
    }

    public function testDosAprobadoresALaVezEjecutanLaBajaUnaSolaVez(): void
    {
        $tipo = I::tipo('COMPUTO');
        $activo = $this->activo($tipo, I::modelo($tipo));
        $s = $this->uno('POST', "/api/v1/core/activos/{$activo}/baja", ['motivo' => 'OBSOLESCENCIA', 'destino' => 'RECICLAJE']);
        $jefes = array_map(fn (): string => $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->sede]])), range(1, 6));
        $r = $this->rafaga(array_map(static fn (string $t): array => ['POST', "/api/v1/aprobaciones/{$s['APR_Aprobacion']}/aprobar", ['comentario' => 'Aprobado bajo carga'], $t], $jefes));
        self::assertSame(1, self::porEstado($r)[200] ?? 0, json_encode(self::porEstado($r)));
        self::assertSame(1, D::contar("SELECT COUNT(*) FROM \"INV_KARDEX\" WHERE \"ACT_Activo\" = :a AND \"KDX_Tipo\" = 'BAJA'", ['a' => str_replace('-', '', $activo)]));
    }

    public function testCargaSostenidaSinErroresYKardexIntegro(): void
    {
        $tipo = I::tipo('COMPUTO');
        $modelo = I::modelo($tipo);
        foreach (range(1, 5) as $_) {
            $this->activo($tipo, $modelo);
        }
        $lecturas = ['/api/v1/core/activos?limit=50', '/api/v1/stats/dashboard', '/api/v1/inventario/kardex?limite=50', '/api/v1/inventario/atencion',
            '/api/v1/consumibles', '/api/v1/aprobaciones', '/api/v1/trazabilidad/movimientos?limit=50', '/api/v1/geo/sedes', '/api/v1/me'];
        $tiempos = [];
        $errores = 0;
        $inicio = microtime(true);
        foreach (range(1, 8) as $ronda) {
            $peticiones = [];
            foreach (range(1, 50) as $i) {
                $peticiones[] = 0 === $i % 10
                    ? ['POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => "LD-{$ronda}-{$i}-".D::sufijo(), 'ACT_Fecha_Compra' => '2026-01-01', 'MOD_Modelo' => $modelo,
                        'TAC_Tipo_Activo' => $tipo, 'EOP_Estado_Operativo' => I::estado('En Bodega'), 'SED_Sede' => $this->sede]]
                    : ['GET', $lecturas[$i % \count($lecturas)]];
            }
            foreach ($this->rafaga($peticiones) as $res) {
                $tiempos[] = $res['ms'];
                if ($res['estado'] >= 500 || 0 === $res['estado']) {
                    ++$errores;
                }
            }
        }
        $duracion = microtime(true) - $inicio;
        sort($tiempos);
        $p = static fn (float $q): float => $tiempos[(int) floor($q * (\count($tiempos) - 1))];
        fwrite(\STDERR, \sprintf("\n[carga] %d peticiones (50 concurrentes) en %.1f s · %.0f req/s · p50 %.0f ms · p95 %.0f ms · p99 %.0f ms · errores 5xx %d\n",
            \count($tiempos), $duracion, \count($tiempos) / $duracion, $p(0.5), $p(0.95), $p(0.99), $errores));
        self::assertSame(0, $errores, 'Sin errores 5xx ni conexiones caídas bajo carga.');
        self::assertLessThan(5000.0, $p(0.95), 'p95 bajo 5 s con 50 peticiones simultáneas.');
        self::assertTrue(I::cadenaKardexIntegra(), 'El sellado del kardex sigue íntegro tras la carga.');
        $jefe = $this->tokenDe(D::crearUsuario(['rol' => 'SUPER_ADMIN', 'global' => true]));
        $v = $this->rafaga([['GET', '/api/v1/inventario/kardex/verificar', null, $jefe]])[0];
        self::assertSame([200, true], [$v['estado'], $v['cuerpo']['integro'] ?? null], json_encode($v['cuerpo']['problemas'] ?? null));
    }
    // ================= Fase 4: archivos =================

    public function testImportacionConfirmadaAlaVezCreaCadaActivoUnaSolaVez(): void
    {
        $prefijo = 'R'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
        $tipo = I::tipo('COMPUTO', $prefijo);
        $area = I::area($this->sede);
        $serie = 'IMP-'.D::sufijo();
        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $libro->getActiveSheet()->setTitle('INVENTARIO')->fromArray([['Categoría', 'Marca', 'Modelo', 'Serie', 'Ubicación', 'Fecha de compra'],
            [D::conexion()->fetchOne('SELECT "TAC_Nombre" FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', ['t' => $tipo]), 'Carga', 'M1', $serie,
                D::conexion()->fetchOne('SELECT "ARE_Nombre" FROM "INV_AREA" WHERE "ARE_Area" = :a', ['a' => $area]), '2026-10-01']], null, 'A1', true);
        $archivo = (string) tempnam(sys_get_temp_dir(), 'imp');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($archivo);
        $r = $this->rafaga(array_fill(0, 6, ['POST', '/api/v1/productividad/importar/activos/confirmar', null, $this->token,
            ['archivo' => new \CURLFile($archivo, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'inventario.xlsx')]]));
        unlink($archivo);
        $estados = self::porEstado($r);
        self::assertSame(1, $estados[201] ?? 0, 'Una sola confirmación crea el activo: '.json_encode($estados));
        self::assertArrayNotHasKey(500, $estados, json_encode(array_column($r, 'cuerpo')));
        self::assertSame(1, D::contar('SELECT COUNT(*) FROM "INV_ACTIVO" WHERE "ACT_Serie_Fabricante" = :s', ['s' => $serie]));
        self::assertTrue(I::cadenaKardexIntegra());
    }

    public function testArchivosPesadosEnParaleloSinErrores(): void
    {
        $tipo = I::tipo('COMPUTO');
        $modelo = I::modelo($tipo);
        $activos = array_map(fn (): string => $this->activo($tipo, $modelo), range(1, 3));
        $peticiones = [];
        foreach (range(1, 4) as $vuelta) {
            foreach ($activos as $a) {
                $peticiones[] = ['GET', "/api/v1/inventario/activos/{$a}/hoja-de-vida.pdf"];
            }
            $peticiones[] = ['GET', '/api/v1/inventario/kardex.csv'];
            $peticiones[] = ['GET', '/api/v1/export/activos.csv'];
            $peticiones[] = ['GET', '/api/v1/inventario/cierre-mensual.xlsx?anio=2026&mes=10'];
            $peticiones[] = ['POST', '/api/v1/core/activos/etiquetas.pdf', ['activos_ids' => $activos]];
            $peticiones[] = ['GET', '/api/v1/productividad/importar/activos/plantilla'];
        }
        $inicio = microtime(true);
        $r = $this->rafaga($peticiones);
        $duracion = microtime(true) - $inicio;
        $tiempos = array_column($r, 'ms');
        sort($tiempos);
        fwrite(\STDERR, \sprintf("\n[archivos] %d documentos simultáneos en %.1f s · p95 %.0f ms · %s\n", \count($r), $duracion,
            $tiempos[(int) floor(0.95 * (\count($tiempos) - 1))], json_encode(self::porEstado($r))));
        self::assertSame([200 => \count($peticiones)], self::porEstado($r), 'Todos los documentos se generan, sin 5xx ni memoria agotada.');
    }
}
