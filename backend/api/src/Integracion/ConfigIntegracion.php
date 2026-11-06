<?php

declare(strict_types=1);

namespace App\Integracion;

use App\Correo\ConfigCorreo;

/** Configuración efectiva de la cuenta de servicio: modo, correo y Active Directory. */
final readonly class ConfigIntegracion
{
    public const string FUENTE_INTERFAZ = 'interfaz';
    public const string FUENTE_ENTORNO = 'entorno';
    public const string FUENTE_PRUEBA = 'prueba';

    public function __construct(
        public string $fuente,
        public ModoIntegracion $modo,
        public ConfigCorreo $correo,
        public ConfigAd $ad,
    ) {
    }
}
