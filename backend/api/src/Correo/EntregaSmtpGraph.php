<?php

declare(strict_types=1);

namespace App\Correo;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as ExcepcionHttp;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Entrega por SMTP (Symfony Mailer) o Microsoft Graph (/users/{buzón}/sendMail,
 * permiso de aplicación Mail.Send), con los mismos códigos de error que el
 * backend anterior. Un fallo de credenciales dispara el cortacircuitos.
 */
final class EntregaSmtpGraph implements EntregaCorreo
{
    /** @var array{clave: string, valor: string, expira: float}|null */
    private ?array $tokenGraph = null;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly Cortacircuitos $cortacircuitos,
        private readonly MetricasCorreo $metricas,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function entregar(ConfigCorreo $config, array $para, string $asunto, string $html, ?string $responderA = null, bool $usarCortacircuitos = true): void
    {
        if (!$config->lista()) {
            throw new ErrorEnvioCorreo('NOT_CONFIGURED');
        }
        if ($usarCortacircuitos && null !== $this->cortacircuitos->pausadoHasta('smtp')) {
            throw new ErrorEnvioCorreo('AUTH_PAUSED');
        }
        try {
            'GRAPH' === $config->proveedor
                ? $this->graph($config, $para, $asunto, $html, $responderA)
                : $this->smtp($config, $para, $asunto, $html, $responderA);
        } catch (ErrorEnvioCorreo $e) {
            $this->metricas->fallo($e->codigo);
            if ('AUTH_FAILED' === $e->codigo && $usarCortacircuitos) {
                $this->cortacircuitos->disparar('smtp', $e->getMessage());
            }
            throw $e;
        }
        $this->metricas->exito();
        $this->logger->info('email.sent', ['para' => \count($para), 'asunto' => $asunto, 'proveedor' => $config->proveedor]);
    }

    /** @param list<string> $para */
    private function smtp(ConfigCorreo $c, array $para, string $asunto, string $html, ?string $responderA): void
    {
        $transporte = new EsmtpTransport((string) $c->host, $c->puerto, 'SSL' === $c->seguridad);
        // STARTTLS obligatorio (no se degrada a texto plano); NINGUNA no intenta TLS.
        $transporte->setAutoTls('STARTTLS' === $c->seguridad);
        $transporte->setRequireTls('STARTTLS' === $c->seguridad);
        if (null !== $c->password && null !== $c->usuario) {
            $transporte->setUsername($c->usuario);
            $transporte->setPassword($c->password);
        }
        $flujo = $transporte->getStream();
        if (method_exists($flujo, 'setTimeout')) {
            $flujo->setTimeout($c->timeout);
        }

        $correo = (new Email())
            ->from(new Address((string) $c->remitente, $c->nombreRemitente))
            ->to(...$para)
            ->subject($asunto)
            ->text(Plantillas::textoPlano($html))
            ->html($html);
        if (null !== $responderA && '' !== $responderA) {
            $correo->replyTo($responderA);
        }

        try {
            $transporte->send($correo);
        } catch (TransportExceptionInterface $e) {
            throw self::clasificarSmtp($e);
        } finally {
            try {
                $transporte->stop();
            } catch (\Throwable) {
            }
        }
    }

    public static function clasificarSmtp(TransportExceptionInterface $e): ErrorEnvioCorreo
    {
        $mensaje = mb_substr($e->getMessage(), 0, 300);
        $codigo = $e instanceof TransportException ? $e->getCode() : 0;
        $texto = strtolower($mensaje);

        return match (true) {
            535 === $codigo || 534 === $codigo || str_contains($texto, 'authenticat') => new ErrorEnvioCorreo('AUTH_FAILED', $mensaje, $e),
            str_contains($texto, 'tls') || str_contains($texto, 'ssl') || str_contains($texto, 'certificate') => new ErrorEnvioCorreo('TLS_FAILED', $mensaje, $e),
            \in_array($codigo, [550, 553, 554], true) && str_contains($texto, 'from') => new ErrorEnvioCorreo('SENDER_REJECTED', $mensaje, $e),
            str_contains($texto, 'connection') || str_contains($texto, 'timed out') || str_contains($texto, 'unable to') => new ErrorEnvioCorreo('CONNECTION_FAILED', $mensaje, $e),
            default => new ErrorEnvioCorreo('ERROR', $mensaje, $e),
        };
    }

    /** @param list<string> $para */
    private function graph(ConfigCorreo $c, array $para, string $asunto, string $html, ?string $responderA): void
    {
        $token = $this->tokenGraph($c);
        $mensaje = [
            'subject' => $asunto,
            'body' => ['contentType' => 'HTML', 'content' => $html],
            'toRecipients' => array_map(static fn (string $d): array => ['emailAddress' => ['address' => $d]], $para),
        ];
        if (null !== $responderA && '' !== $responderA) {
            $mensaje['replyTo'] = [['emailAddress' => ['address' => $responderA]]];
        }
        try {
            $respuesta = $this->http->request('POST', 'https://graph.microsoft.com/v1.0/users/'.rawurlencode((string) $c->remitente).'/sendMail', [
                'auth_bearer' => $token,
                'json' => ['message' => $mensaje, 'saveToSentItems' => false],
                'timeout' => $c->timeout,
            ]);
            $estado = $respuesta->getStatusCode();
            $cuerpo = 202 === $estado ? '' : mb_substr($respuesta->getContent(false), 0, 250);
        } catch (ExcepcionHttp $e) {
            throw new ErrorEnvioCorreo('CONNECTION_FAILED', $e::class.': '.mb_substr($e->getMessage(), 0, 250), $e);
        }
        if (202 === $estado) {
            return;
        }
        if (401 === $estado || 403 === $estado) {
            $this->tokenGraph = null;
            throw new ErrorEnvioCorreo('AUTH_FAILED', "Graph HTTP {$estado}: {$cuerpo}");
        }
        if (404 === $estado) {
            throw new ErrorEnvioCorreo('SENDER_REJECTED', "Buzón no encontrado: {$c->remitente}");
        }
        throw new ErrorEnvioCorreo('ERROR', "Graph HTTP {$estado}: {$cuerpo}");
    }

    /** Token app-only (client credentials), cacheado hasta un minuto antes de expirar. */
    private function tokenGraph(ConfigCorreo $c): string
    {
        $clave = hash('sha256', $c->graphTenant."\0".$c->graphCliente."\0".$c->graphSecreto);
        if (null !== $this->tokenGraph && $this->tokenGraph['clave'] === $clave && $this->tokenGraph['expira'] > microtime(true) + 60) {
            return $this->tokenGraph['valor'];
        }
        try {
            $respuesta = $this->http->request('POST', 'https://login.microsoftonline.com/'.rawurlencode((string) $c->graphTenant).'/oauth2/v2.0/token', [
                'body' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $c->graphCliente,
                    'client_secret' => $c->graphSecreto,
                    'scope' => 'https://graph.microsoft.com/.default',
                ],
                'timeout' => $c->timeout,
            ]);
            $estado = $respuesta->getStatusCode();
            $datos = $respuesta->toArray(false);
        } catch (ExcepcionHttp $e) {
            throw new ErrorEnvioCorreo('CONNECTION_FAILED', mb_substr($e->getMessage(), 0, 250), $e);
        }
        if (400 === $estado || 401 === $estado) {
            throw new ErrorEnvioCorreo('AUTH_FAILED', mb_substr((string) ($datos['error_description'] ?? ''), 0, 300));
        }
        if ($estado >= 300 || !isset($datos['access_token'])) {
            throw new ErrorEnvioCorreo('CONNECTION_FAILED', "token HTTP {$estado}");
        }
        $this->tokenGraph = [
            'clave' => $clave,
            'valor' => (string) $datos['access_token'],
            'expira' => microtime(true) + (int) ($datos['expires_in'] ?? 3600),
        ];

        return $this->tokenGraph['valor'];
    }
}
