<?php

declare(strict_types=1);

namespace App\Integracion;

/**
 * Configuración efectiva de Active Directory (LDAP). El canal siempre va
 * cifrado y verificado (LDAPS o StartTLS con validación del certificado):
 * `verificarCertificado` se conserva solo para el contrato de la API.
 */
final readonly class ConfigAd
{
    public const string FILTRO_USUARIOS = '(&(objectCategory=person)(objectClass=user)(mail=*))';

    /** @param list<string> $servidores URL ldap:// o ldaps:// (varios DC: en orden) */
    public function __construct(
        public bool $habilitado,
        public array $servidores = [],
        public string $baseDn = '',
        public string $grupoBaseDn = '',
        public string $filtroUsuarios = self::FILTRO_USUARIOS,
        public string $grupoAdmin = '',
        public bool $startTls = false,
        public bool $verificarCertificado = true,
        public ?string $caCertificado = null,
        public ?string $usuarioBind = null,
        public ?string $passwordBind = null,
        public int $timeoutSegundos = 10,
        public int $cacheGruposSegundos = 600,
        public int $intervaloMinutos = 0,
        public bool $desactivar = true,
        public string $departamentoDefecto = 'Sin departamento',
        public string $cargoDefecto = 'Sin cargo',
    ) {
    }

    public function configurada(): bool
    {
        return [] !== $this->servidores && '' !== $this->baseDn && null !== $this->usuarioBind;
    }

    public function lista(): bool
    {
        return $this->habilitado && $this->configurada();
    }

    /** Todos los servidores con LDAPS, o StartTLS sobre ldap://. */
    public function canalCifrado(): bool
    {
        if ($this->startTls) {
            return true;
        }
        foreach ($this->servidores as $url) {
            if (!str_starts_with(strtolower($url), 'ldaps://')) {
                return false;
            }
        }

        return [] !== $this->servidores;
    }

    /** Base de búsqueda de grupos (la de usuarios si no se indicó otra). */
    public function baseGrupos(): string
    {
        return '' !== $this->grupoBaseDn ? $this->grupoBaseDn : $this->baseDn;
    }

    /**
     * Raíz del dominio (los DC= de la base): los miembros de un grupo se buscan
     * en todo el dominio, también fuera de la OU de usuarios sincronizados.
     */
    public function raizDominio(): string
    {
        $partes = array_filter(explode(',', $this->baseDn), static fn (string $p): bool => str_starts_with(strtoupper(trim($p)), 'DC='));

        return [] !== $partes ? implode(',', $partes) : $this->baseDn;
    }

    /** Cambia si cambia la conexión o la búsqueda: un cliente nuevo (y su caché de grupos vacía). */
    public function huella(): string
    {
        return hash('sha256', json_encode([$this->servidores, $this->baseDn, $this->grupoBaseDn, $this->usuarioBind,
            $this->passwordBind, $this->startTls, $this->verificarCertificado, $this->caCertificado, $this->filtroUsuarios,
        ], \JSON_THROW_ON_ERROR));
    }
}
