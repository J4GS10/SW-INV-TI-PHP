<?php

declare(strict_types=1);

namespace App\Esquema;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Huella del esquema de la conexión (solo lectura). Dos bases con el mismo
 * esquema dan las mismas líneas y el mismo resumen:
 *
 *   php bin/console app:esquema:huella             # una línea por hecho del diccionario
 *   php bin/console app:esquema:huella --resumen   # solo el SHA-256 de esas líneas
 */
#[AsCommand('app:esquema:huella', 'Imprime la descripción normalizada del esquema (para comparar bases).')]
final readonly class HuellaEsquemaComando
{
    public function __construct(private HuellaEsquema $huella)
    {
    }

    public function __invoke(OutputInterface $salida, #[Option('Solo el resumen SHA-256')] bool $resumen = false): int
    {
        if ($resumen) {
            $salida->writeln($this->huella->resumen());
        } else {
            foreach ($this->huella->lineas() as $linea) {
                $salida->writeln($linea, OutputInterface::OUTPUT_RAW);
            }
        }

        return Command::SUCCESS;
    }
}
