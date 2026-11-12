<?php

declare(strict_types=1);

namespace App\Repositorio;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Small look-ups that several business rules need (the name of a state, the class of a type, the
 * company name…). Read only; the rules that use the answers stay in the business layer.
 */
final readonly class ConsultasComunes
{
    public function __construct(private Connection $conexion)
    {
    }

    // ---------------- Estados operativos ----------------

    public function nombreDeEstado(int $id): ?string
    {
        $nombre = $this->conexion->fetchOne('SELECT "EOP_Nombre" FROM "INV_ESTADO_OPERATIVO" WHERE "EOP_Estado_Operativo" = :id', ['id' => $id]);

        return false === $nombre ? null : (string) $nombre;
    }

    /** The state with that name, without case; the oldest one if the catalogue repeats it. */
    public function idDeEstado(string $nombre): ?int
    {
        $id = $this->conexion->fetchOne('SELECT "EOP_Estado_Operativo" FROM "INV_ESTADO_OPERATIVO" WHERE LOWER("EOP_Nombre") = LOWER(:n) '
            .'ORDER BY "EOP_Estado_Operativo" FETCH FIRST 1 ROWS ONLY', ['n' => $nombre]);

        return false === $id ? null : (int) $id;
    }

    // ---------------- Tipos de activo ----------------

    /** The class (COMPUTO, PERIFERICO…) stored for an asset type; null when the type has none or does not exist. */
    public function claseDeTipo(int $tipo): ?string
    {
        $clase = $this->conexion->fetchOne('SELECT "TAC_Clase" FROM "INV_TIPO_ACTIVO" WHERE "TAC_Tipo_Activo" = :t', ['t' => $tipo]);

        return false === $clase || null === $clase ? null : (string) $clase;
    }

    public function tipoDeActivo(string $activo): ?int
    {
        $tipo = $this->conexion->fetchOne('SELECT "TAC_Tipo_Activo" FROM "INV_ACTIVO" WHERE "ACT_Activo" = :a', ['a' => $activo]);

        return false === $tipo ? null : (int) $tipo;
    }

    /** @return list<int> the component types an equipment type explicitly admits; empty when it lists none */
    public function componentesAdmitidos(int $tipoDeEquipo): array
    {
        return array_map('intval', $this->conexion->fetchFirstColumn('SELECT "TAC_Componente" FROM "INV_TIPO_COMPONENTE" WHERE "TAC_Padre" = :t', ['t' => $tipoDeEquipo]));
    }

    public function nombreDeModelo(mixed $modelo): ?string
    {
        $nombre = $this->conexion->fetchOne('SELECT "MOD_Nombre" FROM "INV_MODELO" WHERE "MOD_Modelo" = :m', ['m' => $modelo]);

        return false === $nombre || null === $nombre ? null : (string) $nombre;
    }

    // ---------------- Alcance por sede ----------------

    /** @return array<string, string> "TABLA|OPERACION" => the scope predicate the VPD policies apply */
    public function predicadosDeAlcance(): array
    {
        $predicados = [];
        foreach ($this->conexion->fetchAllAssociative('SELECT "VPD_Tabla", "VPD_Operacion", "VPD_Predicado" FROM "INV_VPD_PREDICADO"') as $f) {
            $predicados[$f['VPD_Tabla'].'|'.$f['VPD_Operacion']] = (string) $f['VPD_Predicado'];
        }

        return $predicados;
    }

    // ---------------- Configuración, cuentas y personas ----------------

    public function nombreDeEmpresa(): string
    {
        return (string) ($this->conexion->fetchOne('SELECT "SYS_Nombre_Empresa" FROM "SYS_CONFIGURACION" WHERE "SYS_Configuracion" = 1') ?: '');
    }

    /** The logo uploaded to the system, as stored (image bytes); null when there is none. */
    public function logoGuardado(): ?string
    {
        $bytes = $this->conexion->fetchOne('SELECT "SYS_Logo_Image" FROM "SYS_CONFIGURACION" WHERE "SYS_Configuracion" = 1');

        return \is_string($bytes) ? $bytes : null;
    }

    public function nombreDeUsuario(mixed $usuario): string
    {
        return null === $usuario ? '' : (string) $this->conexion->fetchOne('SELECT "USU_Username" FROM "INV_USUARIO" WHERE "USU_Usuario" = :u', ['u' => $usuario]);
    }

    public function existePersona(string $persona): bool
    {
        return false !== $this->conexion->fetchOne('SELECT 1 FROM "INV_PERSONA" WHERE "PER_Persona" = :p', ['p' => $persona]);
    }

    /** @return string|false the person's corporate e-mail; false when there is no such person */
    public function correoDePersona(string $persona): string|false
    {
        $correo = $this->conexion->fetchOne('SELECT "PER_Email_Corporativo" FROM "INV_PERSONA" WHERE "PER_Persona" = :p', ['p' => $persona]);

        return false === $correo ? false : (string) $correo;
    }

    /**
     * @param list<string> $correos lower-case corporate e-mails
     *
     * @return list<string> the e-mails of the active managers of those people
     */
    public function correosDeJefes(array $correos): array
    {
        if ([] === $correos) {
            return [];
        }

        return array_map(strval(...), $this->conexion->fetchFirstColumn(
            'SELECT j."PER_Email_Corporativo" FROM "INV_PERSONA" p JOIN "INV_PERSONA" j ON j."PER_Persona" = p."PER_Jefe" '
            .'WHERE LOWER(p."PER_Email_Corporativo") IN (:correos) AND j."PER_Estado" = 1',
            ['correos' => $correos],
            ['correos' => ArrayParameterType::STRING],
        ));
    }
}
