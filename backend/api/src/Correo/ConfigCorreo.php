<?php

declare(strict_types=1);

namespace App\Correo;

/** Configuración efectiva de correo (SMTP o Microsoft Graph). */
final readonly class ConfigCorreo
{
    public function __construct(
        public bool $habilitado,
        public string $proveedor = 'SMTP',
        public ?string $host = null,
        public int $puerto = 587,
        public string $seguridad = 'STARTTLS',
        public ?string $usuario = null,
        public ?string $password = null,
        public ?string $remitente = null,
        public string $nombreRemitente = 'Sistema Inventario',
        public ?string $graphTenant = null,
        public ?string $graphCliente = null,
        public ?string $graphSecreto = null,
        public float $timeout = 10.0,
        /** 'interfaz' (SYS_INTEGRACION_CORREO) o 'entorno' (SMTP_*). */
        public string $fuente = 'entorno',
    ) {
    }

    public function lista(): bool
    {
        if (!$this->habilitado) {
            return false;
        }
        if ('GRAPH' === $this->proveedor) {
            return null !== $this->graphTenant && null !== $this->graphCliente
                && null !== $this->graphSecreto && null !== $this->remitente;
        }

        return null !== $this->host;
    }
}
