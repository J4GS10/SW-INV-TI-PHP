<?php

declare(strict_types=1);

namespace App\Directorio;

use App\Correo\Cortacircuitos;
use App\Integracion\ConfigAd;
use Psr\Log\LoggerInterface;

/**
 * Active Directory por LDAP sobre una configuración concreta.
 *
 * Con cortacircuitos (uso normal), un fallo de autenticación pausa los binds
 * 15 minutos: los reintentos de la cola de correos y las consultas de grupos no
 * deben repetir una clave mala hasta bloquear la cuenta de servicio en el AD.
 * Las pruebas desde la interfaz no lo usan.
 *
 * Membresía de grupos con LDAP_MATCHING_RULE_IN_CHAIN: incluye grupos anidados.
 */
final class DirectorioLdap implements Directorio
{
    private const string EN_CADENA = '1.2.840.113556.1.4.1941';
    private const array ATRIBUTOS_USUARIO = ['objectGUID', 'distinguishedName', 'sAMAccountName', 'userPrincipalName',
        'mail', 'givenName', 'sn', 'department', 'title', 'telephoneNumber', 'mobile', 'manager', 'userAccountControl'];

    /** @var array<string, array{0: float, 1: list<string>}> grupo => [vence, correos] */
    private array $cacheGrupos = [];

    public function __construct(
        private ConfigAd $config,
        private readonly ?Cortacircuitos $cortacircuitos,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Misma conexión, configuración renovada (mismo servidor y cuenta: conserva la caché de grupos). */
    public function actualizar(ConfigAd $config): void
    {
        $this->config = $config;
    }

    public function probarConexion(): int
    {
        return \count($this->usuarios());
    }

    public function usuarios(): array
    {
        $entradas = $this->ejecutar(fn (ConexionLdap $c): array => $c->buscar($this->config->baseDn, $this->config->filtroUsuarios, self::ATRIBUTOS_USUARIO));

        return array_values(array_filter(array_map(UsuarioDirectorio::desdeAtributos(...), $entradas)));
    }

    public function correosDeGrupo(string $grupo): array
    {
        $clave = mb_strtolower($grupo);
        $guardado = $this->cacheGrupos[$clave] ?? null;
        $ahora = hrtime(true) / 1e9;
        if (null !== $guardado && $guardado[0] > $ahora) {
            return $guardado[1];
        }
        try {
            $correos = $this->ejecutar(fn (ConexionLdap $c): array => $this->miembros($c, $grupo));
        } catch (ErrorDirectorio $e) {
            // Si el AD cae se usa el último valor conocido, aunque haya vencido.
            if (null !== $guardado) {
                $this->logger->warning('ad.miembros_de_grupo_vencidos', ['grupo' => $grupo]);

                return $guardado[1];
            }
            throw $e;
        }
        $this->cacheGrupos[$clave] = [$ahora + $this->config->cacheGruposSegundos, $correos];

        return $correos;
    }

    public function buscarGrupos(string $texto, int $limite = 20): array
    {
        $texto = trim($texto);
        $filtro = '' === $texto ? '(objectClass=group)' : '(&(objectClass=group)(cn=*'.ConexionLdap::escapar($texto).'*))';
        $entradas = $this->ejecutar(fn (ConexionLdap $c): array => $c->buscar($this->config->baseGrupos(), $filtro, ['cn', 'distinguishedName']));
        $grupos = [];
        foreach ($entradas as $e) {
            if (isset($e['cn'][0]) && '' !== $e['cn'][0]) {
                $grupos[] = new GrupoDirectorio($e['cn'][0], $e['distinguishedname'][0] ?? '');
            }
        }
        usort($grupos, static fn (GrupoDirectorio $a, GrupoDirectorio $b): int => mb_strtolower($a->nombre) <=> mb_strtolower($b->nombre));

        return \array_slice($grupos, 0, $limite);
    }

    /** @return list<string> */
    private function miembros(ConexionLdap $c, string $grupo): array
    {
        $dn = $this->dnDeGrupo($c, $grupo);
        if (null === $dn) {
            $this->logger->warning('ad.grupo_no_encontrado', ['grupo' => $grupo]);

            return [];
        }
        $filtro = '(&(objectCategory=person)(objectClass=user)(mail=*)'
            .'(!(userAccountControl:1.2.840.113556.1.4.803:='.UsuarioDirectorio::CUENTA_DESHABILITADA.'))'
            .'(memberOf:'.self::EN_CADENA.':='.ConexionLdap::escapar($dn).'))';
        $correos = [];
        foreach ($c->buscar($this->config->raizDominio(), $filtro, ['mail']) as $e) {
            if (isset($e['mail'][0]) && '' !== trim($e['mail'][0])) {
                $correos[strtolower(trim($e['mail'][0]))] = true;
            }
        }
        $lista = array_map('strval', array_keys($correos));
        sort($lista);

        return $lista;
    }

    /** El grupo puede venir como DN o como nombre (cn o sAMAccountName). */
    private function dnDeGrupo(ConexionLdap $c, string $grupo): ?string
    {
        if (str_contains($grupo, '=') && str_contains($grupo, ',')) {
            return $grupo;
        }
        $g = ConexionLdap::escapar($grupo);
        $entradas = $c->buscar($this->config->baseGrupos(), "(&(objectClass=group)(|(cn={$g})(sAMAccountName={$g})))", ['distinguishedName']);

        return $entradas[0]['distinguishedname'][0] ?? null;
    }

    /**
     * Abre la conexión, ejecuta la operación y la cierra, respetando el cortacircuitos.
     *
     * @template T
     *
     * @param \Closure(ConexionLdap): T $operacion
     *
     * @return T
     */
    private function ejecutar(\Closure $operacion): mixed
    {
        if (null !== $this->cortacircuitos?->pausadoHasta('ad')) {
            throw new ErrorAutenticacionDirectorio('AD_AUTH_PAUSED');
        }
        try {
            $conexion = ConexionLdap::abrir($this->config);
        } catch (ErrorAutenticacionDirectorio $e) {
            $this->cortacircuitos?->disparar('ad', $e->getMessage());
            throw $e;
        }
        try {
            return $operacion($conexion);
        } finally {
            $conexion->cerrar();
        }
    }
}
