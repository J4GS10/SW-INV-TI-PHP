<?php

declare(strict_types=1);

namespace App\Realtime;

use App\Config\Ajustes;
use Firebase\JWT\JWT;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Live updates through the Mercure hub that FrankenPHP serves at /.well-known/mercure.
 *
 * Events only say WHAT changed (an API path), never the data: each browser reloads
 * what it shows through the normal API, with its own permissions and scope.
 * Changes are queued and sent at the end of the request or worker message, once
 * the transaction is committed. A hub failure is logged and never breaks the write.
 */
final class UpdatePublisher implements ResetInterface
{
    public const string TOPIC_CHANGES = 'urn:inventario:changes';
    // Must match `issuer` and `resource_identifier` in docker/Caddyfile.
    public const string ISSUER = 'urn:inventario:api';
    public const string AUDIENCE = 'https://inventario.invalid/hub';
    public const string COOKIE = '__Secure-mercure_access_token';
    public const string COOKIE_PATH = '/.well-known/mercure';

    /** @var array<string, array{topic: string, data: array<string, string>}> */
    private array $pending = [];

    public function __construct(
        private readonly Ajustes $ajustes,
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function userTopic(string $userHex): string
    {
        return 'urn:inventario:user:'.strtolower($userHex);
    }

    public function enabled(): bool
    {
        return null !== $this->ajustes->mercureUrl && null !== $this->ajustes->mercureClave;
    }

    /** Something visible to other users changed (`resource` = API path without /api/v1/). */
    public function changed(string $resource, ?string $origin = null): void
    {
        $this->queue(self::TOPIC_CHANGES, ['r' => $resource, 'o' => $origin ?? '']);
    }

    /** The user's account or sessions changed: their open tabs check the session at once. */
    public function userChanged(string $userHex, ?string $origin = null): void
    {
        $this->queue(self::userTopic($userHex), ['r' => 'me', 'o' => $origin ?? '']);
    }

    /** Subscriber JWT for the cookie: the global topic and the user's own one. */
    public function subscriberToken(string $userHex, int $seconds): ?string
    {
        if (!$this->enabled()) {
            return null;
        }

        return $this->token('subscribe', [self::TOPIC_CHANGES, self::userTopic($userHex)], 'user:'.strtolower($userHex), $seconds);
    }

    public function flush(): void
    {
        if ([] === $this->pending) {
            return;
        }
        $pending = $this->pending;
        $this->pending = [];
        if (!$this->enabled()) {
            return;
        }
        $jwt = $this->token('publish', ['*'], 'api', 60);
        foreach ($pending as $update) {
            try {
                $this->http->request('POST', (string) $this->ajustes->mercureUrl, [
                    'headers' => ['Authorization' => 'Bearer '.$jwt],
                    'body' => ['topic' => $update['topic'], 'data' => json_encode($update['data'], \JSON_THROW_ON_ERROR), 'private' => 'on'],
                    'timeout' => 2,
                ])->getStatusCode();
            } catch (\Throwable $e) {
                $this->logger->warning('realtime.publish_failed', ['topic' => $update['topic'], 'error' => mb_substr($e->getMessage(), 0, 200)]);
            }
        }
    }

    /** @return list<array{topic: string, data: array<string, string>}> queued updates (tests) */
    public function queued(): array
    {
        return array_values($this->pending);
    }

    public function reset(): void
    {
        $this->pending = [];
    }

    /**
     * Access token of the Mercure protocol (RFC 9068 + RFC 9396 authorization details).
     *
     * @param list<string> $topics
     */
    private function token(string $action, array $topics, string $subject, int $seconds): string
    {
        $now = time();

        return JWT::encode([
            'iss' => self::ISSUER,
            'aud' => self::AUDIENCE,
            'sub' => $subject,
            'client_id' => 'inventario',
            'iat' => $now,
            'exp' => $now + $seconds,
            'jti' => bin2hex(random_bytes(16)),
            'authorization_details' => [[
                'type' => 'https://mercure.rocks/authorization-detail',
                'actions' => [$action],
                'topics' => array_map(static fn (string $t): array => ['match' => $t], $topics),
            ]],
        ], (string) $this->ajustes->mercureClave, 'HS256', null, ['typ' => 'at+jwt']);
    }

    /** @param array<string, string> $data */
    private function queue(string $topic, array $data): void
    {
        // One event per topic and resource per request: a bulk write does not flood the browsers.
        $this->pending[$topic.' '.$data['r']] = ['topic' => $topic, 'data' => $data];
    }
}
