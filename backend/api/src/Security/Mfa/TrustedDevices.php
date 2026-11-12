<?php

declare(strict_types=1);

namespace App\Security\Mfa;

use App\Config\Ajustes;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Usuario;
use App\Security\Mfa\Infrastructure\TrustedDeviceStore;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Remember this device": after a valid MFA code the browser gets a random token
 * (HttpOnly cookie, only sent to the sign-in routes). With it, the password still
 * opens the session but the MFA code is not asked again until it expires.
 *
 * Only the token's SHA-256 is stored. A device stops being trusted when it
 * expires, when the password or the MFA secret change (stamp), when every
 * session of the account is revoked, or when its owner removes it.
 */
final readonly class TrustedDevices
{
    public const string COOKIE = 'inv_mfa_device';
    private const string PATH = '/api/v1/login';

    public function __construct(private TrustedDeviceStore $store, private Ajustes $settings)
    {
    }

    public function enabled(): bool
    {
        return $this->settings->mfaRecordarDias > 0;
    }

    /** Remembers the browser and sets its cookie on the response. */
    public function remember(Usuario $user, Response $response, ?string $ip, ?string $device): void
    {
        if (!$this->enabled()) {
            return;
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = Reloj::ahora();
        $expires = $now->modify('+'.$this->settings->mfaRecordarDias.' days');
        $this->store->add($user->id, hash('sha256', $token), $this->stamp($user), null === $device ? null : mb_substr($device, 0, 120), $ip, $now, $expires);
        $response->headers->setCookie(Cookie::create(self::COOKIE)
            ->withValue($token)
            ->withExpires($expires->getTimestamp())
            ->withPath(self::PATH)
            ->withSecure($this->settings->esProduccion())
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT));
    }

    /** Is the request's cookie a live trusted device of this account? Marks its use. */
    public function isTrusted(Usuario $user, Request $request): bool
    {
        $token = (string) $request->cookies->get(self::COOKIE, '');
        if (!$this->enabled() || '' === $token) {
            return false;
        }
        $now = Reloj::ahora();
        $known = $this->store->findLive($user->id, hash('sha256', $token), $now);
        if (null === $known || !hash_equals($known['stamp'], $this->stamp($user))) {
            return false;
        }
        $this->store->markUsed($known['id'], $now);

        return true;
    }

    /** @return list<array{id: int, device: ?string, ip: ?string, created_at: ?string, expires_at: ?string, last_used_at: ?string}> */
    public function listFor(string $userHex): array
    {
        $rows = $this->store->live($userHex, Reloj::ahora());

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['TDV_ID'],
            'device' => $r['TDV_DEVICE'],
            'ip' => $r['TDV_IP'],
            'created_at' => Reloj::iso(Reloj::desdeBd($r['TDV_CREATED_AT'])),
            'expires_at' => Reloj::iso(Reloj::desdeBd($r['TDV_EXPIRES_AT'])),
            'last_used_at' => Reloj::iso(Reloj::desdeBd($r['TDV_LAST_USED_AT'])),
        ], $rows);
    }

    /** Removes one trusted device of the account; false if it was not there. */
    public function forget(string $userHex, int $id): bool
    {
        return $this->store->remove($userHex, $id);
    }

    public function forgetAll(string $userHex): int
    {
        return $this->store->removeAll($userHex);
    }

    /** Fingerprint of the password and MFA secret the device was trusted with. */
    private function stamp(Usuario $user): string
    {
        return hash_hmac('sha256', ($user->passwordHash ?? '').'|'.($user->dosFactoresSecreto ?? '').'|'.Ident::aTexto($user->id),
            $this->settings->claveSecreta);
    }
}
