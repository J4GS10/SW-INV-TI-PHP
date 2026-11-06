<?php

declare(strict_types=1);

namespace App\Tareas;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ejecuta una tarea una vez, con las mismas reglas que el worker (cerrojo
 * incluido). Para operación y pruebas:
 *
 *   php bin/console app:tareas:ejecutar purge_security
 *
 * Imprime {"ejecutada": bool, "resultado": …, "error": …} en JSON.
 */
#[AsCommand('app:tareas:ejecutar', 'Ejecuta una tarea en segundo plano una vez (con su cerrojo).')]
final readonly class EjecutarTareaComando
{
    public function __construct(private EjecutorTareas $ejecutor)
    {
    }

    public function __invoke(OutputInterface $salida, #[Argument('purge_security, security_housekeeping, ad_sync o email_outbox')] string $tarea): int
    {
        if (!isset($this->ejecutor->tareas()[$tarea])) {
            $salida->writeln('Tareas: '.implode(', ', array_keys($this->ejecutor->tareas())));

            return Command::INVALID;
        }
        $resultado = $this->ejecutor->ejecutar($tarea);
        $salida->writeln((string) json_encode($resultado, \JSON_UNESCAPED_UNICODE));

        return null === $resultado['error'] ? Command::SUCCESS : Command::FAILURE;
    }
}
