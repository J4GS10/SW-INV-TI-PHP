<?php

declare(strict_types=1);

namespace App\Consola;

use App\Dominio\ErrorDominio;
use App\Organizacion\AccesoDeEmergencia;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Break-glass access to a SUPER_ADMIN account from the server (docs/MANUAL_OPERACION.md, «Acceso de emergencia»):
 *
 *   docker exec -it inventario-api_php-1 php bin/console app:acceso-emergencia <usuario> \
 *       --confirmar=<usuario> --motivo="<por qué>" --operador="<quién>" [--contrasena-temporal]
 */
#[AsCommand('app:acceso-emergencia', 'Recupera una cuenta SUPER_ADMIN sin entrar a la aplicación: quita el MFA, las sesiones y los bloqueos (queda auditado).')]
final readonly class AccesoEmergenciaComando
{
    public function __construct(private AccesoDeEmergencia $acceso)
    {
    }

    public function __invoke(
        OutputInterface $salida,
        #[Argument('Usuario SUPER_ADMIN que se recupera')] string $usuario,
        #[Option('El mismo usuario otra vez, para confirmar')] string $confirmar = '',
        #[Option('Por qué se usa el acceso de emergencia (mínimo 10 caracteres; queda en la auditoría)')] string $motivo = '',
        #[Option('Quién lo ejecuta (queda en la auditoría)')] string $operador = '',
        #[Option('Además emite una contraseña temporal, que se muestra una sola vez')] bool $contrasenaTemporal = false,
    ): int {
        if ('' === $usuario || $confirmar !== $usuario) {
            $salida->writeln('<error>Confirme repitiendo el usuario en --confirmar.</error>');

            return Command::INVALID;
        }
        try {
            $temporal = $this->acceso->restablecer($usuario, $motivo, $operador, $contrasenaTemporal);
        } catch (ErrorDominio $e) {
            $salida->writeln("<error>{$e->detalle}</error>");

            return Command::FAILURE;
        }

        $salida->writeln("Cuenta {$usuario} recuperada: sin MFA, sin sesiones, sin equipos recordados y sin bloqueos.");
        $salida->writeln('En el próximo ingreso se pide configurar el MFA de nuevo. Quedó registrado en la auditoría (EMERGENCY_ACCESS_RESET).');
        if (null !== $temporal) {
            $salida->writeln("Contraseña temporal (se pide cambiarla al entrar; no se vuelve a mostrar): {$temporal}");
        }

        return Command::SUCCESS;
    }
}
