<?php

declare(strict_types=1);

namespace App\Repositorio;

use App\Config\Ajustes;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use Doctrine\DBAL\Connection;

/**
 * Sesiones de inicio (SYS_SESION). Cada login crea una fila; su id viaja en
 * el claim `sid` de los tokens, así una sesión se cierra sin afectar a las demás.
 */
final readonly class SesionRepositorio
{
    public function __construct(private Connection $conexion, private Ajustes $ajustes)
    {
    }

    /** Huella legible y estable del dispositivo: "Navegador · Sistema". */
    public static function etiquetaDispositivo(?string $userAgent): string
    {
        $ua = $userAgent ?? '';
        $navegador = match (true) {
            1 === preg_match('/Edg\//', $ua) => 'Edge',
            1 === preg_match('/OPR\/|Opera/', $ua) => 'Opera',
            1 === preg_match('/Firefox\//', $ua) => 'Firefox',
            1 === preg_match('/Chrome\/|CriOS\//', $ua) => 'Chrome',
            1 === preg_match('/Safari\//', $ua) => 'Safari',
            '' !== $ua => 'Otro',
            default => 'Desconocido',
        };
        $sistema = match (true) {
            1 === preg_match('/Android/', $ua) => 'Android',
            1 === preg_match('/iPhone|iPad|iOS/', $ua) => 'iOS',
            1 === preg_match('/Windows/', $ua) => 'Windows',
            1 === preg_match('/Mac OS X|Macintosh/', $ua) => 'macOS',
            1 === preg_match('/Linux/', $ua) => 'Linux',
            default => 'Otro',
        };

        return "{$navegador} · {$sistema}";
    }

    /**
     * Registra una sesión nueva. Un dispositivo es nuevo si la cuenta ya tenía
     * sesiones en la ventana de historial y ninguna desde ese navegador y sistema.
     *
     * @return array{id: string, dispositivo: string, nuevo_dispositivo: bool}
     */
    public function abrir(string $usuarioHex, ?string $ip, ?string $userAgent, string $metodo): array
    {
        $ahora = Reloj::ahora();
        $dispositivo = self::etiquetaDispositivo($userAgent);
        $desde = $ahora->modify("-{$this->ajustes->historialSesionesDias} days");
        $conteo = $this->conexion->fetchAssociative(
            'SELECT COUNT(*) AS "PREVIAS", '
            .'COUNT(CASE WHEN "SES_Dispositivo" = :dispositivo THEN 1 END) AS "CONOCIDAS" '
            .'FROM "SYS_SESION" WHERE "USU_Usuario" = :usuario AND "SES_Creada_En" >= :desde',
            ['usuario' => $usuarioHex, 'desde' => Reloj::aBd($desde), 'dispositivo' => $dispositivo],
        );
        $id = Ident::nuevo();
        $this->conexion->executeStatement(
            'INSERT INTO "SYS_SESION" ("SES_Sesion", "USU_Usuario", "SES_Creada_En", "SES_Ultima_Actividad", '
            .'"SES_Expira", "SES_IP", "SES_User_Agent", "SES_Dispositivo", "SES_Metodo") '
            .'VALUES (:id, :usuario, :ahora, :ahora, :expira, :ip, :ua, :dispositivo, :metodo)',
            [
                'id' => $id,
                'usuario' => $usuarioHex,
                'ahora' => Reloj::aBd($ahora),
                'expira' => Reloj::aBd($ahora->modify("+{$this->ajustes->sesionMaxHoras} hours")),
                'ip' => self::recortar($ip, 45),
                'ua' => self::recortar($userAgent, 255),
                'dispositivo' => $dispositivo,
                'metodo' => $metodo,
            ],
        );

        return [
            'id' => $id,
            'dispositivo' => $dispositivo,
            'nuevo_dispositivo' => (int) $conteo['PREVIAS'] > 0 && 0 === (int) $conteo['CONOCIDAS'],
        ];
    }

    public function fijarRefreshJti(string $sesionHex, ?string $jti): void
    {
        $this->conexion->executeStatement(
            'UPDATE "SYS_SESION" SET "SES_Refresh_Jti" = :jti WHERE "SES_Sesion" = :id',
            ['jti' => $jti, 'id' => $sesionHex],
        );
    }

    /** null si la sesión no existe. */
    public function estaCerrada(string $sid): ?bool
    {
        if (!Ident::esValido($sid)) {
            return null;
        }
        $cerrada = $this->conexion->fetchOne(
            'SELECT CASE WHEN "SES_Cerrada_En" IS NULL THEN 0 ELSE 1 END FROM "SYS_SESION" WHERE "SES_Sesion" = :id',
            ['id' => Ident::aHex($sid)],
        );

        return false === $cerrada ? null : 1 === (int) $cerrada;
    }

    /**
     * @return array{id: string, usuario: string, creada: \DateTimeImmutable, ultima: \DateTimeImmutable,
     *     expira: \DateTimeImmutable, ip: ?string, dispositivo: ?string, metodo: string, refresh_jti: ?string,
     *     cerrada: ?\DateTimeImmutable}|null
     */
    public function obtener(string $sid): ?array
    {
        if (!Ident::esValido($sid)) {
            return null;
        }
        $fila = $this->conexion->fetchAssociative(
            self::SELECCION.' WHERE "SES_Sesion" = :id',
            ['id' => Ident::aHex($sid)],
        );

        return false === $fila ? null : self::desdeFila($fila);
    }

    /** @param array{cerrada: ?\DateTimeImmutable, expira: \DateTimeImmutable}|null $sesion */
    public static function activa(?array $sesion): bool
    {
        return null !== $sesion && null === $sesion['cerrada'] && $sesion['expira'] > Reloj::ahora();
    }

    public function tocar(string $sesionHex, ?string $refreshJti, ?string $ip): void
    {
        $this->conexion->executeStatement(
            'UPDATE "SYS_SESION" SET "SES_Ultima_Actividad" = :ahora, "SES_Refresh_Jti" = :jti, '
            .'"SES_IP" = COALESCE(:ip, "SES_IP") WHERE "SES_Sesion" = :id',
            ['ahora' => Reloj::aBd(Reloj::ahora()), 'jti' => $refreshJti, 'ip' => self::recortar($ip, 45), 'id' => $sesionHex],
        );
    }

    /**
     * Cierra la sesión (si estaba abierta). Devuelve el refresh vigente para
     * que el llamador lo revoque, o false si ya estaba cerrada.
     */
    public function cerrar(string $sesionHex, string $motivo): string|false|null
    {
        $refresh = $this->conexion->fetchOne(
            'SELECT "SES_Refresh_Jti" FROM "SYS_SESION" WHERE "SES_Sesion" = :id AND "SES_Cerrada_En" IS NULL',
            ['id' => $sesionHex],
        );
        $cerradas = $this->conexion->executeStatement(
            'UPDATE "SYS_SESION" SET "SES_Cerrada_En" = :ahora, "SES_Motivo_Cierre" = :motivo '
            .'WHERE "SES_Sesion" = :id AND "SES_Cerrada_En" IS NULL',
            ['ahora' => Reloj::aBd(Reloj::ahora()), 'motivo' => mb_substr($motivo, 0, 30), 'id' => $sesionHex],
        );
        if (1 !== $cerradas) {
            return false;
        }

        return false === $refresh || null === $refresh ? null : (string) $refresh;
    }

    /**
     * Sesiones activas de una cuenta, más recientes primero.
     *
     * @return list<array<string, mixed>>
     */
    public function activas(string $usuarioHex): array
    {
        $filas = $this->conexion->fetchAllAssociative(
            self::SELECCION.' WHERE "USU_Usuario" = :u AND "SES_Cerrada_En" IS NULL AND "SES_Expira" > :ahora '
            .'ORDER BY "SES_Ultima_Actividad" DESC',
            ['u' => $usuarioHex, 'ahora' => Reloj::aBd(Reloj::ahora())],
        );

        return array_map(self::desdeFila(...), $filas);
    }

    /**
     * Representación de la API (igual a la del backend anterior).
     *
     * @param array<string, mixed> $s
     *
     * @return array<string, mixed>
     */
    public static function serializar(array $s, ?string $sidActual = null): array
    {
        return [
            'id' => Ident::aTexto($s['id']),
            'usuario_id' => Ident::aTexto($s['usuario']),
            'creada_en' => Reloj::iso($s['creada']),
            'ultima_actividad' => Reloj::iso($s['ultima']),
            'expira' => Reloj::iso($s['expira']),
            'ip' => $s['ip'],
            'dispositivo' => $s['dispositivo'],
            'metodo' => $s['metodo'],
            'actual' => null !== $sidActual && Ident::esValido($sidActual) && Ident::aHex($sidActual) === $s['id'],
        ];
    }

    private const string SELECCION = 'SELECT "SES_Sesion", "USU_Usuario", "SES_Creada_En", "SES_Ultima_Actividad", "SES_Expira", '
        .'"SES_IP", "SES_Dispositivo", "SES_Metodo", "SES_Refresh_Jti", "SES_Cerrada_En" FROM "SYS_SESION"';

    /**
     * @param array<string, mixed> $f
     *
     * @return array{id: string, usuario: string, creada: \DateTimeImmutable, ultima: \DateTimeImmutable,
     *     expira: \DateTimeImmutable, ip: ?string, dispositivo: ?string, metodo: string, refresh_jti: ?string,
     *     cerrada: ?\DateTimeImmutable}
     */
    private static function desdeFila(array $f): array
    {
        return [
            'id' => (string) $f['SES_Sesion'],
            'usuario' => (string) $f['USU_Usuario'],
            'creada' => Reloj::desdeBd($f['SES_Creada_En']),
            'ultima' => Reloj::desdeBd($f['SES_Ultima_Actividad']),
            'expira' => Reloj::desdeBd($f['SES_Expira']),
            'ip' => $f['SES_IP'],
            'dispositivo' => $f['SES_Dispositivo'],
            'metodo' => (string) $f['SES_Metodo'],
            'refresh_jti' => $f['SES_Refresh_Jti'],
            'cerrada' => Reloj::desdeBd($f['SES_Cerrada_En']),
        ];
    }

    private static function recortar(?string $valor, int $largo): ?string
    {
        $v = mb_substr($valor ?? '', 0, $largo);

        return '' === $v ? null : $v;
    }
}
