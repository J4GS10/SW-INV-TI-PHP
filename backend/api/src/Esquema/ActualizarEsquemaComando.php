<?php

declare(strict_types=1);

namespace App\Esquema;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Migrador del despliegue (servicio `migrador` del compose; también lo usan las
 * pruebas sobre una base vacía):
 *
 *   php bin/console app:esquema:actualizar               # migrar + seguridad + permisos + datos mínimos
 *   php bin/console app:esquema:actualizar --sin-datos   # sin cuenta raíz, catálogo ni huellas (base de pruebas)
 *   php bin/console app:esquema:actualizar --verificar   # solo informa diferencias (no cambia nada)
 *
 * Debe conectarse como el dueño del esquema (ORACLE_USER = ORACLE_SCHEMA_OWNER).
 * El usuario de ejecución sale de ORACLE_APP_USER; la cuenta raíz, de
 * SUPER_ADMIN_USERNAME / SUPER_ADMIN_EMAIL / SUPER_ADMIN_PASSWORD.
 */
#[AsCommand('app:esquema:actualizar', 'Aplica las migraciones, la capa de seguridad, los permisos y los datos mínimos.')]
final readonly class ActualizarEsquemaComando
{
    public function __construct(private MigradorEsquema $migrador)
    {
    }

    public function __invoke(
        OutputInterface $salida,
        #[Option('Solo verifica: informa lo que falta o difiere, sin cambiar nada')] bool $verificar = false,
        #[Option('No crea la cuenta raíz ni el catálogo mínimo ni recalcula huellas')] bool $sinDatos = false,
    ): int {
        if ($verificar) {
            $faltas = $this->migrador->verificar();
            foreach ($faltas as $f) {
                $salida->writeln("  - {$f}");
            }
            $salida->writeln([] === $faltas ? 'Esquema al día: migraciones, VPD, disparadores y kardex completos.' : 'El esquema no está al día.');

            return [] === $faltas ? Command::SUCCESS : Command::FAILURE;
        }

        $versiones = $this->migrador->migrar();
        $salida->writeln([] === $versiones ? '==> Migraciones: ninguna pendiente.' : '==> Migraciones aplicadas: '.implode(', ', $versiones));
        $this->informar($salida, 'Capa de seguridad (VPD, disparadores, kardex)', $this->migrador->instalarSeguridad());

        $usuarioEjecucion = self::entorno('ORACLE_APP_USER');
        if (null !== $usuarioEjecucion) {
            $this->informar($salida, "Permisos de {$usuarioEjecucion}", $this->migrador->concederPermisos($usuarioEjecucion));
        } else {
            $salida->writeln('==> Permisos: ORACLE_APP_USER no definido (la API usa el usuario del dueño).');
        }

        if (!$sinDatos) {
            $datos = $this->migrador->datosMinimos(
                self::entorno('SUPER_ADMIN_USERNAME') ?? 'sa',
                self::entorno('SUPER_ADMIN_EMAIL') ?? 'admin@example.com',
                self::entorno('SUPER_ADMIN_PASSWORD'),
                self::entorno('COMPANY_NAME') ?? 'Mi Empresa TI',
            );
            $cuenta = $datos['cuenta'];
            $salida->writeln('==> Cuenta raíz: '.($cuenta['creada'] ? 'creada' : 'ya existía (sin cambios)'));
            if (null !== $cuenta['contrasena_generada']) {
                $salida->writeln("    SUPER_ADMIN_PASSWORD no definida: contraseña temporal {$cuenta['contrasena_generada']} (se pide cambiarla al entrar).");
            }
            $salida->writeln("==> Catálogo mínimo: {$datos['semilla']} filas nuevas.");
            $h = $datos['huellas'];
            $salida->writeln("==> Huellas de licencias: {$h['total']} revisadas, {$h['actualizadas']} actualizadas, {$h['errores']} sin descifrar.");
            if ([] !== $h['conflictos']) {
                $salida->writeln('    Credenciales con la misma huella (revisar a mano): '.implode(', ', $h['conflictos']));
            }
        }

        $faltas = $this->migrador->verificar();
        if ([] !== $faltas) {
            $salida->writeln('El esquema no quedó completo: '.implode('; ', $faltas));

            return Command::FAILURE;
        }
        $salida->writeln('==> Esquema al día.');

        return Command::SUCCESS;
    }

    /** @param list<string> $cambios */
    private function informar(OutputInterface $salida, string $paso, array $cambios): void
    {
        $salida->writeln("==> {$paso}: ".([] === $cambios ? 'sin cambios.' : \count($cambios).' cambios.'));
        foreach ($cambios as $c) {
            $salida->writeln("    - {$c}");
        }
    }

    private static function entorno(string $nombre): ?string
    {
        $valor = $_SERVER[$nombre] ?? $_ENV[$nombre] ?? getenv($nombre);

        return \is_string($valor) && '' !== trim($valor) ? $valor : null;
    }
}
