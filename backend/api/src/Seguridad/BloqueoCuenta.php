<?php

declare(strict_types=1);

namespace App\Seguridad;

use App\Config\Ajustes;
use App\Dominio\Reloj;
use App\Dominio\Usuario;

/**
 * Bloqueo progresivo por intentos fallidos (contraseña o segundo factor), por cuenta e IP.
 *
 * Cada ACCOUNT_LOCKOUT_THRESHOLD fallos desde una misma IP bloquean la cuenta para
 * ESA IP (fase 8: quien conoce un usuario no deja a su titular sin acceso). Los fallos
 * de todas las IP suman además en la cuenta: con el umbral × ACCOUNT_LOCKOUT_GLOBAL_FACTOR
 * (ataque distribuido) se bloquea la cuenta entera. Sin Redis el bloqueo por IP no
 * existe y la cuenta se bloquea con el umbral simple (comportamiento anterior).
 *
 * Cada bloqueo consecutivo dura el doble: ACCOUNT_LOCKOUT_MINUTES × 2^(n-1), con tope en
 * ACCOUNT_LOCKOUT_MAX_MINUTES. La escalada se reinicia con un login completo,
 * un desbloqueo administrativo o tras ACCOUNT_LOCKOUT_RESET_HOURS sin bloqueos.
 */
final readonly class BloqueoCuenta
{
    private const int MAX_IPS = 50;

    public function __construct(private Ajustes $ajustes, private ?CacheAuth $cache = null)
    {
    }

    /** Duración en minutos del n-ésimo bloqueo consecutivo (n ≥ 1). */
    public function duracionMinutos(int $bloqueos): int
    {
        $exponente = max(0, min($bloqueos - 1, 20));

        return min(
            $this->ajustes->bloqueoMinutos * (2 ** $exponente),
            max($this->ajustes->bloqueoMaxMinutos, $this->ajustes->bloqueoMinutos),
        );
    }

    public function estaBloqueada(Usuario $u, \DateTimeImmutable $ahora, ?string $ip = null): bool
    {
        if (null !== $u->bloqueadoHasta && $u->bloqueadoHasta > $ahora) {
            return true;
        }
        $porIp = $this->porIp($u, $ip);

        return null !== $porIp && $porIp['hasta'] > $ahora->getTimestamp();
    }

    /**
     * Un bloqueo vencido deja los intentos en cero (si no, un solo fallo volvía
     * a bloquear) pero conserva la escalada para que el siguiente dure más.
     */
    public function liberarSiVencido(Usuario $u, \DateTimeImmutable $ahora): void
    {
        if (null !== $u->bloqueadoHasta && $u->bloqueadoHasta <= $ahora) {
            $u->bloqueadoHasta = null;
            $u->intentosFallidos = 0;
        }
    }

    /** Suma un intento fallido; true si con él la cuenta queda bloqueada (para esa IP o entera). */
    public function registrarFallo(Usuario $u, \DateTimeImmutable $ahora, ?string $ip = null): bool
    {
        $this->liberarSiVencido($u, $ahora);
        $porIp = null !== $ip && true === $this->cache?->disponible();
        $bloqueadaIp = $porIp && $this->falloPorIp($u, (string) $ip, $ahora);
        ++$u->intentosFallidos;
        $umbral = $this->ajustes->bloqueoUmbral * ($porIp ? $this->ajustes->bloqueoFactorGlobal : 1);
        if ($u->intentosFallidos < $umbral) {
            return $bloqueadaIp;
        }
        $u->bloqueosConsecutivos = $this->escalada($u->ultimoBloqueo, $u->bloqueosConsecutivos, $ahora) + 1;
        $u->ultimoBloqueo = $ahora;
        $u->bloqueadoHasta = $ahora->modify('+'.$this->duracionMinutos($u->bloqueosConsecutivos).' minutes');

        return true;
    }

    /** Login completo (solo su IP) o desbloqueo administrativo (todas las IP): sin fallos ni escalada. */
    public function limpiar(Usuario $u, ?string $ip = null): void
    {
        $u->intentosFallidos = 0;
        $u->bloqueadoHasta = null;
        $u->bloqueosConsecutivos = 0;
        if (null === $this->cache) {
            return;
        }
        if (null === $ip) {
            $this->cache->borrar($this->clave($u));

            return;
        }
        $mapa = $this->cache->obtener($this->clave($u)) ?? [];
        if (isset($mapa[$this->huella($ip)])) {
            unset($mapa[$this->huella($ip)]);
            $this->guardar($u, $mapa);
        }
    }

    /** @return array<string, mixed> */
    public function detalle(Usuario $u, ?string $ip = null): array
    {
        $porIp = $this->porIp($u, $ip);

        return [
            'bloqueos_consecutivos' => $u->bloqueosConsecutivos,
            'bloqueado_hasta' => Reloj::iso($u->bloqueadoHasta),
            ...(null === $porIp ? [] : ['ip_bloqueos' => $porIp['bloqueos'],
                'ip_bloqueada_hasta' => Reloj::iso((new \DateTimeImmutable())->setTimestamp($porIp['hasta']))]),
        ];
    }

    /** Suma el fallo a la pareja cuenta-IP; true si con él esa IP queda bloqueada. */
    private function falloPorIp(Usuario $u, string $ip, \DateTimeImmutable $ahora): bool
    {
        $e = $this->porIp($u, $ip) ?? ['fallos' => 0, 'hasta' => 0, 'bloqueos' => 0, 'ultimo' => 0];
        if ($e['hasta'] > 0 && $e['hasta'] <= $ahora->getTimestamp()) {
            $e['fallos'] = 0;
            $e['hasta'] = 0;
        }
        ++$e['fallos'];
        $bloqueada = $e['fallos'] >= $this->ajustes->bloqueoUmbral;
        if ($bloqueada) {
            $ultimo = 0 === $e['ultimo'] ? null : (new \DateTimeImmutable())->setTimestamp($e['ultimo']);
            $e['bloqueos'] = $this->escalada($ultimo, $e['bloqueos'], $ahora) + 1;
            $e['ultimo'] = $ahora->getTimestamp();
            $e['hasta'] = $ahora->getTimestamp() + 60 * $this->duracionMinutos($e['bloqueos']);
        }
        $mapa = $this->cache?->obtener($this->clave($u)) ?? [];
        unset($mapa[$this->huella($ip)]);
        // Las IP más recientes al final; un ataque desde muchas IP no hace crecer el registro sin tope.
        $mapa[$this->huella($ip)] = $e;
        $this->guardar($u, \array_slice($mapa, -self::MAX_IPS, preserve_keys: true));

        return $bloqueada;
    }

    /** Bloqueos consecutivos vigentes: la escalada caduca tras ACCOUNT_LOCKOUT_RESET_HOURS sin bloqueos. */
    private function escalada(?\DateTimeImmutable $ultimo, int $bloqueos, \DateTimeImmutable $ahora): int
    {
        return null === $ultimo || $ultimo <= $ahora->modify("-{$this->ajustes->bloqueoReinicioHoras} hours") ? 0 : $bloqueos;
    }

    /** @return array{fallos: int, hasta: int, bloqueos: int, ultimo: int}|null */
    private function porIp(Usuario $u, ?string $ip): ?array
    {
        if (null === $ip || null === $this->cache) {
            return null;
        }
        $e = ($this->cache->obtener($this->clave($u)) ?? [])[$this->huella($ip)] ?? null;

        return \is_array($e) ? ['fallos' => (int) ($e['fallos'] ?? 0), 'hasta' => (int) ($e['hasta'] ?? 0),
            'bloqueos' => (int) ($e['bloqueos'] ?? 0), 'ultimo' => (int) ($e['ultimo'] ?? 0)] : null;
    }

    /** @param array<string, mixed> $mapa */
    private function guardar(Usuario $u, array $mapa): void
    {
        [] === $mapa ? $this->cache?->borrar($this->clave($u))
            : $this->cache?->guardar($this->clave($u), $mapa, 3600 * $this->ajustes->bloqueoReinicioHoras + 60 * $this->ajustes->bloqueoMaxMinutos);
    }

    private function clave(Usuario $u): string
    {
        return 'lockout:'.hash('sha256', mb_strtolower($u->username));
    }

    private function huella(string $ip): string
    {
        return substr(hash('sha256', $ip), 0, 16);
    }
}
