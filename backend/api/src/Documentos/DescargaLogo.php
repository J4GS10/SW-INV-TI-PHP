<?php

declare(strict_types=1);

namespace App\Documentos;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Descarga el logo configurado (SYS_Logo_URL) para las actas, sin abrir una vía
 * de SSRF: solo http(s) a los puertos 80/443, sin credenciales en la URL, sin
 * localhost y nunca a una IP privada, de enlace local o reservada (el cliente
 * lo comprueba tras resolver el DNS y en cada redirección, como máximo 3). La
 * respuesta debe ser una imagen de hasta 3 MB, y se descarga en 4 s o se desiste.
 *
 * Si algo falla el acta sale igual, con el nombre de la empresa en texto.
 */
final readonly class DescargaLogo
{
    private const int MAX_BYTES = 3_000_000;
    private const int MAX_REDIRECCIONES = 3;
    private const float TIEMPO_MAXIMO = 4.0;

    private HttpClientInterface $cliente;

    public function __construct(private LoggerInterface $logger, ?HttpClientInterface $cliente = null, private ?\Doctrine\DBAL\Connection $conexion = null)
    {
        // Siempre envuelto: ni el cliente inyectado puede llegar a la red interna.
        $this->cliente = new NoPrivateNetworkHttpClient($cliente ?? HttpClient::create());
    }

    public function descargar(?string $url): ?Logo
    {
        $url = trim((string) $url);
        // El logo subido al sistema se lee de la base: no hay descarga ni red.
        if (str_starts_with($url, '/api/v1/gov/config/logo') && null !== $this->conexion) {
            $bytes = $this->conexion->fetchOne('SELECT "SYS_Logo_Image" FROM "SYS_CONFIGURACION" WHERE "SYS_Configuracion" = 1');

            return \is_string($bytes) ? Logo::desdeBytes($bytes) : null;
        }
        if (!self::esUrlAdmitida($url)) {
            if ('' !== $url) {
                $this->logger->warning('documents.logo_url_blocked', ['target' => self::paraLog($url)]);
            }

            return null;
        }
        try {
            $respuesta = $this->cliente->request('GET', $url, [
                'headers' => ['Accept' => 'image/*'],
                'max_redirects' => self::MAX_REDIRECCIONES,
                'timeout' => self::TIEMPO_MAXIMO,
                'max_duration' => self::TIEMPO_MAXIMO,
                // Cada redirección se valida igual que la URL inicial.
                'on_progress' => static function (int $descargado, int $total, array $info): void {
                    if (!self::esUrlAdmitida((string) ($info['url'] ?? ''))) {
                        throw new TransportException('LOGO_REDIRECT_BLOCKED');
                    }
                    if ($descargado > self::MAX_BYTES || $total > self::MAX_BYTES) {
                        throw new TransportException('LOGO_TOO_LARGE');
                    }
                },
            ]);
            if (200 !== $respuesta->getStatusCode()) {
                return null;
            }
            $tipo = strtolower($respuesta->getHeaders(false)['content-type'][0] ?? '');
            if (!str_starts_with($tipo, 'image/')) {
                return null;
            }
            $contenido = '';
            foreach ($this->cliente->stream($respuesta) as $trozo) {
                $contenido .= $trozo->getContent();
                if (\strlen($contenido) > self::MAX_BYTES) {
                    $respuesta->cancel();

                    return null;
                }
            }

            return '' === $contenido ? null : Logo::desdeBytes($contenido);
        } catch (ExceptionInterface $e) {
            $this->logger->warning('documents.logo_fetch_failed', ['target' => self::paraLog($url), 'error' => mb_substr($e->getMessage(), 0, 160)]);

            return null;
        }
    }

    /** Esquema, puerto, credenciales y host (la IP la verifica el cliente al resolver). */
    public static function esUrlAdmitida(string $url): bool
    {
        $partes = parse_url($url);
        if (false === $partes || !isset($partes['scheme'], $partes['host'])) {
            return false;
        }
        if (!\in_array(strtolower($partes['scheme']), ['http', 'https'], true) || isset($partes['user']) || isset($partes['pass'])) {
            return false;
        }
        if (isset($partes['port']) && !\in_array($partes['port'], [80, 443], true)) {
            return false;
        }
        $host = rtrim(strtolower(trim($partes['host'], '[]')), '.');

        return '' !== $host && 'localhost' !== $host && !str_ends_with($host, '.localhost');
    }

    /** Solo esquema y host en la bitácora (la ruta o la consulta pueden traer tokens). */
    private static function paraLog(string $url): string
    {
        $partes = parse_url($url);

        return false === $partes ? 'invalid-url' : ($partes['scheme'] ?? '').'://'.($partes['host'] ?? 'unknown-host');
    }
}
