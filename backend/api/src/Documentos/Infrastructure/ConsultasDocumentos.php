<?php

declare(strict_types=1);

namespace App\Documentos\Infrastructure;

use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/** What labels, handover acts and CSV reports read from the database, within the current user's sites. */
final readonly class ConsultasDocumentos
{
    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    /**
     * Assets to label, chosen by id or by internal code, that the user may see; ordered by internal code.
     *
     * @param list<string> $activos
     * @param list<string> $codigos
     *
     * @return list<array<string, mixed>> ACT_Codigo_Interno, ACT_Serie_Fabricante, ACT_Hostname
     */
    public function activosParaEtiquetas(array $activos, array $codigos): array
    {
        $condiciones = [];
        $parametros = [];
        foreach (['ACT_Activo' => $activos, 'ACT_Codigo_Interno' => $codigos] as $columna => $valores) {
            if ([] === $valores) {
                continue;
            }
            $nombres = [];
            foreach ($valores as $i => $valor) {
                $nombres[] = ":{$columna}_{$i}";
                $parametros["{$columna}_{$i}"] = $valor;
            }
            $condiciones[] = "a.\"{$columna}\" IN (".implode(', ', $nombres).')';
        }
        if ([] === $condiciones) {
            return [];
        }
        [$alcance, $deAlcance] = $this->alcance->lectura('INV_ACTIVO', 'a');

        return $this->conexion->fetchAllAssociative('SELECT a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante", a."ACT_Hostname" FROM "INV_ACTIVO" a '
            .'WHERE ('.implode(' OR ', $condiciones).') AND '.$alcance.' ORDER BY a."ACT_Codigo_Interno"', [...$parametros, ...$deAlcance]);
    }

    /** @return array<string, mixed> SYS_Nombre_Empresa, SYS_Logo_URL, SYS_Ciudad, SYS_Codigo_Formulario; empty when not configured */
    public function datosDeLaEmpresa(): array
    {
        return $this->conexion->fetchAssociative('SELECT "SYS_Nombre_Empresa", "SYS_Logo_URL", "SYS_Ciudad", "SYS_Codigo_Formulario" '
            .'FROM "SYS_CONFIGURACION" WHERE "SYS_Configuracion" = 1') ?: [];
    }

    /** @return array<string, mixed>|null a custody movement with its person and asset, if the user may see it */
    public function movimientoParaActa(string $movimiento): ?array
    {
        [$alcance, $deAlcance] = $this->alcance->lectura('INV_MOVIMIENTO', 'm');
        $fila = $this->conexion->fetchAssociative('SELECT m."MOV_Movimiento", m."PER_Persona", m."MOV_Fecha_Asignacion", m."MOV_Fecha_Devolucion", '
            .'m."MOV_Observacion", p."PER_Primer_Nombre", p."PER_Primer_Apellido", a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante", a."ACT_Hostname", '
            .'t."TAC_Nombre", mo."MOD_Nombre", ma."MAR_Nombre" FROM "INV_MOVIMIENTO" m JOIN "INV_PERSONA" p ON p."PER_Persona" = m."PER_Persona" '
            .'LEFT JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" LEFT JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" '
            .'LEFT JOIN "INV_MODELO" mo ON mo."MOD_Modelo" = a."MOD_Modelo" LEFT JOIN "INV_MARCA" ma ON ma."MAR_Marca" = mo."MAR_Marca" '
            .'WHERE m."MOV_Movimiento" = :id AND '.$alcance, [...$deAlcance, 'id' => $movimiento]);

        return false === $fila ? null : $fila;
    }

    /** @return array<int|string, mixed> site id => name */
    public function nombresDeSedes(): array
    {
        return $this->conexion->fetchAllKeyValue('SELECT "SED_Sede", "SED_Nombre" FROM "INV_SEDE"');
    }

    /** @return list<array<string, mixed>> every licence with its software and licence type, ordered by software */
    public function licencias(): array
    {
        return $this->conexion->fetchAllAssociative('SELECT s."SOF_Nombre", s."SOF_Version", s."SOF_Fabricante", t."TLI_Nombre", l."LIC_Cantidad_Total", '
            .'l."LIC_Cantidad_Usada", l."LIC_Fecha_Vencimiento", l."LIC_Costo", l."LIC_Moneda" FROM "INV_LICENCIA" l '
            .'JOIN "INV_SOFTWARE" s ON s."SOF_Software" = l."SOF_Software" LEFT JOIN "INV_TIPO_LICENCIA" t ON t."TLI_Tipo_Licencia" = l."TLI_Tipo_Licencia" '
            .'ORDER BY s."SOF_Nombre", l."LIC_Licencia"');
    }
}
