<?php

declare(strict_types=1);

namespace App\Directorio;

use App\Integracion\ConfigAd;

/**
 * Conexión LDAP de solo lectura sobre ext-ldap, abierta para una operación y
 * cerrada al terminar.
 *
 * Seguridad del canal (sin excepciones, en todos los entornos): LDAPS o
 * StartTLS y certificado verificado (LDAP_OPT_X_TLS_DEMAND, nombre del
 * servidor incluido) contra AD_CA_CERT_FILE o las CA del sistema. Un bind
 * simple por LDAP plano expondría la contraseña de la cuenta de servicio.
 */
final class ConexionLdap
{
    public const int TAMANO_PAGINA = 500;
    private const string CA_SISTEMA = '/etc/ssl/certs/ca-certificates.crt';

    private function __construct(private \LDAP\Connection $enlace)
    {
    }

    /** Conecta, cifra el canal y hace el bind simple (UPN de la cuenta de servicio). */
    public static function abrir(ConfigAd $c): self
    {
        if (!$c->configurada()) {
            throw new ErrorDirectorio('AD_NOT_CONFIGURED');
        }
        if (!$c->canalCifrado()) {
            throw new ErrorDirectorio('AD_INSECURE_CHANNEL: usar ldaps:// o StartTLS');
        }
        // Opciones TLS globales: libldap las toma al crear el contexto TLS de la conexión.
        ldap_set_option(null, \LDAP_OPT_X_TLS_REQUIRE_CERT, \LDAP_OPT_X_TLS_DEMAND);
        ldap_set_option(null, \LDAP_OPT_X_TLS_CACERTFILE, $c->caCertificado ?? self::CA_SISTEMA);

        $enlace = @ldap_connect(implode(' ', $c->servidores));
        if (false === $enlace) {
            throw new ErrorDirectorio('AD_CONNECTION_FAILED: LDAPSocketOpenError');
        }
        ldap_set_option($enlace, \LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($enlace, \LDAP_OPT_REFERRALS, 0);
        ldap_set_option($enlace, \LDAP_OPT_NETWORK_TIMEOUT, $c->timeoutSegundos);
        ldap_set_option($enlace, \LDAP_OPT_TIMEOUT, $c->timeoutSegundos);
        ldap_set_option($enlace, \LDAP_OPT_TIMELIMIT, $c->timeoutSegundos);

        if ($c->startTls && !@ldap_start_tls($enlace)) {
            $codigo = ldap_errno($enlace);
            @ldap_unbind($enlace);
            throw new ErrorDirectorio('AD_CONNECTION_FAILED: '.(-1 === $codigo ? 'LDAPSocketOpenError' : 'LDAPStartTLSError'));
        }
        if (!@ldap_bind($enlace, (string) $c->usuarioBind, (string) $c->passwordBind)) {
            $codigo = ldap_errno($enlace);
            @ldap_unbind($enlace);
            if (49 === $codigo) {
                throw new ErrorAutenticacionDirectorio('AD_AUTH_FAILED: LDAPInvalidCredentialsResult');
            }
            throw new ErrorDirectorio('AD_CONNECTION_FAILED: '.ErrorDirectorio::tipoLdap($codigo));
        }

        return new self($enlace);
    }

    /**
     * Búsqueda en subárbol con resultados paginados. Cada entrada: atributo (en
     * minúsculas) => lista de valores.
     *
     * @param list<string> $atributos
     *
     * @return list<array<string, list<string>>>
     */
    public function buscar(string $base, string $filtro, array $atributos): array
    {
        $entradas = [];
        $cookie = '';
        do {
            $resultado = @ldap_search($this->enlace, $base, $filtro, $atributos, 0, -1, -1, \LDAP_DEREF_NEVER,
                [['oid' => \LDAP_CONTROL_PAGEDRESULTS, 'value' => ['size' => self::TAMANO_PAGINA, 'cookie' => $cookie]]]);
            if (!$resultado instanceof \LDAP\Result) {
                throw $this->errorDeBusqueda(ldap_errno($this->enlace));
            }
            $controles = [];
            if (!ldap_parse_result($this->enlace, $resultado, $codigo, $dn, $mensaje, $referencias, $controles) || 0 !== $codigo) {
                throw $this->errorDeBusqueda($codigo ?? ldap_errno($this->enlace));
            }
            $lote = ldap_get_entries($this->enlace, $resultado);
            for ($i = 0; $i < ($lote['count'] ?? 0); ++$i) {
                $entradas[] = self::normalizar($lote[$i]);
            }
            ldap_free_result($resultado);
            $cookie = (string) ($controles[\LDAP_CONTROL_PAGEDRESULTS]['value']['cookie'] ?? '');
        } while ('' !== $cookie);

        return $entradas;
    }

    public function cerrar(): void
    {
        @ldap_unbind($this->enlace);
    }

    /** Escape de un valor dentro de un filtro (RFC 4515). */
    public static function escapar(string $valor): string
    {
        return ldap_escape($valor, '', \LDAP_ESCAPE_FILTER);
    }

    private function errorDeBusqueda(int $codigo): ErrorDirectorio
    {
        return new ErrorDirectorio('AD_SEARCH_FAILED: '.ErrorDirectorio::tipoLdap($codigo));
    }

    /**
     * @param array<int|string, mixed> $entrada
     *
     * @return array<string, list<string>>
     */
    private static function normalizar(array $entrada): array
    {
        $atributos = [];
        foreach ($entrada as $nombre => $valores) {
            if (!\is_string($nombre) || !\is_array($valores)) {
                continue;
            }
            unset($valores['count']);
            $atributos[strtolower($nombre)] = array_values(array_map('strval', $valores));
        }

        return $atributos;
    }
}
