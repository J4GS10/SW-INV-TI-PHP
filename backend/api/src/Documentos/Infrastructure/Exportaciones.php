<?php

declare(strict_types=1);

namespace App\Documentos\Infrastructure;

use App\Documentos\Traducciones;
use App\Documentos\JsonLegible;
use App\Documentos\Idioma;
use App\Documentos\FormatoLocal;
use App\Documentos\ArchivoCsv;
use App\Dominio\ErrorDominio;
use App\Dominio\Ident;
use App\Repositorio\BitacoraRepositorio;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/**
 * Exportaciones CSV de /export: activos, movimientos, consumibles, proveedores,
 * y bitácora. Encabezados legibles en ES/EN/IT, todas las
 * celdas entre comillas y respetando el alcance por sede del usuario.
 */
final readonly class Exportaciones
{
    private const array CABECERAS = [
        'activos' => [
            'es' => ['Código interno', 'Número de serie', 'Hostname', 'Tipo de activo', 'Marca', 'Modelo', 'Estado operativo', 'Fecha de compra',
                'Vencimiento de garantía', 'Costo', 'Moneda'],
            'en' => ['Internal code', 'Serial number', 'Hostname', 'Asset type', 'Brand', 'Model', 'Operational status', 'Purchase date',
                'Warranty expiration', 'Cost', 'Currency'],
            'it' => ['Codice interno', 'Numero di serie', 'Hostname', 'Tipo di asset', 'Marca', 'Modello', 'Stato operativo', 'Data di acquisto',
                'Scadenza garanzia', 'Costo', 'Valuta'],
        ],
        'movimientos' => [
            'es' => ['Fecha de asignación', 'Fecha de devolución', 'Código del activo', 'Número de serie', 'Colaborador', 'Correo electrónico', 'Área',
                'Tipo de movimiento', 'Observaciones'],
            'en' => ['Assignment date', 'Return date', 'Asset code', 'Serial number', 'Employee', 'Email', 'Area', 'Movement type', 'Notes'],
            'it' => ['Data di assegnazione', 'Data di restituzione', 'Codice asset', 'Numero di serie', 'Collaboratore', 'Email', 'Area',
                'Tipo di movimento', 'Note'],
        ],
        'consumibles' => [
            'es' => ['Nombre', 'Categoría', 'Unidad de medida', 'Existencia actual', 'Existencia mínima', 'Bajo el mínimo', 'Activo'],
            'en' => ['Name', 'Category', 'Unit of measure', 'Current stock', 'Minimum stock', 'Below minimum', 'Active'],
            'it' => ['Nome', 'Categoria', 'Unità di misura', 'Giacenza attuale', 'Giacenza minima', 'Sotto la scorta minima', 'Attivo'],
        ],
        'proveedores' => [
            'es' => ['Nombre', 'Identificación fiscal', 'Contacto', 'Correo electrónico', 'Teléfono', 'Dirección', 'Activo'],
            'en' => ['Name', 'Tax ID', 'Contact', 'Email', 'Phone', 'Address', 'Active'],
            'it' => ['Nome', 'Identificativo fiscale', 'Referente', 'Email', 'Telefono', 'Indirizzo', 'Attivo'],
        ],
        'mantenimientos' => [
            'es' => ['Código del activo', 'Número de serie', 'Tipo', 'Falla', 'Ingreso', 'Cierre'],
            'en' => ['Asset code', 'Serial number', 'Type', 'Fault', 'Opened', 'Closed'],
            'it' => ['Codice asset', 'Numero di serie', 'Tipo', 'Guasto', 'Apertura', 'Chiusura'],
        ],
        'personas' => [
            'es' => ['Nombre', 'Apellido', 'Correo electrónico', 'Departamento', 'Cargo', 'Activo'],
            'en' => ['First name', 'Last name', 'Email', 'Department', 'Position', 'Active'],
            'it' => ['Nome', 'Cognome', 'Email', 'Reparto', 'Ruolo', 'Attivo'],
        ],
        'garantias' => [
            'es' => ['Código del activo', 'Número de serie', 'Tipo de activo', 'Marca', 'Modelo', 'Vencimiento de garantía'],
            'en' => ['Asset code', 'Serial number', 'Asset type', 'Brand', 'Model', 'Warranty expiration'],
            'it' => ['Codice asset', 'Numero di serie', 'Tipo di asset', 'Marca', 'Modello', 'Scadenza garanzia'],
        ],
        'auditoria' => [
            'es' => ['Fecha y hora', 'Acción', 'Entidad afectada', 'IP de origen', 'Agente de usuario', 'ID de usuario', 'Detalle (JSON)'],
            'en' => ['Date and time', 'Action', 'Affected entity', 'Source IP', 'User agent', 'User ID', 'Details (JSON)'],
            'it' => ['Data e ora', 'Azione', 'Entità interessata', 'IP di origine', 'User agent', 'ID utente', 'Dettagli (JSON)'],
        ],
    ];

    /** Nombre base del archivo descargado. */
    public const array ARCHIVOS = [
        'activos' => ['es' => 'activos', 'en' => 'assets', 'it' => 'asset'],
        'movimientos' => ['es' => 'movimientos', 'en' => 'movements', 'it' => 'movimenti'],
        'consumibles' => ['es' => 'consumibles', 'en' => 'consumables', 'it' => 'materiali_di_consumo'],
        'proveedores' => ['es' => 'proveedores', 'en' => 'suppliers', 'it' => 'fornitori'],
        'mantenimientos' => ['es' => 'mantenimientos', 'en' => 'maintenance', 'it' => 'manutenzioni'],
        'personas' => ['es' => 'personas', 'en' => 'people', 'it' => 'persone'],
        'garantias' => ['es' => 'garantias', 'en' => 'warranties', 'it' => 'garanzie'],
        'auditoria' => ['es' => 'auditoria', 'en' => 'audit_log', 'it' => 'registro_audit'],
    ];

    public const int MAX_ACTIVOS = 10000;

    /** Nunca un archivo incompleto sin avisar: sobre el límite se pide acotar con filtros. */
    public static function exigirTamano(int $total): void
    {
        if ($total > self::MAX_ACTIVOS) {
            throw ErrorDominio::noProcesable('EXPORT_TOO_LARGE_USE_FILTERS:'.$total);
        }
    }

    public function __construct(
        private Connection $conexion,
        private Alcance $alcance,
        private BitacoraRepositorio $bitacora,
        private FormatoLocal $formato,
    ) {
    }

    /** @param array<string, mixed> $filtros los mismos del listado de activos */
    public function activos(Idioma $idioma, array $filtros = []): string
    {
        [$alcance, $p] = $this->alcance->lectura('INV_ACTIVO', 'a');
        if (null !== ($filtros['q'] ?? null) && '' !== $filtros['q']) {
            $p['q'] = '%'.addcslashes(mb_strtolower((string) $filtros['q']), '\%_').'%';
            $alcance .= " AND (LOWER(a.\"ACT_Codigo_Interno\") LIKE :q ESCAPE '\' OR LOWER(a.\"ACT_Hostname\") LIKE :q ESCAPE '\' OR LOWER(a.\"ACT_Serie_Fabricante\") LIKE :q ESCAPE '\')";
        }
        foreach (['modelo_id' => 'MOD_Modelo', 'tipo_activo_id' => 'TAC_Tipo_Activo', 'estado_operativo_id' => 'EOP_Estado_Operativo', 'sede_id' => 'SED_Sede'] as $filtro => $columna) {
            if (null !== ($filtros[$filtro] ?? null)) {
                $alcance .= " AND a.\"{$columna}\" = :{$filtro}";
                $p[$filtro] = $filtros[$filtro];
            }
        }
        if (null !== ($filtros['fecha_compra_start'] ?? null)) {
            $alcance .= " AND a.\"ACT_Fecha_Compra\" >= TO_DATE(:desde, 'YYYY-MM-DD')";
            $p['desde'] = $filtros['fecha_compra_start'];
        }
        if (null !== ($filtros['fecha_compra_end'] ?? null)) {
            $alcance .= " AND a.\"ACT_Fecha_Compra\" <= TO_DATE(:hasta, 'YYYY-MM-DD')";
            $p['hasta'] = $filtros['fecha_compra_end'];
        }
        // Nunca un archivo incompleto sin avisar: se pide acotar con filtros.
        $total = (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_ACTIVO" a WHERE '.$alcance, $p);
        self::exigirTamano($total);
        $filas = $this->conexion->fetchAllAssociative('SELECT a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante", a."ACT_Hostname", a."ACT_Fecha_Compra", '
            .'a."ACT_Fin_Garantia", a."ACT_Costo", a."ACT_Moneda", t."TAC_Nombre", m."MOD_Nombre", ma."MAR_Nombre", e."EOP_Nombre" FROM "INV_ACTIVO" a '
            .'LEFT JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" LEFT JOIN "INV_MODELO" m ON m."MOD_Modelo" = a."MOD_Modelo" '
            .'LEFT JOIN "INV_MARCA" ma ON ma."MAR_Marca" = m."MAR_Marca" LEFT JOIN "INV_ESTADO_OPERATIVO" e ON e."EOP_Estado_Operativo" = a."EOP_Estado_Operativo" '
            .'WHERE '.$alcance.' ORDER BY a."ACT_Codigo_Interno" FETCH FIRST :maximo ROWS ONLY', [...$p, 'maximo' => self::MAX_ACTIVOS]);

        return $this->csv('activos', $idioma, array_map(fn (array $a): array => [
            $a['ACT_Codigo_Interno'], $a['ACT_Serie_Fabricante'], $a['ACT_Hostname'] ?? '',
            null === $a['TAC_Nombre'] ? '' : Traducciones::catalogo(Traducciones::TIPO_ACTIVO, $a['TAC_Nombre'], $idioma),
            $a['MAR_Nombre'] ?? '', $a['MOD_Nombre'] ?? '',
            null === $a['EOP_Nombre'] ? '' : Traducciones::catalogo(Traducciones::ESTADO_OPERATIVO, $a['EOP_Nombre'], $idioma),
            $this->formato->fecha($a['ACT_Fecha_Compra']), $this->formato->fecha($a['ACT_Fin_Garantia']),
            FormatoLocal::monto($a['ACT_Costo']), null === $a['ACT_Costo'] ? '' : $a['ACT_Moneda'],
        ], $filas));
    }

    /** Los `$limite` movimientos más recientes. Persona y activo fuera del alcance salen vacíos. */
    /** @param array{tipo?: ?string, estado?: ?string, q?: ?string} $filtros los mismos de la pantalla */
    public function movimientos(int $limite, Idioma $idioma, array $filtros = []): string
    {
        [$aMov, $p] = $this->alcance->lectura('INV_MOVIMIENTO', 'm');
        if (null !== ($filtros['tipo'] ?? null)) {
            $aMov .= ' AND tm."TMO_Nombre" = :tipo';
            $p['tipo'] = $filtros['tipo'];
        }
        if ('vigente' === ($filtros['estado'] ?? null)) {
            $aMov .= ' AND m."MOV_Fecha_Devolucion" IS NULL';
        } elseif ('cerrado' === ($filtros['estado'] ?? null)) {
            $aMov .= ' AND m."MOV_Fecha_Devolucion" IS NOT NULL';
        }
        if (null !== ($filtros['q'] ?? null)) {
            $aMov .= ' AND (UPPER(a."ACT_Codigo_Interno") LIKE :q OR UPPER(a."ACT_Serie_Fabricante") LIKE :q OR UPPER(p."PER_Primer_Nombre"||\' \'||p."PER_Primer_Apellido") LIKE :q)';
            $p['q'] = '%'.mb_strtoupper((string) $filtros['q']).'%';
        }
        [$aAct] = $this->alcance->lectura('INV_ACTIVO', 'a');
        [$aPer] = $this->alcance->lectura('INV_PERSONA', 'p');
        $filas = $this->conexion->fetchAllAssociative('SELECT m."MOV_Fecha_Asignacion", m."MOV_Fecha_Devolucion", m."MOV_Observacion", a."ACT_Codigo_Interno", '
            .'a."ACT_Serie_Fabricante", p."PER_Persona", p."PER_Primer_Nombre", p."PER_Primer_Apellido", p."PER_Email_Corporativo", ar."ARE_Nombre", tm."TMO_Nombre" '
            .'FROM "INV_MOVIMIENTO" m LEFT JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" AND '.$aAct
            .' LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = m."PER_Persona" AND '.$aPer
            .' LEFT JOIN "INV_AREA" ar ON ar."ARE_Area" = m."ARE_Area" LEFT JOIN "INV_TIPO_MOVIMIENTO" tm ON tm."TMO_Tipo_Movimiento" = m."TMO_Tipo_Movimiento" '
            .'WHERE '.$aMov.' ORDER BY m."MOV_Fecha_Asignacion" DESC FETCH FIRST :limite ROWS ONLY', [...$p, 'limite' => $limite]);

        return $this->csv('movimientos', $idioma, array_map(fn (array $m): array => [
            $this->formato->fechaHora($m['MOV_Fecha_Asignacion']), $this->formato->fechaHora($m['MOV_Fecha_Devolucion']),
            $m['ACT_Codigo_Interno'] ?? '', $m['ACT_Serie_Fabricante'] ?? '',
            null === $m['PER_Persona'] ? '' : $m['PER_Primer_Nombre'].' '.$m['PER_Primer_Apellido'], $m['PER_Email_Corporativo'] ?? '',
            $m['ARE_Nombre'] ?? '',
            null === $m['TMO_Nombre'] ? '' : Traducciones::catalogo(Traducciones::TIPO_MOVIMIENTO, $m['TMO_Nombre'], $idioma),
            $m['MOV_Observacion'] ?? '',
        ], $filas));
    }

    public function consumibles(Idioma $idioma, ?string $busqueda = null, bool $soloBajo = false): string
    {
        [$alcance, $p] = $this->alcance->lectura('INV_CONSUMIBLE', 'c');
        if (null !== $busqueda) {
            $alcance .= ' AND (UPPER(c."CON_Nombre") LIKE :q OR UPPER(c."CON_Categoria") LIKE :q)';
            $p['q'] = '%'.mb_strtoupper($busqueda).'%';
        }
        if ($soloBajo) {
            $alcance .= ' AND c."CON_Stock_Minimo" > 0 AND c."CON_Stock_Actual" <= c."CON_Stock_Minimo"';
        }
        $filas = $this->conexion->fetchAllAssociative('SELECT c."CON_Nombre", c."CON_Categoria", c."CON_Unidad", c."CON_Stock_Actual", c."CON_Stock_Minimo", '
            .'c."CON_Activo" FROM "INV_CONSUMIBLE" c WHERE '.$alcance.' ORDER BY c."CON_Nombre"', $p);

        return $this->csv('consumibles', $idioma, array_map(static fn (array $c): array => [
            $c['CON_Nombre'], $c['CON_Categoria'] ?? '', Traducciones::catalogo(Traducciones::UNIDAD, $c['CON_Unidad'], $idioma),
            (int) $c['CON_Stock_Actual'], (int) $c['CON_Stock_Minimo'],
            Traducciones::siNo((int) $c['CON_Stock_Minimo'] > 0 && (int) $c['CON_Stock_Actual'] <= (int) $c['CON_Stock_Minimo'], $idioma),
            Traducciones::siNo(1 === (int) $c['CON_Activo'], $idioma),
        ], $filas));
    }

    public function mantenimientos(Idioma $idioma): string
    {
        [$alcance, $p] = $this->alcance->lectura('INV_ACTIVO', 'a');
        $filas = $this->conexion->fetchAllAssociative('SELECT a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante", t."TMA_Nombre", DBMS_LOB.SUBSTR(m."MAN_Descripcion_Falla", 1000, 1) AS FALLA, '
            .'m."MAN_Fecha_Ingreso", m."MAN_Fecha_Cierre" FROM "INV_MANTENIMIENTO" m JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" '
            .'LEFT JOIN "INV_TIPO_MANTENIMIENTO" t ON t."TMA_Tipo_Mantenimiento" = m."TMA_Tipo_Mantenimiento" WHERE '.$alcance.' ORDER BY m."MAN_Fecha_Ingreso" DESC', $p);

        return $this->csv('mantenimientos', $idioma, array_map(fn (array $m): array => [$m['ACT_Codigo_Interno'], $m['ACT_Serie_Fabricante'] ?? '', $m['TMA_Nombre'] ?? '', $m['FALLA'] ?? '',
            $this->formato->fechaHora($m['MAN_Fecha_Ingreso']), $this->formato->fechaHora($m['MAN_Fecha_Cierre'])], $filas));
    }

    public function personas(Idioma $idioma): string
    {
        [$alcance, $p] = $this->alcance->lectura('INV_PERSONA', 'p');
        $filas = $this->conexion->fetchAllAssociative('SELECT p."PER_Primer_Nombre", p."PER_Primer_Apellido", p."PER_Email_Corporativo", d."DEP_Nombre", c."CAR_Nombre", p."PER_Estado" '
            .'FROM "INV_PERSONA" p LEFT JOIN "INV_DEPARTAMENTO" d ON d."DEP_Departamento" = p."DEP_Departamento" LEFT JOIN "INV_CARGO" c ON c."CAR_Cargo" = p."CAR_Cargo" '
            .'WHERE '.$alcance.' ORDER BY p."PER_Primer_Apellido", p."PER_Primer_Nombre"', $p);

        return $this->csv('personas', $idioma, array_map(static fn (array $f): array => [$f['PER_Primer_Nombre'], $f['PER_Primer_Apellido'], $f['PER_Email_Corporativo'] ?? '', $f['DEP_Nombre'] ?? '', $f['CAR_Nombre'] ?? '',
            Traducciones::siNo(1 === (int) $f['PER_Estado'], $idioma)], $filas));
    }

    public function garantias(Idioma $idioma): string
    {
        [$alcance, $p] = $this->alcance->lectura('INV_ACTIVO', 'a');
        $filas = $this->conexion->fetchAllAssociative('SELECT a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante", t."TAC_Nombre", ma."MAR_Nombre", m."MOD_Nombre", a."ACT_Fin_Garantia" FROM "INV_ACTIVO" a '
            .'LEFT JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" LEFT JOIN "INV_MODELO" m ON m."MOD_Modelo" = a."MOD_Modelo" LEFT JOIN "INV_MARCA" ma ON ma."MAR_Marca" = m."MAR_Marca" '
            .'WHERE a."ACT_Fin_Garantia" IS NOT NULL AND '.$alcance.' ORDER BY a."ACT_Fin_Garantia"', $p);

        return $this->csv('garantias', $idioma, array_map(fn (array $a): array => [$a['ACT_Codigo_Interno'], $a['ACT_Serie_Fabricante'] ?? '', $a['TAC_Nombre'] ?? '', $a['MAR_Nombre'] ?? '', $a['MOD_Nombre'] ?? '',
            $this->formato->fechaHora($a['ACT_Fin_Garantia'])], $filas));
    }

    /** Catálogo de proveedores: compartido por todas las sedes. */
    public function proveedores(Idioma $idioma): string
    {
        $filas = $this->conexion->fetchAllAssociative('SELECT "PRV_Nombre", "PRV_Identificacion_Fiscal", "PRV_Contacto", "PRV_Email", "PRV_Telefono", '
            .'"PRV_Direccion", "PRV_Activo" FROM "INV_PROVEEDOR" ORDER BY "PRV_Nombre"');

        return $this->csv('proveedores', $idioma, array_map(static fn (array $f): array => [
            $f['PRV_Nombre'], $f['PRV_Identificacion_Fiscal'] ?? '', $f['PRV_Contacto'] ?? '', $f['PRV_Email'] ?? '', $f['PRV_Telefono'] ?? '',
            $f['PRV_Direccion'] ?? '', Traducciones::siNo(1 === (int) $f['PRV_Activo'], $idioma),
        ], $filas));
    }

    /**
     * Bitácora filtrada por el alcance del lector.
     *
     * @param array{0: string, 1: array<string, mixed>} $filtro de BitacoraRepositorio::filtro()
     *
     * @return array{0: string, 1: int} contenido y cantidad de eventos exportados
     */
    public function auditoria(array $filtro, int $limite, Idioma $idioma): array
    {
        $eventos = $this->bitacora->eventos($filtro, 0, $limite);
        $contenido = $this->csv('auditoria', $idioma, array_map(fn (array $ev): array => [
            $this->formato->fechaHora($ev['AUD_Fecha_Hora']),
            Traducciones::catalogo(Traducciones::ACCION_AUDITORIA, $ev['AUD_Accion'], $idioma),
            $ev['AUD_Entidad_Afectada'], $ev['AUD_IP_Origen'] ?? '', $ev['AUD_User_Agent'] ?? '',
            null === $ev['USU_Usuario'] ? '' : Ident::aTexto((string) $ev['USU_Usuario']),
            null === $ev['AUD_Snapshot_JSON'] || '' === $ev['AUD_Snapshot_JSON'] ? '' : self::detalle((string) $ev['AUD_Snapshot_JSON']),
        ], $eventos));

        return [$contenido, \count($eventos)];
    }

    /** Snapshot vacío ({} o []) se exporta vacío, como en el backend anterior. */
    private static function detalle(string $json): string
    {
        $texto = JsonLegible::reformatear($json);

        return \in_array($texto, ['{}', '[]', 'null'], true) ? '' : $texto;
    }

    /** @param list<list<mixed>> $filas */
    private function csv(string $exporte, Idioma $idioma, array $filas): string
    {
        return ArchivoCsv::entreComillas($idioma->de(self::CABECERAS[$exporte]))->filas($filas)->contenido();
    }
}
