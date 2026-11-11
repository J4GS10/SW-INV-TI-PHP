<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Seguridad\Tokens;
use App\Tests\Soporte\DatosPrueba as D;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** /cat y /catalogos contra Oracle real. */
final class CatalogosTest extends WebTestCase
{
    private KernelBrowser $cliente;
    private string $admin;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
        $this->admin = $this->token(D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]));
    }

    public function testTipoDeActivoConClaseDeducidaReglasYPlantilla(): void
    {
        // Nombre propio de la prueba: la clase se deduce de la palabra «impresora» y no se toca el tipo real.
        $nombre = 'Impresora '.D::sufijo();

        [$e, $tipo] = $this->pedir('POST', '/api/v1/cat/tipos-activo', ['TAC_Nombre' => $nombre]);
        self::assertSame(201, $e);
        self::assertSame('IMPRESION', $tipo['TAC_Clase']);
        self::assertTrue($tipo['TAC_Aplica_Depreciacion']);
        self::assertSame(['clase' => 'IMPRESION', 'uso' => 'COMPARTIDO', 'software' => false, 'componentes' => true, 'red' => true, 'consumibles' => true], $tipo['reglas']);
        self::assertSame([400, 'ASSET_TYPE_ALREADY_EXISTS'], $this->detalle('POST', '/api/v1/cat/tipos-activo', ['TAC_Nombre' => $nombre]));

        [, $especificaciones] = $this->pedir('GET', "/api/v1/catalogos/especificaciones-tipo/{$tipo['TAC_Tipo_Activo']}");
        self::assertSame(['Tecnología de impresión', 'Impresión a color', 'Dirección IP', 'Dirección MAC', 'Contador de páginas'], array_column($especificaciones, 'nombre'));
        self::assertSame(['Sí', 'No'], $especificaciones[1]['valores']);
        self::assertSame([10, 20, 30, 40, 50], array_column($especificaciones, 'orden'));

        self::assertSame([422, 'VALIDATION_ERROR'], $this->detalle('POST', '/api/v1/cat/tipos-activo', ['TAC_Nombre' => 'X1', 'TAC_Clase' => 'NAVE']));
    }

    public function testComponentesSegunLaClase(): void
    {
        [, $periferico] = $this->pedir('POST', '/api/v1/cat/tipos-activo', ['TAC_Nombre' => 'Periférico '.D::sufijo(), 'TAC_Clase' => 'PERIFERICO']);
        [, $computo] = $this->pedir('POST', '/api/v1/cat/tipos-activo', ['TAC_Nombre' => 'Equipo '.D::sufijo(), 'TAC_Clase' => 'COMPUTO']);
        [, $ram] = $this->pedir('POST', '/api/v1/cat/tipos-activo', ['TAC_Nombre' => 'RAM '.D::sufijo(), 'TAC_Clase' => 'COMPONENTE']);

        self::assertSame([422, 'ASSET_CLASS_NO_COMPONENTS'], $this->detalle('PUT', "/api/v1/cat/tipos-activo/{$periferico['TAC_Tipo_Activo']}/componentes", ['componentes' => [$ram['TAC_Tipo_Activo']]]));
        $id = $computo['TAC_Tipo_Activo'];
        self::assertSame([200, [$ram['TAC_Tipo_Activo']]], $this->pedir('PUT', "/api/v1/cat/tipos-activo/{$id}/componentes", ['componentes' => [$ram['TAC_Tipo_Activo'], $ram['TAC_Tipo_Activo'], $id]]));
        self::assertSame([200, [$ram['TAC_Tipo_Activo']]], $this->pedir('GET', "/api/v1/cat/tipos-activo/{$id}/componentes"));
        self::assertSame([404, 'ASSET_TYPE_NOT_FOUND'], $this->detalle('PUT', "/api/v1/cat/tipos-activo/{$id}/componentes", ['componentes' => [999999999]]));
    }

    public function testMarcasModelosUnicidadYUso(): void
    {
        $nombre = 'Marca '.D::sufijo();
        [$e, $marca] = $this->pedir('POST', '/api/v1/cat/marcas', ['MAR_Nombre' => $nombre]);
        self::assertSame([201, ['MAR_Marca', 'MAR_Nombre']], [$e, array_keys($marca)]);
        self::assertSame([409, 'BRAND_ALREADY_EXISTS'], $this->detalle('POST', '/api/v1/cat/marcas', ['MAR_Nombre' => ' '.strtoupper($nombre)]));

        [$e, $modelo] = $this->pedir('POST', '/api/v1/cat/modelos', ['MOD_Nombre' => 'Modelo 100%_A', 'MAR_Marca' => $marca['MAR_Marca'], 'MOD_Anio_Lanzamiento' => 2024]);
        self::assertSame(201, $e);
        self::assertSame([422, 'VALIDATION_ERROR'], $this->detalle('POST', '/api/v1/cat/modelos', ['MOD_Nombre' => 'Viejo', 'MAR_Marca' => $marca['MAR_Marca'], 'MOD_Anio_Lanzamiento' => 1900]));
        [, $planos] = $this->pedir('GET', '/api/v1/cat/modelos-flat?q='.rawurlencode('100%_'));
        self::assertContains($modelo['MOD_Modelo'], array_column($planos, 'MOD_Modelo'));
        [, $nada] = $this->pedir('GET', '/api/v1/cat/modelos-flat?q='.rawurlencode('%'));
        self::assertNotContains('_ningún_otro_', array_column($nada, 'MOD_Nombre'), 'Un % del usuario es literal, no comodín.');
        self::assertSame([409, 'CANNOT_DELETE_BRAND_IN_USE'], $this->detalle('DELETE', "/api/v1/cat/marcas/{$marca['MAR_Marca']}"));
        [, $contexto] = $this->pedir('GET', "/api/v1/cat/modelos/{$modelo['MOD_Modelo']}/contexto");
        self::assertSame(['marca' => ['MAR_Marca' => $marca['MAR_Marca'], 'nombre' => $nombre], 'tipo_sugerido' => null], $contexto);
    }

    public function testEstadosOperativosDelSistemaProtegidos(): void
    {
        [$e, $estado] = $this->pedir('POST', '/api/v1/cat/estados-operativos', ['EOP_Nombre' => 'En Bodega']);
        if (201 !== $e) {
            $estado = ['EOP_Estado_Operativo' => (int) D::conexion()->fetchOne('SELECT "EOP_Estado_Operativo" FROM "INV_ESTADO_OPERATIVO" WHERE LOWER("EOP_Nombre") = \'en bodega\'')];
        }
        $id = $estado['EOP_Estado_Operativo'];
        self::assertSame([409, 'CANNOT_MODIFY_SYSTEM_OPERATIONAL_STATUS'], $this->detalle('PATCH', "/api/v1/cat/estados-operativos/{$id}", ['EOP_Nombre' => 'Otro']));
        self::assertSame([409, 'CANNOT_DELETE_SYSTEM_OPERATIONAL_STATUS'], $this->detalle('DELETE', "/api/v1/cat/estados-operativos/{$id}"));
    }

    public function testCatalogoGenericoCrearEditarSistemaYCampos(): void
    {
        [, $catalogos] = $this->pedir('GET', '/api/v1/catalogos');
        self::assertCount(17, $catalogos);

        $codigoSistema = 'BODEGA';
        if (false === D::conexion()->fetchOne('SELECT 1 FROM "INV_TIPO_AREA" WHERE "TAR_Codigo" = :c', ['c' => $codigoSistema])) {
            D::conexion()->executeStatement('INSERT INTO "INV_TIPO_AREA" ("TAR_Nombre", "TAR_Codigo", "TAR_Orden", "TAR_Activo") VALUES (\'Bodega\', :c, 10, 1)', ['c' => $codigoSistema]);
        }
        $sistema = (int) D::conexion()->fetchOne('SELECT "TAR_Tipo_Area" FROM "INV_TIPO_AREA" WHERE "TAR_Codigo" = :c', ['c' => $codigoSistema]);
        self::assertSame([409, 'CATALOG_SYSTEM_ITEM'], $this->detalle('PATCH', "/api/v1/catalogos/tipos-area/{$sistema}", ['nombre' => 'Almacén']));
        self::assertSame([409, 'CATALOG_SYSTEM_ITEM'], $this->detalle('PATCH', "/api/v1/catalogos/tipos-area/{$sistema}", ['activo' => false]));

        $nombre = 'Sala '.D::sufijo();
        [$e, $item] = $this->pedir('POST', '/api/v1/catalogos/tipos-area', ['nombre' => $nombre, 'descripcion' => '  ']);
        self::assertSame(201, $e);
        self::assertSame(['id', 'nombre', 'codigo', 'descripcion', 'orden', 'activo', 'fusionado_en', 'sistema', 'uso', 'extras'], array_keys($item));
        self::assertSame([null, null, true, false, 0], [$item['codigo'], $item['descripcion'], $item['activo'], $item['sistema'], $item['uso']]);
        self::assertSame([409, 'CATALOG_ITEM_ALREADY_EXISTS'], $this->detalle('POST', '/api/v1/catalogos/tipos-area', ['nombre' => strtoupper($nombre)]));
        [, $editado] = $this->pedir('PATCH', "/api/v1/catalogos/tipos-area/{$item['id']}", ['activo' => false, 'orden' => 5]);
        self::assertSame([false, 5], [$editado['activo'], $editado['orden']]);
        [, $activos] = $this->pedir('GET', '/api/v1/catalogos/tipos-area');
        self::assertNotContains($item['id'], array_column($activos, 'id'));
        [, $todos] = $this->pedir('GET', '/api/v1/catalogos/tipos-area?inactivos=true');
        self::assertContains($item['id'], array_column($todos, 'id'));

        [$e, $motivo] = $this->pedir('POST', '/api/v1/catalogos/motivos-baja', ['nombre' => 'Daño por agua '.D::sufijo(), 'extras' => ['MBA_Pide_Detalle' => true]]);
        self::assertSame(201, $e);
        self::assertMatchesRegularExpression('/^DANO_POR_AGUA_[A-Z0-9]+$/', $motivo['codigo'], 'Código automático sin acentos.');
        self::assertSame(['MBA_Pide_Detalle' => true], $motivo['extras']);
        self::assertSame([422, 'CATALOG_FIELD_NOT_ALLOWED:Otro'], $this->detalle('POST', '/api/v1/catalogos/motivos-baja', ['nombre' => 'X '.D::sufijo(), 'extras' => ['Otro' => 1]]));
        self::assertSame([422, 'CATALOG_FIELD_INVALID:MMO_Aplica_A'], $this->detalle('POST', '/api/v1/catalogos/motivos-movimiento', ['nombre' => 'M '.D::sufijo(), 'extras' => ['MMO_Aplica_A' => ['NADA']]]));
        [, $mm] = $this->pedir('POST', '/api/v1/catalogos/motivos-movimiento', ['nombre' => 'M '.D::sufijo(), 'extras' => ['MMO_Aplica_A' => ['PRESTAMO', 'ASIGNACION', 'PRESTAMO']]]);
        self::assertSame(['ASIGNACION', 'PRESTAMO'], $mm['extras']['MMO_Aplica_A']);
        self::assertSame([409, 'CATALOG_CLOSED'], $this->detalle('POST', '/api/v1/catalogos/formas-conteo', ['nombre' => 'Por gato']));
        self::assertSame([404, 'CATALOG_NOT_FOUND'], $this->detalle('GET', '/api/v1/catalogos/no-existe'));
    }

    public function testNoSeFusionanEspecificacionesDeDistintoTipoDeDatoOUnidad(): void
    {
        $unidad = fn (): int => $this->pedir('POST', '/api/v1/catalogos/unidades-medida', ['nombre' => 'Unidad '.D::sufijo()])[1]['id'];
        $crear = function (string $tipo, ?int $unidad): int {
            $nombre = 'Espec '.D::sufijo();
            D::conexion()->executeStatement('INSERT INTO "INV_TIPO_ESPECIFICACION" ("TES_Nombre", "TES_Data_Type", "UME_Unidad") VALUES (:n, :d, :u)', ['n' => $nombre, 'd' => $tipo, 'u' => $unidad]);

            return (int) D::conexion()->fetchOne('SELECT "TES_Tipo_Especificacion" FROM "INV_TIPO_ESPECIFICACION" WHERE "TES_Nombre" = :n', ['n' => $nombre]);
        };
        $gb = $unidad();
        $capacidad = $crear('NUMBER', $gb);
        $tecnologia = $crear('TEXT', null);
        $otraUnidad = $crear('NUMBER', $unidad());
        $gemela = $crear('NUMBER', $gb);

        foreach ([$tecnologia, $otraUnidad] as $destino) {
            self::assertSame([422, 'CATALOG_MERGE_INCOMPATIBLE'], $this->detalle('POST', "/api/v1/catalogos/tipos-especificacion/{$capacidad}/fusionar/{$destino}"));
        }
        self::assertSame(200, $this->pedir('POST', "/api/v1/catalogos/tipos-especificacion/{$capacidad}/fusionar/{$gemela}")[0]);
    }

    public function testFusionDeMarcasUneModelosGemelosYExigeAlcanceGlobal(): void
    {
        [, $buena] = $this->pedir('POST', '/api/v1/cat/marcas', ['MAR_Nombre' => 'Kingston '.D::sufijo()]);
        [, $mala] = $this->pedir('POST', '/api/v1/cat/marcas', ['MAR_Nombre' => 'Kingstom '.D::sufijo()]);
        [, $gemelo] = $this->pedir('POST', '/api/v1/cat/modelos', ['MOD_Nombre' => 'Fury', 'MAR_Marca' => $buena['MAR_Marca']]);
        [, $duplicado] = $this->pedir('POST', '/api/v1/cat/modelos', ['MOD_Nombre' => ' fury', 'MAR_Marca' => $mala['MAR_Marca']]);
        [, $unico] = $this->pedir('POST', '/api/v1/cat/modelos', ['MOD_Nombre' => 'Beast', 'MAR_Marca' => $mala['MAR_Marca']]);
        $ruta = "/api/v1/catalogos/marcas/{$mala['MAR_Marca']}/fusionar/{$buena['MAR_Marca']}";

        [$e, $previa] = $this->pedir('GET', $ruta);
        self::assertSame([200, ['INV_MODELO.MAR_Marca' => 2, 'INV_ACTIVO.MAR_Marca' => 0], 2], [$e, $previa['conteos'], $previa['total']]);
        $restringido = $this->token(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [D::crearSede('S '.D::sufijo())]]));
        self::assertSame([403, 'CATALOG_MERGE_REQUIRES_GLOBAL_SCOPE'], $this->detalle('POST', $ruta, null, $restringido));

        [$e, $resultado] = $this->pedir('POST', $ruta);
        self::assertSame([200, 1], [$e, $resultado['modelos_unidos']]);
        self::assertFalse(D::conexion()->fetchOne('SELECT 1 FROM "INV_MODELO" WHERE "MOD_Modelo" = :m', ['m' => $duplicado['MOD_Modelo']]), 'El modelo gemelo se elimina.');
        self::assertSame($buena['MAR_Marca'], (int) D::conexion()->fetchOne('SELECT "MAR_Marca" FROM "INV_MODELO" WHERE "MOD_Modelo" = :m', ['m' => $unico['MOD_Modelo']]));
        self::assertSame($gemelo['MOD_Modelo'], (int) D::conexion()->fetchOne('SELECT "MOD_Modelo" FROM "INV_MODELO" WHERE "MOD_Modelo" = :m', ['m' => $gemelo['MOD_Modelo']]));
        [, $todos] = $this->pedir('GET', '/api/v1/catalogos/marcas?inactivos=true');
        $origen = array_values(array_filter($todos, static fn (array $m): bool => $m['id'] === $mala['MAR_Marca']))[0];
        self::assertSame([false, $buena['MAR_Marca']], [$origen['activo'], $origen['fusionado_en']]);
        self::assertSame([409, 'CATALOG_ITEM_MERGED'], $this->detalle('POST', $ruta));
        self::assertSame([422, 'CATALOG_MERGE_SAME_ITEM'], $this->detalle('POST', "/api/v1/catalogos/marcas/{$buena['MAR_Marca']}/fusionar/{$buena['MAR_Marca']}"));
    }

    public function testKitsYEspecificacionesPorTipo(): void
    {
        $cargo = (int) D::conexion()->fetchOne('SELECT MIN("CAR_Cargo") FROM "INV_CARGO"');
        D::conexion()->executeStatement('DELETE FROM "INV_KIT_ESTANDAR_ITEM" WHERE "KIT_Kit" IN (SELECT "KIT_Kit" FROM "INV_KIT_ESTANDAR" WHERE "CAR_Cargo" = :c)', ['c' => $cargo]);
        D::conexion()->executeStatement('DELETE FROM "INV_KIT_ESTANDAR" WHERE "CAR_Cargo" = :c', ['c' => $cargo]);
        [, $tipo] = $this->pedir('POST', '/api/v1/cat/tipos-activo', ['TAC_Nombre' => 'Tipo kit '.D::sufijo(), 'TAC_Clase' => 'OTRO']);

        [$e, $kit] = $this->pedir('POST', '/api/v1/catalogos/kits', ['KIT_Nombre' => 'Kit oficina', 'CAR_Cargo' => $cargo, 'items' => [['TAC_Tipo_Activo' => $tipo['TAC_Tipo_Activo'], 'KIT_Cantidad' => 2]]]);
        self::assertSame(201, $e);
        self::assertSame([true, 2, null], [$kit['KIT_Activo'], $kit['items'][0]['KIT_Cantidad'], $kit['DEP_Departamento']]);
        self::assertSame([409, 'STANDARD_KIT_ALREADY_EXISTS'], $this->detalle('POST', '/api/v1/catalogos/kits', ['KIT_Nombre' => 'Otro', 'CAR_Cargo' => $cargo, 'items' => [['KIT_Cantidad' => 1]]]));
        self::assertSame([422, 'STANDARD_KIT_NEEDS_POSITION_OR_DEPARTMENT'], $this->detalle('POST', '/api/v1/catalogos/kits', ['KIT_Nombre' => 'Otro', 'items' => [['KIT_Cantidad' => 1]]]));
        self::assertSame([422, 'VALIDATION_ERROR'], $this->detalle('POST', '/api/v1/catalogos/kits', ['KIT_Nombre' => 'Otro', 'CAR_Cargo' => $cargo, 'items' => []]));
        [$e, $editado] = $this->pedir('PUT', "/api/v1/catalogos/kits/{$kit['KIT_Kit']}", ['KIT_Nombre' => 'Kit oficina 2', 'CAR_Cargo' => $cargo, 'items' => [['TAC_Tipo_Activo' => $tipo['TAC_Tipo_Activo'], 'KIT_Cantidad' => 3]]]);
        self::assertSame([200, 'Kit oficina 2', 3], [$e, $editado['KIT_Nombre'] ?? json_encode($editado), $editado['items'][0]['KIT_Cantidad'] ?? null]);
        self::assertSame(204, $this->pedir('DELETE', "/api/v1/catalogos/kits/{$kit['KIT_Kit']}")[0]);

        [, $tes] = $this->pedir('POST', '/api/v1/cat/tipos-especificacion', ['TES_Nombre' => 'Espec '.D::sufijo()]);
        $ruta = "/api/v1/catalogos/especificaciones-tipo/{$tipo['TAC_Tipo_Activo']}";
        self::assertSame([422, 'DUPLICATED_SPECIFICATION'], $this->detalle('PUT', $ruta, ['items' => [['TES_Tipo_Especificacion' => $tes['TES_Tipo_Especificacion']], ['TES_Tipo_Especificacion' => $tes['TES_Tipo_Especificacion']]]]));
        [$e, $lista] = $this->pedir('PUT', $ruta, ['items' => [['TES_Tipo_Especificacion' => $tes['TES_Tipo_Especificacion'], 'obligatoria' => true, 'valores' => [' A ', '', 'B']]]]);
        self::assertSame([200, true, ['A', 'B'], 10], [$e, $lista[0]['obligatoria'], $lista[0]['valores'], $lista[0]['orden']]);
        [, $todas] = $this->pedir('GET', '/api/v1/catalogos/especificaciones-tipo');
        self::assertSame($lista, $todas[(string) $tipo['TAC_Tipo_Activo']]);
    }

    // ---- apoyo ---------------------------------------------------------

    /** @param array{username: string} $u */
    private function token(array $u): string
    {
        return static::getContainer()->get(Tokens::class)->crearAcceso($u['username'], 'x');
    }

    /**
     * @param array<string, mixed>|null $cuerpo
     *
     * @return array{0: int, 1: mixed}
     */
    private function pedir(string $metodo, string $ruta, ?array $cuerpo = null, ?string $token = null): array
    {
        $this->cliente->request($metodo, $ruta, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.($token ?? $this->admin)],
            content: null === $cuerpo ? '' : json_encode($cuerpo));
        $r = $this->cliente->getResponse();

        return [$r->getStatusCode(), json_decode((string) $r->getContent(), true)];
    }

    /**
     * @param array<string, mixed>|null $cuerpo
     *
     * @return array{0: int, 1: mixed}
     */
    private function detalle(string $metodo, string $ruta, ?array $cuerpo = null, ?string $token = null): array
    {
        [$e, $json] = $this->pedir($metodo, $ruta, $cuerpo, $token);

        return [$e, $json['detail'] ?? $json];
    }
}
