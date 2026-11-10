<?php

declare(strict_types=1);

namespace App\Tests\Unit\Realtime;

use App\Config\Ajustes;
use App\Realtime\PublishChangesListener;
use App\Realtime\UpdatePublisher;
use App\Seguridad\UsuarioActual;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/** Live updates: what is announced after a write, to whom, and with which Mercure token. */
final class UpdatePublisherTest extends TestCase
{
    private const string SECRET = '0123456789abcdef0123456789abcdef0123456789abcdef';
    private const string USER = '0f0e0d0c0b0a09080706050403020100';

    /** @var list<array{url: string, headers: list<string>, body: string}> */
    private array $sent = [];

    public function testAWriteIsAnnouncedOnceAfterTheRequestWithAPublisherToken(): void
    {
        $publisher = $this->publisher();
        $listener = new PublishChangesListener($publisher, new UsuarioActual(), $this->settings());

        $listener->onResponse($this->event('POST', '/api/v1/core/activos', 201, 'tab-1'));
        $listener->onResponse($this->event('POST', '/api/v1/core/activos', 201, 'tab-1'));
        self::assertCount(0, $this->sent, 'Nothing leaves before the response is finished (transaction committed).');

        $listener->flush();
        self::assertCount(1, $this->sent, 'Two identical writes in one request are one event.');
        parse_str($this->sent[0]['body'], $form);
        self::assertSame([UpdatePublisher::TOPIC_CHANGES, ['r' => 'core/activos', 'o' => 'tab-1'], 'on'],
            [$form['topic'], json_decode((string) $form['data'], true), $form['private']]);

        $claims = $this->claims($this->sent[0]['headers']);
        self::assertSame([UpdatePublisher::ISSUER, UpdatePublisher::AUDIENCE, ['publish'], [['match' => '*']]],
            [$claims['iss'], $claims['aud'], $claims['authorization_details'][0]['actions'], $claims['authorization_details'][0]['topics']]);
    }

    public function testReadsFailuresAndSignInAreNotAnnounced(): void
    {
        $publisher = $this->publisher();
        $listener = new PublishChangesListener($publisher, new UsuarioActual(), $this->settings());

        $listener->onResponse($this->event('GET', '/api/v1/core/activos', 200));
        $listener->onResponse($this->event('PUT', '/api/v1/gov/config', 422));
        $listener->onResponse($this->event('POST', '/api/v1/login/refresh', 200));
        $listener->onResponse($this->event('POST', '/health', 200));

        self::assertSame([], $publisher->queued());
    }

    public function testAChangeToAUserAlsoReachesThatUsersOpenTabs(): void
    {
        $publisher = $this->publisher();
        $listener = new PublishChangesListener($publisher, new UsuarioActual(), $this->settings());

        $listener->onResponse($this->event('DELETE', '/api/v1/org/usuarios/0F0E0D0C-0B0A-0908-0706-050403020100/sesiones', 200));

        self::assertSame([UpdatePublisher::TOPIC_CHANGES, UpdatePublisher::userTopic(self::USER)], array_column($publisher->queued(), 'topic'));
    }

    public function testTheSubscriberTokenOnlyOpensTheGlobalTopicAndTheUsersOwn(): void
    {
        $token = (string) $this->publisher()->subscriberToken(self::USER, 600);
        $claims = (array) json_decode((string) json_encode(JWT::decode($token, new Key(self::SECRET, 'HS256'))), true);

        self::assertSame(['subscribe'], $claims['authorization_details'][0]['actions']);
        self::assertSame([['match' => UpdatePublisher::TOPIC_CHANGES], ['match' => 'urn:inventario:user:'.self::USER]],
            $claims['authorization_details'][0]['topics']);
        self::assertEqualsWithDelta(time() + 600, $claims['exp'], 2);
        self::assertSame('at+jwt', json_decode((string) base64_decode(explode('.', $token)[0]), true)['typ']);
    }

    public function testWithoutAHubNothingIsSentAndAFailingHubNeverBreaksTheWrite(): void
    {
        $off = new UpdatePublisher(Ajustes::desdeEntorno(['SECRET_KEY' => self::SECRET]), $this->http(), new NullLogger());
        $off->changed('core/activos');
        $off->flush();
        self::assertSame([], $this->sent);
        self::assertNull($off->subscriberToken(self::USER, 60));

        $broken = new UpdatePublisher($this->settings(), new MockHttpClient(static fn (): MockResponse => throw new \RuntimeException('hub down')), new NullLogger());
        $broken->changed('core/activos');
        $broken->flush();
        self::assertSame([], $broken->queued());
    }

    private function settings(): Ajustes
    {
        return Ajustes::desdeEntorno(['SECRET_KEY' => self::SECRET, 'MERCURE_JWT_SECRET' => self::SECRET,
            'MERCURE_HUB_URL' => 'http://hub.test/.well-known/mercure']);
    }

    private function publisher(): UpdatePublisher
    {
        return new UpdatePublisher($this->settings(), $this->http(), new NullLogger());
    }

    private function http(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->sent[] = ['url' => $url, 'headers' => $options['headers'], 'body' => (string) $options['body']];

            return new MockResponse('urn:uuid:1', ['http_code' => 200]);
        });
    }

    private function event(string $method, string $path, int $status, ?string $origin = null): ResponseEvent
    {
        $request = Request::create($path, $method);
        if (null !== $origin) {
            $request->headers->set(PublishChangesListener::ORIGIN_HEADER, $origin);
        }

        return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, new Response('', $status));
    }

    /**
     * @param list<string> $headers
     *
     * @return array<string, mixed>
     */
    private function claims(array $headers): array
    {
        $auth = (string) current(array_filter($headers, static fn (string $h): bool => str_starts_with(strtolower($h), 'authorization:')));
        $jwt = trim(substr($auth, (int) strpos($auth, 'Bearer ') + 7));

        return (array) json_decode((string) json_encode(JWT::decode($jwt, new Key(self::SECRET, 'HS256'))), true);
    }
}
