<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Dominio\Reloj;
use Doctrine\DBAL\Connection;

/** Instalaciones de licencias (INV_INSTALACION) que se liberan al salir el equipo o la persona. */
final readonly class Instalaciones
{
    public function __construct(private Connection $conexion)
    {
    }

    /**
     * Libera las instalaciones activas de esos equipos o de esa persona: devuelve
     * cupos (salvo las licencias por sitio) y claves, y lista lo liberado para el kardex.
     *
     * @param list<string> $activos hex
     *
     * @return list<array{licencia: int, activo: ?string, persona: ?string, clave: ?int}>
     */
    public function liberarPorDestino(array $activos = [], ?string $persona = null, ?string $motivo = null): array
    {
        $condiciones = [];
        $p = [];
        if ([] !== $activos) {
            $nombres = [];
            foreach ($activos as $i => $a) {
                $nombres[] = ":a{$i}";
                $p["a{$i}"] = $a;
            }
            $condiciones[] = '"ACT_Activo" IN ('.implode(', ', $nombres).')';
        }
        if (null !== $persona) {
            $condiciones[] = '"PER_Persona" = :persona';
            $p['persona'] = $persona;
        }
        if ([] === $condiciones) {
            return [];
        }
        $filas = $this->conexion->fetchAllAssociative(
            'SELECT "INS_Instalacion", "LIC_Licencia", "ACT_Activo", "PER_Persona", "LCL_Licencia_Clave", "INS_Consume_Cupo" '
            .'FROM "INV_INSTALACION" WHERE ('.implode(' OR ', $condiciones).') AND "INS_Estado" = 1 ORDER BY "INS_Instalacion" FOR UPDATE', $p);
        if ([] === $filas) {
            return [];
        }
        $ahora = Reloj::aBd(Reloj::ahora());
        $porLicencia = [];
        foreach ($filas as $f) {
            $this->conexion->executeStatement(
                'UPDATE "INV_INSTALACION" SET "INS_Estado" = 0, "INS_Fecha_Retiro" = :ahora, "INS_Motivo_Retiro" = :motivo '
                .'WHERE "INS_Instalacion" = :id AND "INS_Estado" = 1',
                ['ahora' => $ahora, 'motivo' => null === $motivo || '' === $motivo ? null : mb_substr($motivo, 0, 255), 'id' => (int) $f['INS_Instalacion']]);
            if (1 === (int) $f['INS_Consume_Cupo']) {
                $porLicencia[(int) $f['LIC_Licencia']] = ($porLicencia[(int) $f['LIC_Licencia']] ?? 0) + 1;
            }
            if (null !== $f['LCL_Licencia_Clave']) {
                $this->conexion->executeStatement(
                    "UPDATE \"INV_LICENCIA_CLAVE\" SET \"LCL_Estado\" = 'DISPONIBLE' WHERE \"LCL_Licencia_Clave\" = :c AND \"LCL_Estado\" = 'ASIGNADA'",
                    ['c' => (int) $f['LCL_Licencia_Clave']]);
            }
        }
        foreach ($porLicencia as $licencia => $cantidad) {
            $this->conexion->executeStatement(
                'UPDATE "INV_LICENCIA" SET "LIC_Cantidad_Usada" = "LIC_Cantidad_Usada" - :n WHERE "LIC_Licencia" = :l AND "LIC_Cantidad_Usada" >= :n',
                ['n' => $cantidad, 'l' => $licencia]);
        }

        return array_map(static fn (array $f): array => [
            'licencia' => (int) $f['LIC_Licencia'],
            'activo' => null === $f['ACT_Activo'] ? null : (string) $f['ACT_Activo'],
            'persona' => null === $f['PER_Persona'] ? null : (string) $f['PER_Persona'],
            'clave' => null === $f['LCL_Licencia_Clave'] ? null : (int) $f['LCL_Licencia_Clave'],
        ], $filas);
    }
}
