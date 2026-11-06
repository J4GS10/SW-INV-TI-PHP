<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Db\Unicidad;
use App\Seguridad\Tokens;
use App\Tests\Soporte\DatosPrueba as D;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * /geo: permisos por módulo y por acción, alcance por sede, unicidad,
 * hijos, validación y auditoría, contra Oracle real.
 */
final class UbicacionTest extends WebTestCase
{
    private KernelBrowser $cliente;

    protected function setUp(): void
    {
        $this->cliente = static::createClient();
    }

    public function testPermisosDelModuloYDeEscritura(): void
    {
        $seguridad = $this->token(D::crearUsuario(['rol' => 'ADMIN_SEGURIDAD']));
        self::assertSame([403, 'NOT_ENOUGH_PERMISSIONS'], $this->pedir('GET', '/api/v1/geo/paises', $seguridad));

        $tecnico = $this->token(D::crearUsuario(['rol' => 'TECNICO', 'global' => true]));
        self::assertSame(200, $this->pedir('GET', '/api/v1/geo/paises', $tecnico)[0]);
        self::assertSame([403, 'NOT_ENOUGH_PERMISSIONS'], $this->pedir('POST', '/api/v1/geo/paises', $tecnico, ['PAI_Nombre' => 'X', 'PAI_Codigo_ISO' => 'XX']));
        self::assertSame(401, $this->pedir('GET', '/api/v1/geo/paises')[0]);
    }

    public function testCrudDePaisConUnicidadHijosYAuditoria(): void
    {
        $admin = D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]);
        $t = $this->token($admin);
        $nombre = 'País '.D::sufijo();
        $iso = strtoupper(substr(D::sufijo(), 0, 4));

        [$estado, $pais] = $this->pedir('POST', '/api/v1/geo/paises', $t, ['PAI_Nombre' => "  {$nombre} ", 'PAI_Codigo_ISO' => $iso], true);
        self::assertSame(201, $estado);
        self::assertSame(['PAI_Pais', 'PAI_Nombre', 'PAI_Codigo_ISO'], array_keys($pais));
        self::assertIsInt($pais['PAI_Pais']);
        self::assertSame($nombre, $pais['PAI_Nombre'], 'Los textos se recortan.');
        self::assertSame(1, $this->auditorias($admin['id'], 'CREATE', 'INV_PAIS'));

        // Unicidad insensible a mayúsculas: el índice da su código.
        self::assertSame([409, 'COUNTRY_ALREADY_EXISTS'],
            $this->pedir('POST', '/api/v1/geo/paises', $t, ['PAI_Nombre' => strtoupper($nombre), 'PAI_Codigo_ISO' => 'Q'.substr($iso, 0, 3)]));

        [$e, $actualizado] = $this->pedir('PATCH', "/api/v1/geo/paises/{$pais['PAI_Pais']}", $t, ['PAI_Nombre' => $nombre.' b'], true);
        self::assertSame([200, $nombre.' b', $iso], [$e, $actualizado['PAI_Nombre'], $actualizado['PAI_Codigo_ISO']]);

        [$e, $est] = $this->pedir('POST', '/api/v1/geo/estados', $t, ['EST_Nombre' => 'Estado X', 'PAI_Pais' => $pais['PAI_Pais']], true);
        self::assertSame(201, $e);
        self::assertSame([409, 'CANNOT_DELETE_COUNTRY_HAS_STATES'], $this->pedir('DELETE', "/api/v1/geo/paises/{$pais['PAI_Pais']}", $t));
        [, $lista] = $this->pedir('GET', "/api/v1/geo/estados?pais_id={$pais['PAI_Pais']}", $t, null, true);
        self::assertSame([$est], $lista);

        self::assertSame(204, $this->pedir('DELETE', "/api/v1/geo/estados/{$est['EST_Estado']}", $t)[0]);
        self::assertSame(204, $this->pedir('DELETE', "/api/v1/geo/paises/{$pais['PAI_Pais']}", $t)[0]);
        self::assertSame([404, 'COUNTRY_NOT_FOUND'], $this->pedir('GET', "/api/v1/geo/paises/{$pais['PAI_Pais']}", $t));
    }

    public function testValidacionDeEntradaYRuta(): void
    {
        $t = $this->token(D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]));
        [$e, $cuerpo] = $this->pedir('POST', '/api/v1/geo/paises', $t, ['PAI_Nombre' => 'X', 'PAI_Codigo_ISO' => 7], true);
        self::assertSame(422, $e);
        self::assertSame([['body', 'PAI_Nombre'], ['body', 'PAI_Codigo_ISO']], array_column($cuerpo['errors'], 'loc'));
        self::assertSame(['string_too_short', 'string_type'], array_column($cuerpo['errors'], 'type'));

        [$e, $cuerpo] = $this->pedir('GET', '/api/v1/geo/paises/abc', $t, null, true);
        self::assertSame([422, ['path', 'id']], [$e, $cuerpo['errors'][0]['loc']]);
        [$e, $cuerpo] = $this->pedir('GET', '/api/v1/geo/estados', $t, null, true);
        self::assertSame([422, ['query', 'pais_id'], 'missing'], [$e, $cuerpo['errors'][0]['loc'], $cuerpo['errors'][0]['type']]);
    }

    public function testAlcancePorSede(): void
    {
        $propia = D::crearSede('Propia '.D::sufijo());
        $ajena = D::crearSede('Ajena '.D::sufijo());
        $admin = $this->token(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$propia]]));
        $global = $this->token(D::crearUsuario(['rol' => 'ADMIN_TI', 'global' => true]));

        // Jerarquía compartida: solo alcance global.
        self::assertSame([403, 'GLOBAL_SCOPE_REQUIRED'], $this->pedir('POST', '/api/v1/geo/paises', $admin, ['PAI_Nombre' => 'Otro país', 'PAI_Codigo_ISO' => 'OP']));

        [$e, $edificio] = $this->pedir('POST', '/api/v1/geo/edificios', $admin, ['EDI_Nombre' => 'Torre A', 'SED_Sede' => $propia], true);
        self::assertSame(201, $e);
        self::assertSame([403, 'SEDE_OUT_OF_SCOPE'], $this->pedir('POST', '/api/v1/geo/edificios', $admin, ['EDI_Nombre' => 'Torre B', 'SED_Sede' => $ajena]));

        [, $ajeno] = $this->pedir('POST', '/api/v1/geo/edificios', $global, ['EDI_Nombre' => 'Torre C', 'SED_Sede' => $ajena], true);
        self::assertSame([404, 'BUILDING_NOT_FOUND'], $this->pedir('GET', "/api/v1/geo/edificios/{$ajeno['EDI_Edificio']}", $admin));
        self::assertSame([403, 'SEDE_OUT_OF_SCOPE'], $this->pedir('PATCH', "/api/v1/geo/edificios/{$ajeno['EDI_Edificio']}", $admin, ['EDI_Nombre' => 'X']));
        self::assertSame([200, []], $this->pedir('GET', "/api/v1/geo/edificios?sede_id={$ajena}", $admin, null, true));

        [, $nivel] = $this->pedir('POST', '/api/v1/geo/niveles', $admin, ['NIV_Numero_Piso' => '1', 'EDI_Edificio' => $edificio['EDI_Edificio']], true);
        [$e, $area] = $this->pedir('POST', '/api/v1/geo/areas', $admin, ['ARE_Nombre' => 'Bodega central', 'NIV_Nivel' => $nivel['NIV_Nivel']], true);
        self::assertSame([201, 'General'], [$e, $area['ARE_Tipo_Acceso']]);
        self::assertSame([422, 'CATALOG_ITEM_NOT_FOUND:tipos-area'],
            $this->pedir('PATCH', "/api/v1/geo/areas/{$area['ARE_Area']}", $admin, ['TAR_Tipo_Area' => 999999]));

        [, $todas] = $this->pedir('GET', '/api/v1/geo/areas/all', $admin, null, true);
        self::assertContains($area['ARE_Area'], array_column($todas, 'ARE_Area'));
        self::assertSame([$propia], array_values(array_unique(array_column($todas, 'SED_Sede'))), 'Solo áreas de sus sedes.');
        $mia = array_values(array_filter($todas, static fn (array $a): bool => $a['ARE_Area'] === $area['ARE_Area']))[0];
        self::assertStringEndsWith('› Torre A › Piso 1 › Bodega central', $mia['ruta']);

        self::assertSame([409, 'CANNOT_DELETE_LEVEL_HAS_AREAS'], $this->pedir('DELETE', "/api/v1/geo/niveles/{$nivel['NIV_Nivel']}", $admin));
    }

    public function testCadaIndiceDeUnicidadMapeadoExisteEnElEsquema(): void
    {
        $codigos = (new \ReflectionClassConstant(Unicidad::class, 'CODIGOS'))->getValue();
        $existentes = D::conexion()->fetchFirstColumn(
            'SELECT index_name FROM user_indexes UNION SELECT constraint_name FROM user_constraints',
        );
        $faltantes = array_diff(array_keys($codigos), $existentes);
        self::assertSame([], array_values($faltantes), 'Índices mapeados que no existen en el esquema.');
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
    private function pedir(string $metodo, string $ruta, ?string $token = null, ?array $cuerpo = null, bool $completo = false): array
    {
        $this->cliente->request($metodo, $ruta, server: array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => null === $token ? null : "Bearer {$token}",
        ]), content: null === $cuerpo ? '' : json_encode($cuerpo));
        $r = $this->cliente->getResponse();
        $json = json_decode((string) $r->getContent(), true);

        return [$r->getStatusCode(), $completo ? $json : ($json['detail'] ?? $json)];
    }

    private function auditorias(string $usuario, string $accion, string $entidad): int
    {
        return D::contar('SELECT COUNT(*) FROM "INV_AUDITORIA_SISTEMA" WHERE "USU_Usuario" = :u AND "AUD_Accion" = :a AND "AUD_Entidad_Afectada" = :e',
            ['u' => $usuario, 'a' => $accion, 'e' => $entidad]);
    }
}
