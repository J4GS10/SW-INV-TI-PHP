<?php

declare(strict_types=1);

namespace App\Tests\Integracion;

use App\Tests\Soporte\DatosPrueba as D;
use App\Tests\Soporte\Inventario as I;
use App\Tests\Soporte\PruebaApi;

/**
 * Rol «Escáner» y código de operador: la cuenta del escáner ve, pero solo cambia algo con el código de un
 * técnico; el cambio queda a nombre del técnico y con la cuenta desde la que se hizo.
 */
final class CodigoOperadorTest extends PruebaApi
{
    private const string CODIGO = '482916';

    private int $sede;
    private int $area;
    private int $tipo;
    private int $modelo;
    private string $persona;
    /** @var array{id: string, username: string, persona: string, correo: string} */
    private array $tecnico;
    private string $tokenTecnico;

    protected function setUp(): void
    {
        parent::setUp();
        I::estados();
        if (0 === D::contar('SELECT COUNT(*) FROM "INV_TIPO_MOVIMIENTO" WHERE "TMO_Nombre" = :n', ['n' => 'Asignación'])) {
            D::conexion()->executeStatement('INSERT INTO "INV_TIPO_MOVIMIENTO" ("TMO_Nombre") VALUES (:n)', ['n' => 'Asignación']);
        }
        $this->sede = D::crearSede('Sede E '.D::sufijo());
        $this->area = I::area($this->sede);
        $this->tipo = I::tipo('COMPUTO');
        $this->modelo = I::modelo($this->tipo);
        $this->persona = D::crearPersona($this->sede, 'Ana');
        $this->token = $this->tokenDe(D::crearUsuario(['rol' => 'ADMIN_TI', 'sedes' => [$this->sede]]));
        $this->tecnico = D::crearUsuario(['rol' => 'TECNICO', 'sedes' => [$this->sede]]);
        $this->tokenTecnico = $this->tokenDe($this->tecnico);
    }

    private function activo(): string
    {
        return $this->ok(201, 'POST', '/api/v1/core/activos', ['ACT_Serie_Fabricante' => 'ES-'.D::sufijo(), 'ACT_Fecha_Compra' => '2026-01-01',
            'MOD_Modelo' => $this->modelo, 'TAC_Tipo_Activo' => $this->tipo, 'EOP_Estado_Operativo' => I::estado('En Bodega'),
            'SED_Sede' => $this->sede])['ACT_Activo'];
    }

    /** @return array<string, mixed> */
    private function asignacion(string $activo): array
    {
        $persona = strtolower(substr($this->persona, 0, 8).'-'.substr($this->persona, 8, 4).'-'.substr($this->persona, 12, 4).'-'
            .substr($this->persona, 16, 4).'-'.substr($this->persona, 20));
        $tipo = (int) D::conexion()->fetchOne('SELECT MIN("TMO_Tipo_Movimiento") FROM "INV_TIPO_MOVIMIENTO" WHERE "TMO_Nombre" = :n', ['n' => 'Asignación']);

        return ['ACT_Activo' => $activo, 'PER_Persona' => $persona, 'ARE_Area' => $this->area, 'TMO_Tipo_Movimiento' => $tipo, 'MOV_Motivo' => 'Desde el escáner'];
    }

    /** @return array<string, string> */
    private function codigo(string $usuario, string $codigo): array
    {
        return ['X-Operator-User' => $usuario, 'X-Operator-Code' => $codigo];
    }

    public function testCadaTecnicoDefineSuCodigoConSuContrasena(): void
    {
        $definir = fn (array $cuerpo, ?string $token = null): array => $this->detalle('PUT', '/api/v1/me/operator-code', $cuerpo, $token ?? $this->tokenTecnico);
        self::assertFalse($this->ok(200, 'GET', '/api/v1/me', token: $this->tokenTecnico)['operator_code']['configurado']);
        self::assertSame([400, 'INCORRECT_CURRENT_PASSWORD'], $definir(['code' => self::CODIGO, 'current_password' => 'otra']));
        self::assertSame([422, 'OPERATOR_CODE_TOO_WEAK'], $definir(['code' => '123456', 'current_password' => D::PASSWORD]));
        self::assertSame([422, 'OPERATOR_CODE_FORMAT'], $definir(['code' => '48291', 'current_password' => D::PASSWORD]));
        self::assertSame(204, $this->pedir('PUT', '/api/v1/me/operator-code', ['code' => self::CODIGO, 'current_password' => D::PASSWORD], $this->tokenTecnico)[0]);
        $estado = $this->ok(200, 'GET', '/api/v1/me', token: $this->tokenTecnico)['operator_code'];
        self::assertSame([true, true, null], [$estado['puede_tener'], $estado['configurado'], $estado['bloqueado_hasta']]);
        $hash = (string) D::conexion()->fetchOne('SELECT "USU_Codigo_Operador_Hash" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $this->tecnico['id']]);
        self::assertStringStartsWith('$argon2id$', $hash, 'Solo el hash, nunca el código.');

        // Consulta y el propio escáner no tienen código.
        foreach (['CONSULTA', 'ESCANER'] as $rol) {
            $otro = $this->tokenDe(D::crearUsuario(['rol' => $rol, 'sedes' => [$this->sede]]));
            self::assertSame([403, 'NOT_ENOUGH_PERMISSIONS'], $definir(['code' => self::CODIGO, 'current_password' => D::PASSWORD], $otro), $rol);
            self::assertFalse($this->ok(200, 'GET', '/api/v1/me', token: $otro)['operator_code']['puede_tener'], $rol);
        }
    }

    public function testElEscanerSoloCambiaAlgoConElCodigoYQuedaANombreDelTecnico(): void
    {
        $this->pedir('PUT', '/api/v1/me/operator-code', ['code' => self::CODIGO, 'current_password' => D::PASSWORD], $this->tokenTecnico);
        $escaner = D::crearUsuario(['rol' => 'ESCANER', 'sedes' => [$this->sede]]);
        $tokenEscaner = $this->tokenDe($escaner);
        $a = $this->activo();

        // Ve el equipo, quién lo tiene y su software, sin costos.
        $ficha = $this->ok(200, 'GET', "/api/v1/core/activos/{$a}", token: $tokenEscaner);
        self::assertArrayNotHasKey('ACT_Costo', array_filter($ficha, static fn (mixed $v): bool => null !== $v));
        self::assertSame(200, $this->pedir('GET', "/api/v1/soft/activos/{$a}/instalaciones", token: $tokenEscaner)[0]);

        // Sin código no cambia nada; un código mal escrito o de un usuario que no existe, la misma respuesta.
        self::assertSame([403, 'OPERATOR_CODE_REQUIRED'], $this->detalle('POST', '/api/v1/trazabilidad/movimientos', $this->asignacion($a), $tokenEscaner));
        foreach ([[$this->tecnico['username'], '730415'], ['nadie_'.D::sufijo(), self::CODIGO]] as [$usuario, $codigo]) {
            [$e, $r] = $this->pedir('POST', '/api/v1/trazabilidad/movimientos', $this->asignacion($a), $tokenEscaner, $this->codigo($usuario, $codigo));
            self::assertSame([403, 'OPERATOR_CODE_INVALID'], [$e, $r['detail'] ?? null]);
        }

        [$e, $mov] = $this->pedir('POST', '/api/v1/trazabilidad/movimientos', $this->asignacion($a), $tokenEscaner, $this->codigo($this->tecnico['username'], self::CODIGO));
        self::assertSame(201, $e, (string) json_encode($mov, \JSON_UNESCAPED_UNICODE));
        $evento = D::conexion()->fetchAssociative('SELECT "USU_Usuario", "KDX_Detalle" FROM "INV_KARDEX" WHERE "ACT_Activo" = :a AND "KDX_Tipo" = \'ASIGNACION\'',
            ['a' => str_replace('-', '', $a)]);
        self::assertIsArray($evento);
        self::assertSame($this->tecnico['id'], $evento['USU_Usuario'], 'El kardex queda a nombre de quien autorizó.');
        self::assertSame($escaner['username'], json_decode((string) $evento['KDX_Detalle'], true)['cuenta'] ?? null, 'Y dice desde qué cuenta.');
        self::assertSame(1, D::contar('SELECT COUNT(*) FROM "INV_AUDITORIA_SISTEMA" WHERE "USU_Usuario" = :u AND DBMS_LOB.INSTR("AUD_Snapshot_JSON", :c) > 0',
            ['u' => $this->tecnico['id'], 'c' => '"desde_cuenta":"'.$escaner['username'].'"']));
        self::assertTrue(I::cadenaKardexIntegra());

        // El técnico, en su propia sesión y sin el escáner, opera como siempre.
        $b = $this->activo();
        self::assertSame(201, $this->pedir('POST', '/api/v1/trazabilidad/movimientos', $this->asignacion($b), $this->tokenTecnico)[0]);
    }

    public function testCincoIntentosFallidosBloqueanElCodigoYElAdministradorLoRestablece(): void
    {
        $this->pedir('PUT', '/api/v1/me/operator-code', ['code' => self::CODIGO, 'current_password' => D::PASSWORD], $this->tokenTecnico);
        $tokenEscaner = $this->tokenDe(D::crearUsuario(['rol' => 'ESCANER', 'sedes' => [$this->sede]]));
        $a = $this->activo();
        $intento = fn (string $codigo): array => $this->pedir('POST', '/api/v1/trazabilidad/movimientos', $this->asignacion($a), $tokenEscaner,
            $this->codigo($this->tecnico['username'], $codigo));
        for ($i = 1; $i <= 4; ++$i) {
            self::assertSame('OPERATOR_CODE_INVALID', $intento('730415')[1]['detail'] ?? null, "intento {$i}");
        }
        self::assertSame('OPERATOR_CODE_LOCKED', $intento('730415')[1]['detail'] ?? null, 'El quinto bloquea.');
        self::assertSame('OPERATOR_CODE_LOCKED', $intento(self::CODIGO)[1]['detail'] ?? null, 'Bloqueado, ni el correcto entra.');
        self::assertNotNull($this->ok(200, 'GET', '/api/v1/me', token: $this->tokenTecnico)['operator_code']['bloqueado_hasta']);
        self::assertSame(5, D::contar('SELECT COUNT(*) FROM "INV_AUDITORIA_SISTEMA" WHERE "AUD_Accion" = \'OPERATOR_CODE_FAILED\' '
            .'AND DBMS_LOB.INSTR("AUD_Snapshot_JSON", :o) > 0', ['o' => '"operador":"'.$this->tecnico['username'].'"']));

        // Solo el administrador del sistema lo restablece; después, su dueño define uno nuevo.
        $admin = $this->tokenDe(D::crearUsuario(['rol' => 'SUPER_ADMIN', 'global' => true]));
        $ruta = '/api/v1/org/usuarios/'.$this->tecnico['id'].'/operator-code/reset';
        self::assertSame([403, 'NOT_ENOUGH_PERMISSIONS'], $this->detalle('POST', $ruta));
        self::assertSame(200, $this->pedir('POST', $ruta, token: $admin)[0]);
        self::assertFalse($this->ok(200, 'GET', '/api/v1/me', token: $this->tokenTecnico)['operator_code']['configurado']);
        self::assertSame('OPERATOR_CODE_INVALID', $intento(self::CODIGO)[1]['detail'] ?? null);
    }
}
