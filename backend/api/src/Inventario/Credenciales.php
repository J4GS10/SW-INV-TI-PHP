<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Db\Transaccion;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Http\ErrorApi;
use App\Repositorio\GobiernoRepositorio;
use App\Seguridad\CifradoCampos;
use Doctrine\DBAL\Connection;

/**
 * Credenciales y cupos de las licencias. El método de activación decide qué
 * pide cada credencial (usuario, secreto, serial, key, servidor, archivo,
 * dispositivo); secreto y key se guardan cifrados. Los listados nunca devuelven
 * secretos ni keys completas; «revelar» queda en la bitácora y en el kardex.
 */
final readonly class Credenciales
{
    public const array CAMPOS = ['usuario', 'secreto', 'serial', 'clave', 'servidor', 'archivo', 'dispositivo'];
    private const array COLUMNA = ['usuario' => 'LCL_Usuario', 'secreto' => 'LCL_Secreto_Enc', 'serial' => 'LCL_Serial', 'clave' => 'LCL_Clave_Activacion',
        'servidor' => 'LCL_Servidor', 'archivo' => 'LCL_Archivo', 'dispositivo' => 'LCL_Dispositivo'];
    private const array CIFRADOS = ['secreto', 'clave'];
    private const int ROTACION_DIAS = 180;

    public function __construct(
        private Connection $conexion,
        private Kardex $kardex,
        private GobiernoRepositorio $gobierno,
        private CifradoCampos $cifrado,
        private Transaccion $transaccion,
    ) {
    }

    public function mascara(?string $cifrada): ?string
    {
        if (null === $cifrada || '' === $cifrada) {
            return null;
        }
        $clave = (string) $this->cifrado->descifrar($cifrada);

        return '' === $clave ? null : '•••• '.mb_substr($clave, -5);
    }

    /** @return array<string, mixed> licencia con su método y forma */
    private function licencia(int $id): array
    {
        $f = $this->conexion->fetchAssociative('SELECT l."LIC_Licencia", l."LIC_Cantidad_Total", l."LIC_Cantidad_Usada", l."LIC_Clave_Activacion", '
            .'l."MAC_Metodo_Activacion", fc."FCO_Codigo", m.* FROM "INV_LICENCIA" l LEFT JOIN "INV_FORMA_CONTEO" fc ON fc."FCO_Forma" = l."FCO_Forma_Conteo" '
            .'LEFT JOIN "INV_METODO_ACTIVACION" m ON m."MAC_Metodo" = l."MAC_Metodo_Activacion" WHERE l."LIC_Licencia" = :id', ['id' => $id]);

        return false === $f ? throw new ErrorApi(404, 'LICENSE_NOT_FOUND') : $f;
    }

    /** @return array<string, mixed> */
    private function credencial(int $id, bool $bloquear = false): array
    {
        $f = $this->conexion->fetchAssociative('SELECT * FROM "INV_LICENCIA_CLAVE" WHERE "LCL_Licencia_Clave" = :id'.($bloquear ? ' FOR UPDATE' : ''), ['id' => $id]);

        return false === $f ? throw new ErrorApi(404, 'LICENSE_KEY_NOT_FOUND') : $f;
    }

    /**
     * Campos que pide el método. Sin método (licencias anteriores): la product key.
     *
     * @param array<string, mixed> $lic
     *
     * @return list<string>
     */
    private static function pedidos(array $lic): array
    {
        if (null === $lic['MAC_Metodo_Activacion']) {
            return ['clave'];
        }

        return array_values(array_filter(self::CAMPOS, static fn (string $c): bool => 1 === (int) ($lic['MAC_Pide_'.ucfirst($c)] ?? 0)));
    }

    private static function texto(mixed $v): ?string
    {
        if (null === $v) {
            return null;
        }
        $t = trim((string) $v);

        return '' === $t ? null : $t;
    }

    /**
     * Exige exactamente los campos del método y rechaza los demás (`parcial`: solo los presentes).
     *
     * @param array<string, mixed> $lic
     * @param array<string, mixed> $datos
     *
     * @return array<string, ?string>
     */
    private static function validar(array $lic, array $datos, bool $parcial = false): array
    {
        $pedidos = self::pedidos($lic);
        $limpios = [];
        foreach (self::CAMPOS as $c) {
            if (\array_key_exists($c, $datos)) {
                $limpios[$c] = self::texto($datos[$c]);
            }
        }
        if ([] === $pedidos && !$parcial) {
            throw new ErrorApi(422, 'LICENSE_METHOD_HAS_NO_CREDENTIALS');
        }
        foreach ($limpios as $c => $v) {
            if (null !== $v && !\in_array($c, $pedidos, true)) {
                throw new ErrorApi(422, "LICENSE_FIELD_NOT_ALLOWED:{$c}");
            }
        }
        foreach ($pedidos as $c) {
            if ($parcial && !\array_key_exists($c, $limpios)) {
                continue;
            }
            if (null === ($limpios[$c] ?? null)) {
                throw new ErrorApi(422, "LICENSE_FIELD_REQUIRED:{$c}");
            }
        }
        if (null !== ($limpios['archivo'] ?? null)) {
            $limpios['archivo'] = Ident::aHex($limpios['archivo']);
        }

        return $limpios;
    }

    /**
     * Huella única sin revelar el valor: key y serial, únicos en la empresa;
     * cuenta y servidor, dentro de su licencia. También la usa
     * App\Esquema\RecalculoHuellas tras rotar HMAC_KEYS.
     *
     * @param array<string, ?string> $d
     */
    public function huella(int $licencia, array $d): ?string
    {
        return match (true) {
            null !== ($d['clave'] ?? null) => $this->cifrado->huella($d['clave']),
            null !== ($d['serial'] ?? null) => $this->cifrado->huella('serial:'.mb_strtoupper(trim($d['serial']))),
            null !== ($d['dispositivo'] ?? null) => $this->cifrado->huella('dongle:'.mb_strtoupper(trim($d['dispositivo']))),
            null !== ($d['usuario'] ?? null) => $this->cifrado->huella("cuenta:{$licencia}:".mb_strtolower(trim($d['usuario']))),
            null !== ($d['servidor'] ?? null) => $this->cifrado->huella("servidor:{$licencia}:".mb_strtolower(trim($d['servidor']))),
            null !== ($d['secreto'] ?? null) => $this->cifrado->huella("token:{$licencia}:".$d['secreto']),
            default => null,
        };
    }

    /**
     * @param array<string, ?string> $limpios
     *
     * @return array<string, ?string>
     */
    private function columnas(array $limpios): array
    {
        $cols = [];
        foreach ($limpios as $c => $v) {
            $cols[self::COLUMNA[$c]] = \in_array($c, self::CIFRADOS, true) && null !== $v ? $this->cifrado->cifrar($v) : $v;
        }

        return $cols;
    }

    private function referencia(int $licencia): string
    {
        $sw = $this->conexion->fetchAssociative('SELECT s."SOF_Nombre", s."SOF_Version" FROM "INV_SOFTWARE" s JOIN "INV_LICENCIA" l ON l."SOF_Software" = s."SOF_Software" '
            .'WHERE l."LIC_Licencia" = :l', ['l' => $licencia]);
        $nombre = false === $sw ? 'Licencia' : implode(' ', array_filter([$sw['SOF_Nombre'], $sw['SOF_Version']]));

        return mb_substr("{$nombre} · licencia #{$licencia}", 0, 200);
    }

    /**
     * Alta de una o varias credenciales (p. ej. pegadas desde una lista).
     *
     * @param list<array<string, mixed>> $lote
     *
     * @return list<int> ids creados
     */
    public function crear(int $licencia, array $lote, ?string $usuario, ?string $ip, bool $registrarKardex = true): array
    {
        return $this->transaccion->ejecutar(function () use ($licencia, $lote, $usuario, $ip, $registrarKardex): array {
            $lic = $this->licencia($licencia);
            $limpios = array_map(static fn (array $c): array => self::validar($lic, $c), $lote);
            $huellas = array_map(fn (array $c): ?string => $this->huella($licencia, $c), $limpios);
            $reales = array_values(array_filter($huellas));
            if (\count($reales) !== \count(array_unique($reales))) {
                throw new ErrorApi(400, 'DUPLICATE_LICENSE_KEY_IN_REQUEST');
            }
            if ([] !== $reales) {
                $p = [];
                foreach ($reales as $i => $h) {
                    $p["h{$i}"] = $h;
                }
                if (false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_LICENCIA_CLAVE" WHERE "LCL_Clave_Hash" IN (:'.implode(', :', array_keys($p)).') FETCH FIRST 1 ROWS ONLY', $p)) {
                    throw new ErrorApi(409, 'LICENSE_KEY_ALREADY_EXISTS');
                }
            }
            // Las credenciales son cupos: no más que los comprados (salvo por sitio).
            $existentes = (int) $this->conexion->fetchOne("SELECT COUNT(*) FROM \"INV_LICENCIA_CLAVE\" WHERE \"LIC_Licencia\" = :l AND \"LCL_Estado\" <> 'BAJA'", ['l' => $licencia]);
            if ('SITIO' !== $lic['FCO_Codigo'] && $existentes + \count($lote) > (int) $lic['LIC_Cantidad_Total']) {
                throw new ErrorApi(409, 'LICENSE_CREDENTIALS_EXCEED_SEATS:'.(int) $lic['LIC_Cantidad_Total']);
            }
            $ahora = Reloj::aBd(Reloj::ahora());
            $ids = [];
            $usuarios = [];
            foreach ($limpios as $i => $d) {
                if (null !== ($d['archivo'] ?? null)) {
                    $adj = $this->conexion->fetchOne('SELECT "LIC_Licencia" FROM "INV_ADJUNTO" WHERE "ADJ_Adjunto" = :a', ['a' => $d['archivo']]);
                    if (false === $adj || (int) $adj !== $licencia) {
                        throw new ErrorApi(422, 'LICENSE_FILE_NOT_FOUND');
                    }
                }
                $cols = ['LIC_Licencia' => $licencia, 'LCL_Clave_Hash' => $huellas[$i], 'LCL_Estado' => 'DISPONIBLE', 'LCL_Requiere_Cambio' => 0,
                    'LCL_Referencia' => self::texto($lote[$i]['referencia'] ?? null),
                    'LCL_Ultimo_Cambio_Secreto' => null !== ($d['secreto'] ?? null) ? $ahora : null, ...$this->columnas($d)];
                $ids[] = (int) \App\Db\InsercionOracle::insertar($this->conexion, 'INV_LICENCIA_CLAVE', $cols, 'LCL_Licencia_Clave');
                if (null !== ($lote[$i]['vence'] ?? null)) {
                    $this->conexion->executeStatement('UPDATE "INV_LICENCIA_CLAVE" SET "LCL_Vence" = TO_DATE(:v, \'YYYY-MM-DD\') WHERE "LCL_Licencia_Clave" = :id',
                        ['v' => $lote[$i]['vence'], 'id' => end($ids)]);
                }
                if (null !== ($d['usuario'] ?? null)) {
                    $usuarios[] = $d['usuario'];
                }
            }
            if ($registrarKardex) {
                $this->kardex->registrar('LICENCIA_CREDENCIAL_ALTA', licencia: $licencia, referencia: $this->referencia($licencia), cantidad: \count($ids),
                    usuario: $usuario, detalle: ['credenciales' => $ids]);
            }
            // Solo datos visibles en la bitácora: nunca secretos ni keys.
            $this->gobierno->registrarAuditoria('CREATE', 'INV_LICENCIA_CLAVE', ['licencia' => $licencia, 'cantidad' => \count($ids),
                'usuarios' => \array_slice($usuarios, 0, 50)], $usuario, $ip);

            return $ids;
        });
    }

    /**
     * Listado enmascarado con el destino actual de cada credencial.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(int $licencia): array
    {
        $lic = $this->licencia($licencia);
        $filas = $this->conexion->fetchAllAssociative('SELECT c.*, i."INS_Fecha_Instalacion", i."ACT_Activo" AS "I_ACT", i."PER_Persona" AS "I_PER", '
            .'a."ACT_Codigo_Interno", p."PER_Primer_Nombre", p."PER_Primer_Apellido", p."PER_Email_Corporativo", ad."ADJ_Nombre_Original" '
            .'FROM "INV_LICENCIA_CLAVE" c LEFT JOIN "INV_INSTALACION" i ON i."LCL_Licencia_Clave" = c."LCL_Licencia_Clave" AND i."INS_Estado" = 1 '
            .'LEFT JOIN "INV_ACTIVO" a ON a."ACT_Activo" = i."ACT_Activo" LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = i."PER_Persona" '
            .'LEFT JOIN "INV_ADJUNTO" ad ON ad."ADJ_Adjunto" = c."LCL_Archivo" WHERE c."LIC_Licencia" = :l ORDER BY c."LCL_Licencia_Clave"', ['l' => $licencia]);
        $hoy = Reloj::ahora();
        $salida = [];
        foreach ($filas as $c) {
            $destino = null;
            if (null !== $c['I_ACT'] && null !== $c['ACT_Codigo_Interno']) {
                $destino = ['tipo' => 'ACTIVO', 'id' => Ident::aTexto((string) $c['I_ACT']), 'nombre' => $c['ACT_Codigo_Interno']];
            } elseif (null !== $c['I_PER'] && null !== $c['PER_Primer_Nombre']) {
                $destino = ['tipo' => 'PERSONA', 'id' => Ident::aTexto((string) $c['I_PER']),
                    'nombre' => implode(' ', array_filter([$c['PER_Primer_Nombre'], $c['PER_Primer_Apellido']])), 'detalle' => $c['PER_Email_Corporativo']];
            }
            $cambio = Reloj::desdeBd($c['LCL_Ultimo_Cambio_Secreto'] ?? null) ?? Reloj::desdeBd($c['CREATED_AT']);
            $tieneSecreto = null !== $c['LCL_Secreto_Enc'] && '' !== $c['LCL_Secreto_Enc'];
            $salida[] = [
                'LCL_Licencia_Clave' => (int) $c['LCL_Licencia_Clave'], 'LIC_Licencia' => (int) $c['LIC_Licencia'], 'referencia' => $c['LCL_Referencia'],
                'usuario' => $c['LCL_Usuario'], 'serial' => $c['LCL_Serial'], 'servidor' => $c['LCL_Servidor'], 'dispositivo' => $c['LCL_Dispositivo'],
                'archivo' => null === $c['LCL_Archivo'] ? null : ['id' => Ident::aTexto((string) $c['LCL_Archivo']), 'nombre' => $c['ADJ_Nombre_Original']],
                'clave_mascara' => $this->mascara($c['LCL_Clave_Activacion']), 'tiene_secreto' => $tieneSecreto,
                'tiene_clave' => null !== $c['LCL_Clave_Activacion'] && '' !== $c['LCL_Clave_Activacion'],
                'vence' => null === $c['LCL_Vence'] ? null : substr((string) $c['LCL_Vence'], 0, 10), 'estado' => $c['LCL_Estado'],
                'creado' => Reloj::iso(Reloj::desdeBd($c['CREATED_AT'])), 'ultimo_cambio_secreto' => Reloj::iso(Reloj::desdeBd($c['LCL_Ultimo_Cambio_Secreto'])),
                'requiere_cambio' => 1 === (int) $c['LCL_Requiere_Cambio'],
                'sin_rotar' => $tieneSecreto && null !== $cambio && (int) $cambio->diff($hoy)->days > self::ROTACION_DIAS && $cambio < $hoy,
                'asignada_en' => null === $c['INS_Fecha_Instalacion'] ? null : substr((string) $c['INS_Fecha_Instalacion'], 0, 10),
                'destino' => $destino,
            ];
        }
        // Licencia anterior con una sola key en la cabecera (sin credenciales).
        if ([] === $salida && null !== $lic['LIC_Clave_Activacion'] && '' !== $lic['LIC_Clave_Activacion']) {
            $salida[] = ['LCL_Licencia_Clave' => -$licencia, 'LIC_Licencia' => $licencia, 'referencia' => null, 'usuario' => null, 'serial' => null,
                'servidor' => null, 'dispositivo' => null, 'archivo' => null, 'clave_mascara' => $this->mascara($lic['LIC_Clave_Activacion']),
                'tiene_secreto' => false, 'tiene_clave' => true, 'vence' => null, 'estado' => 0 !== (int) $lic['LIC_Cantidad_Usada'] ? 'ASIGNADA' : 'DISPONIBLE',
                'creado' => null, 'ultimo_cambio_secreto' => null, 'requiere_cambio' => false, 'sin_rotar' => false, 'asignada_en' => null, 'destino' => null,
                'legada' => true];
        }

        return $salida;
    }

    /**
     * Datos visibles (secreto y key se cambian con «rotar»).
     *
     * @param array<string, mixed> $datos
     */
    public function editar(int $id, array $datos, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $datos, $usuario, $ip): void {
            $c = $this->credencial($id, true);
            $lic = $this->licencia((int) $c['LIC_Licencia']);
            $visibles = array_intersect_key($datos, array_flip(['usuario', 'serial', 'servidor', 'dispositivo']));
            $limpios = self::validar($lic, $visibles, true);
            $cambios = $this->columnas($limpios);
            if (\array_key_exists('referencia', $datos)) {
                $cambios['LCL_Referencia'] = self::texto($datos['referencia']);
            }
            $vence = \array_key_exists('vence', $datos);
            if (null !== ($datos['estado'] ?? null) && $datos['estado'] !== $c['LCL_Estado']) {
                if ('ASIGNADA' === $c['LCL_Estado']) {
                    throw new ErrorApi(409, 'LICENSE_CREDENTIAL_IN_USE');
                }
                $cambios['LCL_Estado'] = $datos['estado'];
            }
            if ([] !== $limpios) {
                $actual = ['usuario' => $c['LCL_Usuario'], 'serial' => $c['LCL_Serial'], 'servidor' => $c['LCL_Servidor'], 'dispositivo' => $c['LCL_Dispositivo'],
                    ...$limpios, 'clave' => null === $c['LCL_Clave_Activacion'] ? null : $this->cifrado->descifrar($c['LCL_Clave_Activacion'])];
                $nueva = $this->huella((int) $c['LIC_Licencia'], $actual);
                if ($nueva !== $c['LCL_Clave_Hash'] && null !== $nueva && false !== $this->conexion->fetchOne(
                    'SELECT 1 FROM "INV_LICENCIA_CLAVE" WHERE "LCL_Clave_Hash" = :h FETCH FIRST 1 ROWS ONLY', ['h' => $nueva])) {
                    throw new ErrorApi(409, 'LICENSE_KEY_ALREADY_EXISTS');
                }
                $cambios['LCL_Clave_Hash'] = $nueva;
            }
            $sets = [];
            $p = ['id' => $id];
            foreach ($cambios as $col => $v) {
                $sets[] = "\"{$col}\" = :{$col}";
                $p[$col] = $v;
            }
            if ($vence) {
                $sets[] = "\"LCL_Vence\" = TO_DATE(:vence, 'YYYY-MM-DD')";
                $p['vence'] = $datos['vence'];
            }
            if ([] !== $sets) {
                $this->conexion->executeStatement('UPDATE "INV_LICENCIA_CLAVE" SET '.implode(', ', $sets).' WHERE "LCL_Licencia_Clave" = :id', $p);
            }
            $nombres = array_values(array_filter(array_keys($cambios), static fn (string $k): bool => 'LCL_Clave_Hash' !== $k));
            if ($vence) {
                $nombres[] = 'LCL_Vence';
            }
            sort($nombres);
            $this->gobierno->registrarAuditoria('UPDATE', 'INV_LICENCIA_CLAVE', ['id' => $id, 'cambios' => $nombres], $usuario, $ip);
        });
    }

    /** @return array{secreto: ?string, clave: ?string, ocultar_en_segundos: int} */
    public function revelar(int $id, string $usuario, ?string $ip): array
    {
        return $this->transaccion->ejecutar(function () use ($id, $usuario, $ip): array {
            $c = $this->credencial($id);
            $secreto = $c['LCL_Secreto_Enc'];
            $clave = $c['LCL_Clave_Activacion'];
            if ((null === $secreto || '' === $secreto) && (null === $clave || '' === $clave)) {
                throw new ErrorApi(404, 'LICENSE_CREDENTIAL_HAS_NO_SECRET');
            }
            $this->gobierno->registrarAuditoria('LICENSE_SECRET_VIEWED', 'INV_LICENCIA_CLAVE', ['credencial' => $id, 'licencia' => (int) $c['LIC_Licencia'],
                'usuario_cuenta' => $c['LCL_Usuario']], $usuario, $ip);
            $this->kardex->registrar('LICENCIA_SECRETO_VISTO', licencia: (int) $c['LIC_Licencia'], clave: $id, referencia: $this->referencia((int) $c['LIC_Licencia']),
                usuario: $usuario);

            return ['secreto' => null === $secreto || '' === $secreto ? null : $this->cifrado->descifrar($secreto),
                'clave' => null === $clave || '' === $clave ? null : $this->cifrado->descifrar($clave), 'ocultar_en_segundos' => 30];
        });
    }

    /** @param array{secreto?: ?string, clave?: ?string} $datos */
    public function rotar(int $id, array $datos, string $usuario, ?string $ip): void
    {
        $this->transaccion->ejecutar(function () use ($id, $datos, $usuario, $ip): void {
            $c = $this->credencial($id, true);
            $pedidos =self::pedidos($this->licencia((int) $c['LIC_Licencia']));
            $nuevos = [];
            foreach (self::CIFRADOS as $campo) {
                $v = self::texto($datos[$campo] ?? null);
                if (null !== $v) {
                    $nuevos[$campo] = $v;
                }
            }
            if ([] === $nuevos) {
                throw new ErrorApi(422, 'LICENSE_ROTATION_EMPTY');
            }
            foreach (array_keys($nuevos) as $campo) {
                if (!\in_array($campo, $pedidos, true)) {
                    throw new ErrorApi(422, "LICENSE_FIELD_NOT_ALLOWED:{$campo}");
                }
            }
            $cambios = $this->columnas($nuevos);
            if (isset($nuevos['clave'])) {
                $h = $this->cifrado->huella($nuevos['clave']);
                if (false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_LICENCIA_CLAVE" WHERE "LCL_Clave_Hash" = :h AND "LCL_Licencia_Clave" <> :id '
                    .'FETCH FIRST 1 ROWS ONLY', ['h' => $h, 'id' => $id])) {
                    throw new ErrorApi(409, 'LICENSE_KEY_ALREADY_EXISTS');
                }
                $cambios['LCL_Clave_Hash'] = $h;
            }
            $sets = ['"LCL_Ultimo_Cambio_Secreto" = :ahora', '"LCL_Requiere_Cambio" = 0'];
            $p = ['id' => $id, 'ahora' => Reloj::aBd(Reloj::ahora())];
            foreach ($cambios as $col => $v) {
                $sets[] = "\"{$col}\" = :{$col}";
                $p[$col] = $v;
            }
            $this->conexion->executeStatement('UPDATE "INV_LICENCIA_CLAVE" SET '.implode(', ', $sets).' WHERE "LCL_Licencia_Clave" = :id', $p);
            $campos = array_keys($nuevos);
            sort($campos);
            $this->kardex->registrar('LICENCIA_SECRETO_CAMBIADO', licencia: (int) $c['LIC_Licencia'], clave: $id, referencia: $this->referencia((int) $c['LIC_Licencia']),
                usuario: $usuario, detalle: ['campos' => $campos]);
            $this->gobierno->registrarAuditoria('LICENSE_SECRET_ROTATED', 'INV_LICENCIA_CLAVE', ['credencial' => $id, 'licencia' => (int) $c['LIC_Licencia'],
                'campos' => $campos], $usuario, $ip);
        });
    }

    /**
     * Cuentas que usaba una persona que salió: su clave debe cambiarse.
     *
     * @param list<int> $ids
     */
    public function marcarCambioTrasSalida(array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }
        $p = [];
        foreach ($ids as $i => $id) {
            $p["c{$i}"] = $id;
        }

        return $this->conexion->executeStatement('UPDATE "INV_LICENCIA_CLAVE" SET "LCL_Requiere_Cambio" = 1 WHERE "LCL_Licencia_Clave" IN (:'
            .implode(', :', array_keys($p)).') AND "LCL_Secreto_Enc" IS NOT NULL', $p);
    }
}
