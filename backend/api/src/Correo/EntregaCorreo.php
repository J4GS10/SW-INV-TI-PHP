<?php

declare(strict_types=1);

namespace App\Correo;

/** Entrega efectiva de un correo ya renderizado. */
interface EntregaCorreo
{
    /**
     * @param list<string> $para
     * @param bool $usarCortacircuitos false solo en pruebas manuales desde la interfaz
     *
     * @throws ErrorEnvioCorreo
     */
    public function entregar(
        ConfigCorreo $config,
        array $para,
        string $asunto,
        string $html,
        ?string $responderA = null,
        bool $usarCortacircuitos = true,
    ): void;
}
