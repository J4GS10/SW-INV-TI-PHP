<?php

declare(strict_types=1);

namespace App\Directorio;

use App\Correo\Cortacircuitos;
use App\Integracion\ConfigAd;
use App\Integracion\FuenteIntegracion;
use Psr\Log\LoggerInterface;

/**
 * Cliente del directorio según la configuración efectiva. Un cambio de
 * servidor, cuenta o filtro crea un cliente nuevo (y vacía la caché de grupos).
 */
final class FabricaDirectorio
{
    private ?DirectorioLdap $cliente = null;
    private ?string $huella = null;
    private ?Directorio $sustituto = null;

    public function __construct(
        private readonly FuenteIntegracion $fuente,
        private readonly Cortacircuitos $cortacircuitos,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** El directorio activo, o null si el AD no está habilitado y configurado. */
    public function activo(): ?Directorio
    {
        if (null !== $this->sustituto) {
            return $this->sustituto;
        }
        $config = $this->fuente->obtener()->ad;
        if (!$config->lista()) {
            return null;
        }
        if (null === $this->cliente || $this->huella !== $config->huella()) {
            $this->cliente = new DirectorioLdap($config, $this->cortacircuitos, $this->logger);
            $this->huella = $config->huella();
        } else {
            $this->cliente->actualizar($config);
        }

        return $this->cliente;
    }

    /** Cliente para probar valores del formulario: sin cortacircuitos ni caché compartida. */
    public function paraPrueba(ConfigAd $config): Directorio
    {
        return new DirectorioLdap($config, null, $this->logger);
    }

    /** Pruebas: inyecta un directorio (null restaura el comportamiento normal). */
    public function sustituir(?Directorio $directorio): void
    {
        $this->sustituto = $directorio;
    }
}
