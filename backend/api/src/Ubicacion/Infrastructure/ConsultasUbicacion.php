<?php

declare(strict_types=1);

namespace App\Ubicacion\Infrastructure;

use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/** Read-side queries about places, within the sites the current user may see. */
final readonly class ConsultasUbicacion
{
    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    /**
     * Every visible area with what is needed to show where it is (site, building, floor), ordered by name.
     *
     * @return list<array<string, mixed>> ARE_Area, ARE_Nombre, ARE_Tipo_Acceso, TAR_Tipo_Area, TAR_Codigo, NIV_Nivel, SED_Sede, SED_Nombre, EDI_Nombre, NIV_Numero_Piso
     */
    public function areasConSuRuta(): array
    {
        [$filtro, $parametros] = $this->alcance->filtroSede('e."SED_Sede"');

        return $this->conexion->fetchAllAssociative(
            'SELECT a."ARE_Area", a."ARE_Nombre", a."ARE_Tipo_Acceso", a."TAR_Tipo_Area", ta."TAR_Codigo", a."NIV_Nivel", '
            .'e."SED_Sede", s."SED_Nombre", e."EDI_Nombre", n."NIV_Numero_Piso" '
            .'FROM "INV_AREA" a JOIN "INV_NIVEL" n ON n."NIV_Nivel" = a."NIV_Nivel" '
            .'JOIN "INV_EDIFICIO" e ON e."EDI_Edificio" = n."EDI_Edificio" JOIN "INV_SEDE" s ON s."SED_Sede" = e."SED_Sede" '
            .'LEFT JOIN "INV_TIPO_AREA" ta ON ta."TAR_Tipo_Area" = a."TAR_Tipo_Area" '
            .'WHERE '.$filtro.' ORDER BY a."ARE_Nombre", a."ARE_Area"',
            $parametros,
        );
    }
}
