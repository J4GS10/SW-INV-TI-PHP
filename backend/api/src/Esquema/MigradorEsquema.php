<?php

declare(strict_types=1);

namespace App\Esquema;

use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ciclo de vida del esquema (migrador de un solo uso, fail-closed), conectado
 * como DUEÑO del esquema:
 *
 * 1. Migraciones de Doctrine pendientes (la primera es la línea base).
 * 2. Capa de seguridad: VPD, disparadores e integridad del kardex (convergente).
 * 3. Permisos del usuario de ejecución de la API (solo DML).
 * 4. Datos mínimos: cuenta raíz, catálogo canónico y huellas de licencias.
 *
 * Todo es idempotente: sobre una base al día no cambia nada.
 */
final readonly class MigradorEsquema
{
    public function __construct(
        #[Autowire(service: 'doctrine.migrations.dependency_factory')]
        private DependencyFactory $migraciones,
        private CapaSeguridad $seguridad,
        private PermisosEjecucion $permisos,
        private CuentaInicial $cuenta,
        private SemillaMinima $semilla,
        private RecalculoHuellas $huellas,
    ) {
    }

    /** @return list<string> versiones aplicadas (la línea base sobre una base existente se registra sin SQL) */
    public function migrar(): array
    {
        $this->migraciones->getMetadataStorage()->ensureInitialized();
        $ultima = $this->migraciones->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan = $this->migraciones->getMigrationPlanCalculator()->getPlanUntilVersion($ultima);
        if (0 === \count($plan)) {
            return [];
        }
        $this->migraciones->getMigrator()->migrate($plan, (new MigratorConfiguration())->setAllOrNothing(false));

        return array_map(static fn ($item): string => (string) $item->getVersion(), $plan->getItems());
    }

    /** @return list<string> migraciones disponibles que aún no se aplicaron (sin crear nada) */
    public function pendientes(): array
    {
        // Sin la tabla de registro (base sin migrar) Doctrine las da todas por pendientes.
        return array_map(static fn ($m): string => (string) $m->getVersion(),
            $this->migraciones->getMigrationStatusCalculator()->getNewMigrations()->getItems());
    }

    /** @return list<string> */
    public function instalarSeguridad(): array
    {
        return $this->seguridad->instalar();
    }

    /** @return list<string> */
    public function concederPermisos(string $usuarioEjecucion): array
    {
        return $this->permisos->conceder($usuarioEjecucion);
    }

    /**
     * @return array{cuenta: array{creada: bool, contrasena_generada: ?string}, semilla: int,
     *     huellas: array{total: int, actualizadas: int, conflictos: list<int>, errores: int}}
     */
    public function datosMinimos(string $usuario, string $correo, ?string $contrasena, string $empresa): array
    {
        return [
            'cuenta' => $this->cuenta->asegurar($usuario, $correo, $contrasena, $empresa),
            'semilla' => $this->semilla->sembrar(),
            'huellas' => $this->huellas->recalcular(),
        ];
    }

    /** @return list<string> vacía si el esquema está completo */
    public function verificar(): array
    {
        return [...array_map(static fn (string $v): string => "migración pendiente {$v}", $this->pendientes()), ...$this->seguridad->verificar()];
    }
}
