<?php

declare(strict_types=1);

namespace App\Inventario;

use Doctrine\DBAL\Connection;

/** Quién hizo la operación, para firmar las notificaciones (nombre, rol y correo de respuesta). */
final readonly class Operador
{
    public function __construct(private Connection $conexion)
    {
    }

    /** @return array{nombre: string, rol: string, correo: ?string} */
    public function de(?string $usuarioHex): array
    {
        if (null === $usuarioHex) {
            return ['nombre' => 'Sistema', 'rol' => '', 'correo' => null];
        }
        $f = $this->conexion->fetchAssociative(
            'SELECT u."USU_Username", u."USU_Rol", p."PER_Primer_Nombre", p."PER_Primer_Apellido", p."PER_Email_Corporativo" '
            .'FROM "INV_USUARIO" u LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = u."PER_Persona" WHERE u."USU_Usuario" = :u', ['u' => $usuarioHex]);
        if (false === $f) {
            return ['nombre' => 'Sistema', 'rol' => '', 'correo' => null];
        }
        if (null !== $f['PER_Primer_Nombre'] && '' !== $f['PER_Primer_Nombre']) {
            return ['nombre' => $f['PER_Primer_Nombre'].' '.$f['PER_Primer_Apellido'], 'rol' => (string) $f['USU_Rol'], 'correo' => $f['PER_Email_Corporativo']];
        }

        return ['nombre' => (string) $f['USU_Username'], 'rol' => (string) $f['USU_Rol'], 'correo' => null];
    }

    /**
     * Nombre visible de varias cuentas: "Nombre Apellido" o el usuario.
     *
     * @param list<?string> $ids hex
     *
     * @return array<string, string>
     */
    public function nombres(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ([] === $ids) {
            return [];
        }
        $p = [];
        foreach ($ids as $i => $id) {
            $p["u{$i}"] = $id;
        }
        $filas = $this->conexion->fetchAllAssociative(
            'SELECT u."USU_Usuario", u."USU_Username", p."PER_Primer_Nombre", p."PER_Primer_Apellido" FROM "INV_USUARIO" u '
            .'LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = u."PER_Persona" WHERE u."USU_Usuario" IN (:'.implode(', :', array_keys($p)).')', $p);
        $nombres = [];
        foreach ($filas as $f) {
            $nombres[(string) $f['USU_Usuario']] = null !== $f['PER_Primer_Nombre'] && '' !== $f['PER_Primer_Nombre']
                ? $f['PER_Primer_Nombre'].' '.$f['PER_Primer_Apellido'] : (string) $f['USU_Username'];
        }

        return $nombres;
    }
}
