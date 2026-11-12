<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Catalogo\ClasesActivo;
use App\Dominio\ErrorDominio;
use Doctrine\DBAL\Connection;

/**
 * Lo que admite cada activo según la clase de su tipo (App\Catalogo\ClasesActivo):
 * asignarse a una persona, instalarse en un área, llevar software o componentes.
 */
final readonly class ReglasClase
{
    public function __construct(private Connection $conexion)
    {
    }

    /** @return array{clase: string, uso: string, software: bool, componentes: bool, red: bool, consumibles: bool} */
    public function deTipo(?int $tipo): array
    {
        $clase = null === $tipo ? false : $this->conexion->fetchOne('SELECT "TAC_Clase" FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', ['t' => $tipo]);

        return ClasesActivo::reglas(false === $clase || null === $clase ? null : (string) $clase);
    }

    /**
     * @param array<string, mixed> $activo
     *
     * @return array{clase: string, uso: string, software: bool, componentes: bool, red: bool, consumibles: bool}
     */
    public function deActivo(array $activo): array
    {
        return $this->deTipo((int) $activo['TAC_Tipo_Activo']);
    }

    /** Un insumo (aire comprimido, pasta térmica…) se registra como consumible, no como activo. */
    public function exigirNoInsumo(int $tipo): void
    {
        if (ClasesActivo::INSUMO === $this->deTipo($tipo)['uso']) {
            throw ErrorDominio::noProcesable('ASSET_TYPE_IS_SUPPLY');
        }
    }

    /** @param array<string, mixed> $activo asignar, prestar o transferir a una persona */
    public function exigirAsignableAPersona(array $activo): void
    {
        $uso = $this->deActivo($activo)['uso'];
        if (ClasesActivo::COMPARTIDO === $uso) {
            throw ErrorDominio::noProcesable('ASSET_IS_SHARED_INSTALL_IN_AREA');
        }
        if (ClasesActivo::INSUMO === $uso) {
            throw ErrorDominio::noProcesable('ASSET_TYPE_IS_SUPPLY');
        }
    }

    /** @param array<string, mixed> $activo */
    public function exigirInstalableEnArea(array $activo): void
    {
        $uso = $this->deActivo($activo)['uso'];
        if (ClasesActivo::INSUMO === $uso) {
            throw ErrorDominio::noProcesable('ASSET_TYPE_IS_SUPPLY');
        }
        if (ClasesActivo::COMPONENTE === $uso) {
            throw ErrorDominio::noProcesable('COMPONENT_INSTALLS_IN_EQUIPMENT');
        }
    }

    /** Instalaciones y licencias por equipo solo en equipos que llevan software. */
    public function exigirSoftware(string $activoHex): void
    {
        $tipo = $this->conexion->fetchOne('SELECT "TAC_Tipo_Activo" FROM "INV_ACTIVO" WHERE "ACT_Activo" = :a', ['a' => $activoHex]);
        if (!$this->deTipo(false === $tipo ? null : (int) $tipo)['software']) {
            throw ErrorDominio::noProcesable('ASSET_CLASS_NO_SOFTWARE');
        }
    }

    /**
     * El equipo admite componentes y el componente es de un tipo que admite.
     *
     * @param array<string, mixed> $equipo
     * @param array<string, mixed> $componente
     */
    public function exigirComponenteValido(array $equipo, array $componente): void
    {
        if (!$this->deActivo($equipo)['componentes']) {
            throw ErrorDominio::noProcesable('ASSET_CLASS_NO_COMPONENTS');
        }
        $permitidos = array_map('intval', $this->conexion->fetchFirstColumn(
            'SELECT "TAC_Componente" FROM "INV_TIPO_COMPONENTE" WHERE "TAC_Padre" = :t', ['t' => (int) $equipo['TAC_Tipo_Activo']]));
        $valido = [] !== $permitidos
            ? \in_array((int) $componente['TAC_Tipo_Activo'], $permitidos, true)
            : ClasesActivo::COMPONENTE === $this->deActivo($componente)['uso'];
        if (!$valido) {
            throw ErrorDominio::noProcesable('COMPONENT_TYPE_NOT_ALLOWED');
        }
    }
}
