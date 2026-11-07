<?php

declare(strict_types=1);

namespace App\Inventario;

use App\Catalogo\ClasesActivo;
use App\Dominio\Ident;
use App\Dominio\Reloj;
use App\Dominio\Roles;
use App\Http\ErrorApi;
use App\Seguridad\Alcance;
use Doctrine\DBAL\Connection;

/**
 * Herramientas para trabajar más rápido sin perder control: búsqueda global,
 * kit sugerido para una entrega de puesto, ficha 360 de una persona, calidad de
 * datos y los indicadores del tablero. Las consultas pasan por el alcance por
 * sede (VPD en la base; capa 1 en las tablas principales).
 */
final readonly class Productividad
{
    private const string ESTADO_LIBRE = 'En Bodega';
    private const array DEPARTAMENTOS_PROVISIONALES = ['por clasificar', 'sin departamento', 'sin asignar'];
    private const array DOMINIOS_PROVISIONALES = ['%example.com', '%.example', '%.local', '%.test', '%.invalid'];
    private const int MAX_ITEMS_VERIFICACION = 50;

    public function __construct(private Connection $conexion, private Alcance $alcance)
    {
    }

    /** @param array<string, mixed> $p */
    private static function nombre(array $p): string
    {
        return implode(' ', array_filter([$p['PER_Primer_Nombre'] ?? null, $p['PER_Segundo_Nombre'] ?? null, $p['PER_Primer_Apellido'] ?? null,
            $p['PER_Segundo_Apellido'] ?? null], static fn (mixed $x): bool => null !== $x && '' !== $x));
    }

    // ------------------------------------------------------------------
    // Búsqueda global
    // ------------------------------------------------------------------

    /** @return array{q: string, resultados: list<array<string, mixed>>} */
    public function buscar(string $q, string $rol, int $limite = 8): array
    {
        $tokens = \array_slice(array_values(array_filter(preg_split('/\s+/', trim($q)) ?: [])), 0, 5);
        if ([] === $tokens || mb_strlen(trim($q)) < 2) {
            return ['q' => $q, 'resultados' => []];
        }
        $resultados = [];
        // Cada rol solo encuentra lo que su módulo le deja ver.
        if (\in_array($rol, Roles::LECTURA_EXISTENCIAS, true)) {
            $this->buscarActivos($q, $tokens, $rol, $limite, $resultados);
        }
        if (\in_array($rol, Roles::LECTURA_PERSONAS, true)) {
            $this->buscarPersonas($tokens, $resultados);
        }

        return ['q' => $q, 'resultados' => $resultados];
    }

    /**
     * @param list<string> $tokens
     * @param list<array<string, mixed>> $resultados
     */
    private function buscarActivos(string $q, array $tokens, string $rol, int $limite, array &$resultados): void
    {
        [$alcance, $p] = $this->alcance->lectura('INV_ACTIVO', 'a');
        $cond = [];
        foreach ($tokens as $i => $t) {
            $p["t{$i}"] = '%'.mb_strtolower($t).'%';
            $cond[] = "(LOWER(a.\"ACT_Codigo_Interno\") LIKE :t{$i} OR LOWER(a.\"ACT_Serie_Fabricante\") LIKE :t{$i} OR LOWER(a.\"ACT_Hostname\") LIKE :t{$i} "
                ."OR LOWER(m.\"MOD_Nombre\") LIKE :t{$i} OR LOWER(ma.\"MAR_Nombre\") LIKE :t{$i} OR LOWER(t.\"TAC_Nombre\") LIKE :t{$i})";
        }
        $p['exacto'] = mb_strtolower(trim($q));
        $filas = $this->conexion->fetchAllAssociative('SELECT a."ACT_Activo", a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante", a."ACT_Hostname", t."TAC_Nombre", ma."MAR_Nombre", '
            .'m."MOD_Nombre", e."EOP_Nombre", p."PER_Primer_Nombre", p."PER_Segundo_Nombre", p."PER_Primer_Apellido", p."PER_Segundo_Apellido" FROM "INV_ACTIVO" a '
            .'JOIN "INV_MODELO" m ON m."MOD_Modelo" = a."MOD_Modelo" JOIN "INV_MARCA" ma ON ma."MAR_Marca" = m."MAR_Marca" '
            .'JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" JOIN "INV_ESTADO_OPERATIVO" e ON e."EOP_Estado_Operativo" = a."EOP_Estado_Operativo" '
            .'LEFT JOIN "INV_MOVIMIENTO" mv ON mv."ACT_Activo" = a."ACT_Activo" AND mv."MOV_Fecha_Devolucion" IS NULL LEFT JOIN "INV_PERSONA" p ON p."PER_Persona" = mv."PER_Persona" '
            .'WHERE '.implode(' AND ', $cond).' AND '.$alcance.' ORDER BY CASE WHEN LOWER(a."ACT_Codigo_Interno") = :exacto THEN 0 ELSE 1 END, a."ACT_Codigo_Interno" '
            .'FETCH FIRST '.$limite.' ROWS ONLY', $p);
        foreach ($filas as $f) {
            $resultados[] = ['tipo' => 'activo', 'id' => Ident::aTexto((string) $f['ACT_Activo']), 'codigo' => $f['ACT_Codigo_Interno'],
                'titulo' => "{$f['ACT_Codigo_Interno']} · {$f['TAC_Nombre']}",
                'subtitulo' => implode(' · ', array_filter(["{$f['MAR_Nombre']} {$f['MOD_Nombre']}", $f['ACT_Serie_Fabricante'], $f['ACT_Hostname']])),
                'estado' => $f['EOP_Nombre'],
                'custodio' => null !== $f['PER_Primer_Nombre'] && \in_array($rol, Roles::LECTURA_INVENTARIO, true) ? self::nombre($f) : null];
        }
    }

    /**
     * @param list<string> $tokens
     * @param list<array<string, mixed>> $resultados
     */
    private function buscarPersonas(array $tokens, array &$resultados): void
    {
        [$alcance, $p] = $this->alcance->lectura('INV_PERSONA', 'p');
        $cond = [];
        foreach ($tokens as $i => $t) {
            $p["t{$i}"] = '%'.mb_strtolower($t).'%';
            $cond[] = "(LOWER(p.\"PER_Primer_Nombre\" || ' ' || NVL(p.\"PER_Segundo_Nombre\", '') || ' ' || p.\"PER_Primer_Apellido\" || ' ' || NVL(p.\"PER_Segundo_Apellido\", '')) "
                ."LIKE :t{$i} OR LOWER(p.\"PER_Email_Corporativo\") LIKE :t{$i})";
        }
        foreach ($this->conexion->fetchAllAssociative('SELECT p.*, d."DEP_Nombre" FROM "INV_PERSONA" p JOIN "INV_DEPARTAMENTO" d ON d."DEP_Departamento" = p."DEP_Departamento" '
            .'WHERE '.implode(' AND ', $cond).' AND '.$alcance.' ORDER BY p."PER_Estado" DESC, p."PER_Primer_Nombre" FETCH FIRST 6 ROWS ONLY', $p) as $f) {
            $resultados[] = ['tipo' => 'persona', 'id' => Ident::aTexto((string) $f['PER_Persona']), 'titulo' => self::nombre($f),
                'subtitulo' => implode(' · ', array_filter([$f['DEP_Nombre'], $f['PER_Email_Corporativo']])), 'activo' => 1 === (int) $f['PER_Estado']];
        }
    }


    // ------------------------------------------------------------------
    // Kit sugerido para una entrega de puesto
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function kitSugerido(string $persona): array
    {
        [$aPer, $pPer] = $this->alcance->lectura('INV_PERSONA', 'p');
        $per = $this->conexion->fetchAssociative('SELECT p."CAR_Cargo", p."DEP_Departamento", d."DEP_Nombre" FROM "INV_PERSONA" p '
            .'JOIN "INV_DEPARTAMENTO" d ON d."DEP_Departamento" = p."DEP_Departamento" WHERE p."PER_Persona" = :p AND '.$aPer, [...$pPer, 'p' => $persona])
            ?: throw new ErrorApi(404, 'PERSON_NOT_FOUND');
        [$aAct, $pAct] = $this->alcance->lectura('INV_ACTIVO', 'a');
        $disponibles = array_map('intval', $this->conexion->fetchAllKeyValue('SELECT a."TAC_Tipo_Activo", COUNT(*) FROM "INV_ACTIVO" a '
            .'JOIN "INV_ESTADO_OPERATIVO" e ON e."EOP_Estado_Operativo" = a."EOP_Estado_Operativo" WHERE e."EOP_Nombre" = :libre AND a."ACT_Activo_Padre" IS NULL AND '
            .$aAct.' GROUP BY a."TAC_Tipo_Activo"', [...$pAct, 'libre' => self::ESTADO_LIBRE]));
        $yaTiene = array_flip(array_map('intval', $this->conexion->fetchFirstColumn('SELECT a."TAC_Tipo_Activo" FROM "INV_ACTIVO" a JOIN "INV_MOVIMIENTO" m ON m."ACT_Activo" = a."ACT_Activo" '
            .'WHERE m."PER_Persona" = :p AND m."MOV_Fecha_Devolucion" IS NULL', ['p' => $persona])));
        $nombres = $this->conexion->fetchAllKeyValue('SELECT "TAC_Tipo_Activo", "TAC_Nombre" FROM "INV_TIPO_ACTIVO"');
        // El kit estándar del cargo (o del departamento) manda sobre la deducción.
        foreach (['CAR_Cargo' => $per['CAR_Cargo'], 'DEP_Departamento' => $per['DEP_Departamento']] as $col => $valor) {
            if (null === $valor) {
                continue;
            }
            $kit = $this->conexion->fetchAssociative("SELECT \"KIT_Kit\", \"KIT_Nombre\" FROM \"INV_KIT_ESTANDAR\" WHERE \"{$col}\" = :v AND \"KIT_Activo\" = 1 "
                .'ORDER BY "KIT_Kit" FETCH FIRST 1 ROWS ONLY', ['v' => $valor]);
            if (false === $kit) {
                continue;
            }
            $items = $this->conexion->fetchAllAssociative('SELECT "TAC_Tipo_Activo", "KIT_Cantidad", "LIC_Licencia" FROM "INV_KIT_ESTANDAR_ITEM" WHERE "KIT_Kit" = :k ORDER BY "KIT_Item"',
                ['k' => $kit['KIT_Kit']]);

            return ['origen' => 'kit', 'kit' => $kit['KIT_Nombre'], 'departamento' => $per['DEP_Nombre'], 'personas_referencia' => 0,
                'tipos' => array_values(array_map(static fn (array $i): array => ['TAC_Tipo_Activo' => (int) $i['TAC_Tipo_Activo'],
                    'nombre' => $nombres[$i['TAC_Tipo_Activo']] ?? '?', 'frecuencia' => 1.0, 'cantidad' => (int) $i['KIT_Cantidad'],
                    'disponibles' => $disponibles[(int) $i['TAC_Tipo_Activo']] ?? 0, 'ya_tiene' => isset($yaTiene[(int) $i['TAC_Tipo_Activo']])],
                    array_filter($items, static fn (array $i): bool => null !== $i['TAC_Tipo_Activo']))),
                'licencias' => array_values(array_map('intval', array_filter(array_column($items, 'LIC_Licencia'))))];
        }
        $frecuencias = function (bool $porDepartamento) use ($persona, $per): array {
            $p = ['p' => $persona];
            $sql = 'FROM "INV_ACTIVO" a JOIN "INV_MOVIMIENTO" m ON m."ACT_Activo" = a."ACT_Activo" JOIN "INV_PERSONA" pe ON pe."PER_Persona" = m."PER_Persona" '
                .'WHERE m."MOV_Fecha_Devolucion" IS NULL AND pe."PER_Persona" <> :p';
            if ($porDepartamento) {
                $sql .= ' AND pe."DEP_Departamento" = :d';
                $p['d'] = $per['DEP_Departamento'];
            }
            $total = (int) $this->conexion->fetchOne('SELECT COUNT(DISTINCT m."PER_Persona") '.$sql, $p);

            return [$total, array_map(static fn (array $f): array => [(int) $f[0], (int) $f[1]],
                $this->conexion->fetchAllNumeric('SELECT a."TAC_Tipo_Activo", COUNT(DISTINCT m."PER_Persona") '.$sql.' GROUP BY a."TAC_Tipo_Activo"', $p))];
        };
        $origen = 'departamento';
        // Un departamento provisional («Por clasificar») no describe un puesto.
        $provisional = \in_array(mb_strtolower(trim((string) $per['DEP_Nombre'])), self::DEPARTAMENTOS_PROVISIONALES, true);
        [$total, $filas] = $provisional ? [0, []] : $frecuencias(true);
        if ($total < 2) {
            $origen = 'general';
            [$total, $filas] = $frecuencias(false);
        }
        $umbral = $total >= 3 ? 0.4 : 0.0;
        $tipos = [];
        foreach ($filas as [$tipo, $n]) {
            if ($total > 0 && $n / $total >= $umbral) {
                $tipos[] = ['TAC_Tipo_Activo' => $tipo, 'nombre' => $nombres[$tipo] ?? '?', 'frecuencia' => round($n / $total, 2),
                    'disponibles' => $disponibles[$tipo] ?? 0, 'ya_tiene' => isset($yaTiene[$tipo])];
            }
        }
        usort($tipos, static fn (array $a, array $b): int => [-$a['frecuencia'], $a['nombre']] <=> [-$b['frecuencia'], $b['nombre']]);

        return ['origen' => $origen, 'departamento' => $per['DEP_Nombre'], 'personas_referencia' => $total, 'tipos' => \array_slice($tipos, 0, 10), 'licencias' => []];
    }

    // ------------------------------------------------------------------
    // Ficha 360 de una persona
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function ficha(string $id): array
    {
        [$alcance, $pa] = $this->alcance->lectura('INV_PERSONA', 'p');
        $p = $this->conexion->fetchAssociative('SELECT p.*, d."DEP_Nombre", c."CAR_Nombre", s."SED_Nombre", j."PER_Primer_Nombre" AS "J1", j."PER_Segundo_Nombre" AS "J2", '
            .'j."PER_Primer_Apellido" AS "J3", j."PER_Segundo_Apellido" AS "J4" FROM "INV_PERSONA" p LEFT JOIN "INV_DEPARTAMENTO" d ON d."DEP_Departamento" = p."DEP_Departamento" '
            .'LEFT JOIN "INV_CARGO" c ON c."CAR_Cargo" = p."CAR_Cargo" LEFT JOIN "INV_SEDE" s ON s."SED_Sede" = p."SED_Sede" LEFT JOIN "INV_PERSONA" j ON j."PER_Persona" = p."PER_Jefe" '
            .'WHERE p."PER_Persona" = :id AND '.$alcance, [...$pa, 'id' => $id]) ?: throw new ErrorApi(404, 'PERSON_NOT_FOUND');
        $jefe = null === $p['J1'] ? null : self::nombre(['PER_Primer_Nombre' => $p['J1'], 'PER_Segundo_Nombre' => $p['J2'], 'PER_Primer_Apellido' => $p['J3'],
            'PER_Segundo_Apellido' => $p['J4']]);
        $equipos = array_map(static fn (array $f): array => ['MOV_Movimiento' => Ident::aTexto((string) $f['MOV_Movimiento']), 'ACT_Activo' => Ident::aTexto((string) $f['ACT_Activo']),
            'codigo' => $f['ACT_Codigo_Interno'], 'hostname' => $f['ACT_Hostname'], 'tipo' => $f['TAC_Nombre'], 'movimiento' => $f['TMO_Nombre'], 'area' => $f['ARE_Nombre'],
            'desde' => Reloj::iso(Reloj::desdeBd((string) $f['MOV_Fecha_Asignacion'])),
            'devolucion_prevista' => null === $f['MOV_Fecha_Prevista_Devolucion'] ? null : substr((string) $f['MOV_Fecha_Prevista_Devolucion'], 0, 10)],
            $this->conexion->fetchAllAssociative('SELECT m."MOV_Movimiento", m."MOV_Fecha_Asignacion", m."MOV_Fecha_Prevista_Devolucion", a."ACT_Activo", a."ACT_Codigo_Interno", '
                .'a."ACT_Hostname", t."TAC_Nombre", tm."TMO_Nombre", ar."ARE_Nombre" FROM "INV_MOVIMIENTO" m JOIN "INV_ACTIVO" a ON a."ACT_Activo" = m."ACT_Activo" '
                .'LEFT JOIN "INV_TIPO_ACTIVO" t ON t."TAC_Tipo_Activo" = a."TAC_Tipo_Activo" LEFT JOIN "INV_TIPO_MOVIMIENTO" tm ON tm."TMO_Tipo_Movimiento" = m."TMO_Tipo_Movimiento" '
                .'LEFT JOIN "INV_AREA" ar ON ar."ARE_Area" = m."ARE_Area" WHERE m."PER_Persona" = :p AND m."MOV_Fecha_Devolucion" IS NULL ORDER BY m."MOV_Fecha_Asignacion" DESC',
                ['p' => $id]));
        $licencias = array_map(static fn (array $f): array => ['INS_Instalacion' => (int) $f['INS_Instalacion'], 'LIC_Licencia' => (int) $f['LIC_Licencia'],
            'software' => implode(' ', array_filter([$f['SOF_Nombre'], $f['SOF_Version']])), 'cuenta' => $f['LCL_Usuario'],
            'LCL_Licencia_Clave' => null === $f['LCL_Licencia_Clave'] ? null : (int) $f['LCL_Licencia_Clave'],
            'desde' => substr((string) $f['INS_Fecha_Instalacion'], 0, 10), 'vence' => null === $f['LIC_Fecha_Vencimiento'] ? null : substr((string) $f['LIC_Fecha_Vencimiento'], 0, 10)],
            $this->conexion->fetchAllAssociative('SELECT i."INS_Instalacion", i."LIC_Licencia", i."LCL_Licencia_Clave", i."INS_Fecha_Instalacion", l."LIC_Fecha_Vencimiento", '
                .'s."SOF_Nombre", s."SOF_Version", c."LCL_Usuario" FROM "INV_INSTALACION" i JOIN "INV_LICENCIA" l ON l."LIC_Licencia" = i."LIC_Licencia" '
                .'JOIN "INV_SOFTWARE" s ON s."SOF_Software" = l."SOF_Software" LEFT JOIN "INV_LICENCIA_CLAVE" c ON c."LCL_Licencia_Clave" = i."LCL_Licencia_Clave" '
                .'WHERE i."PER_Persona" = :p AND i."INS_Estado" = 1 ORDER BY s."SOF_Nombre"', ['p' => $id]));
        $consumibles = array_map(static fn (array $f): array => ['MOC_Movimiento' => (int) $f['MOC_Movimiento'], 'nombre' => $f['CON_Nombre'], 'cantidad' => (int) $f['MOC_Cantidad'],
            'unidad' => $f['CON_Unidad'], 'fecha' => Reloj::iso(Reloj::desdeBd((string) $f['MOC_Fecha'])), 'motivo' => $f['MOC_Motivo']],
            $this->conexion->fetchAllAssociative('SELECT m."MOC_Movimiento", m."MOC_Cantidad", m."MOC_Fecha", m."MOC_Motivo", c."CON_Nombre", c."CON_Unidad" FROM "INV_MOVIMIENTO_CONSUMIBLE" m '
                ."JOIN \"INV_CONSUMIBLE\" c ON c.\"CON_Consumible\" = m.\"CON_Consumible\" WHERE m.\"PER_Persona\" = :p AND m.\"MOC_Tipo\" = 'SALIDA' ORDER BY m.\"MOC_Fecha\" DESC "
                .'FETCH FIRST 50 ROWS ONLY', ['p' => $id]));
        $historial = array_map(static fn (array $f): array => ['KDX_Evento' => (int) $f['KDX_Evento'], 'fecha' => Reloj::iso(Reloj::desdeBd((string) $f['KDX_Fecha'])),
            'tipo' => $f['KDX_Tipo'], 'objeto' => $f['KDX_Objeto'], 'referencia' => $f['KDX_Referencia'], 'motivo' => $f['KDX_Motivo'],
            'sentido' => (string) $f['KDX_Destino_Persona'] === $id ? 'RECIBE' : 'ENTREGA'],
            $this->conexion->fetchAllAssociative('SELECT "KDX_Evento", "KDX_Fecha", "KDX_Tipo", "KDX_Objeto", "KDX_Referencia", "KDX_Motivo", "KDX_Destino_Persona" FROM "INV_KARDEX" '
                .'WHERE "KDX_Destino_Persona" = :p OR "KDX_Origen_Persona" = :p ORDER BY "KDX_Fecha" DESC, "KDX_Evento" DESC FETCH FIRST 100 ROWS ONLY', ['p' => $id]));

        return ['persona' => ['PER_Persona' => Ident::aTexto($id), 'nombre' => self::nombre($p), 'PER_Email_Corporativo' => $p['PER_Email_Corporativo'],
            'PER_Telefono' => $p['PER_Telefono'] ?? null, 'PER_Estado' => 1 === (int) $p['PER_Estado'], 'departamento' => $p['DEP_Nombre'], 'cargo' => $p['CAR_Nombre'],
            'jefe' => $jefe, 'PER_Jefe' => null === $p['PER_Jefe'] ? null : Ident::aTexto((string) $p['PER_Jefe']), 'sede' => $p['SED_Nombre'],
            'SED_Sede' => null === $p['SED_Sede'] ? null : (int) $p['SED_Sede']],
            'equipos' => $equipos, 'licencias' => $licencias, 'consumibles' => $consumibles, 'historial' => $historial];
    }

    // ------------------------------------------------------------------
    // Calidad de datos
    // ------------------------------------------------------------------

    /** @return array{pendientes: int, verificaciones: list<array<string, mixed>>} */
    public function calidadDatos(): array
    {
        [$aAct, $pAct] = $this->alcance->lectura('INV_ACTIVO', 'a');
        $verificaciones = [];
        $agregar = function (string $clave, string $severidad, string $sql, array $p, \Closure $item) use (&$verificaciones): void {
            $total = (int) $this->conexion->fetchOne("SELECT COUNT(*) FROM ({$sql})", $p);
            $filas = 0 === $total ? [] : $this->conexion->fetchAllAssociative("{$sql} FETCH FIRST ".self::MAX_ITEMS_VERIFICACION.' ROWS ONLY', $p);
            $verificaciones[] = ['clave' => $clave, 'severidad' => $severidad, 'total' => $total, 'items' => array_map($item, $filas)];
        };
        $activo = static fn (array $f): array => ['tipo' => 'activo', 'id' => Ident::aTexto((string) $f['ACT_Activo']), 'codigo' => $f['ACT_Codigo_Interno'],
            'titulo' => $f['ACT_Codigo_Interno'], 'detalle' => $f['ACT_Serie_Fabricante']];
        $persona = static fn (array $f): array => ['tipo' => 'persona', 'id' => Ident::aTexto((string) $f['PER_Persona']), 'titulo' => self::nombre($f),
            'detalle' => $f['PER_Email_Corporativo']];
        $cols = 'a."ACT_Activo", a."ACT_Codigo_Interno", a."ACT_Serie_Fabricante"';
        $base = 'FROM "INV_ACTIVO" a JOIN "INV_ESTADO_OPERATIVO" e ON e."EOP_Estado_Operativo" = a."EOP_Estado_Operativo"';
        $abiertos = 'SELECT "ACT_Activo" FROM "INV_MOVIMIENTO" WHERE "MOV_Fecha_Devolucion" IS NULL';
        $agregar('custodia_persona_inactiva', 'alta', "SELECT {$cols}, p.\"PER_Primer_Nombre\", p.\"PER_Segundo_Nombre\", p.\"PER_Primer_Apellido\", p.\"PER_Segundo_Apellido\" "
            .'FROM "INV_ACTIVO" a JOIN "INV_MOVIMIENTO" m ON m."ACT_Activo" = a."ACT_Activo" AND m."MOV_Fecha_Devolucion" IS NULL JOIN "INV_PERSONA" p ON p."PER_Persona" = m."PER_Persona" '
            ."WHERE p.\"PER_Estado\" = 0 AND {$aAct} ORDER BY a.\"ACT_Codigo_Interno\"", $pAct, static fn (array $f): array => [...$activo($f), 'detalle' => self::nombre($f)]);
        $agregar('estado_inconsistente', 'alta', "SELECT {$cols} {$base} WHERE ((e.\"EOP_Nombre\" = 'Asignado' AND a.\"ACT_Activo\" NOT IN ({$abiertos})) "
            ."OR (e.\"EOP_Nombre\" = 'En Bodega' AND a.\"ACT_Activo\" IN ({$abiertos}))) AND {$aAct} ORDER BY a.\"ACT_Codigo_Interno\"", $pAct, $activo);
        [$aPer, $pPer] = $this->alcance->lectura('INV_PERSONA', 'p');
        $agregar('personas_por_clasificar', 'media', 'SELECT p."PER_Persona", p."PER_Primer_Nombre", p."PER_Segundo_Nombre", p."PER_Primer_Apellido", p."PER_Segundo_Apellido", '
            .'p."PER_Email_Corporativo" FROM "INV_PERSONA" p JOIN "INV_DEPARTAMENTO" d ON d."DEP_Departamento" = p."DEP_Departamento" '
            ."WHERE LOWER(d.\"DEP_Nombre\") IN ('por clasificar', 'sin departamento', 'sin asignar') AND p.\"PER_Estado\" = 1 AND {$aPer} ORDER BY p.\"PER_Primer_Nombre\"", $pPer, $persona);
        $dominios = implode(' OR ', array_map(static fn (string $d): string => "LOWER(p.\"PER_Email_Corporativo\") LIKE '{$d}'", self::DOMINIOS_PROVISIONALES));
        $agregar('correos_provisionales', 'media', 'SELECT p."PER_Persona", p."PER_Primer_Nombre", p."PER_Segundo_Nombre", p."PER_Primer_Apellido", p."PER_Segundo_Apellido", '
            ."p.\"PER_Email_Corporativo\" FROM \"INV_PERSONA\" p WHERE ({$dominios}) AND p.\"PER_Estado\" = 1 AND {$aPer} ORDER BY p.\"PER_Primer_Nombre\"", $pPer, $persona);
        $agregar('activos_sin_serie', 'media', "SELECT {$cols} {$base} WHERE e.\"EOP_Nombre\" <> 'Baja' AND (a.\"ACT_Serie_Fabricante\" = a.\"ACT_Codigo_Interno\" "
            ."OR a.\"ACT_Serie_Fabricante\" LIKE '%(' || a.\"ACT_Codigo_Interno\" || ')') AND {$aAct} ORDER BY a.\"ACT_Codigo_Interno\"", $pAct, $activo);
        $agregar('activos_sin_costo', 'baja', "SELECT {$cols} {$base} WHERE e.\"EOP_Nombre\" <> 'Baja' AND a.\"ACT_Costo\" IS NULL AND {$aAct} ORDER BY a.\"ACT_Codigo_Interno\"",
            $pAct, $activo);
        $agregar('modelos_sin_tipo', 'baja', 'SELECT m."MOD_Modelo", m."MOD_Nombre", ma."MAR_Nombre" FROM "INV_MODELO" m JOIN "INV_MARCA" ma ON ma."MAR_Marca" = m."MAR_Marca" '
            .'WHERE m."TAC_Tipo_Activo" IS NULL ORDER BY m."MOD_Nombre"', [], static fn (array $f): array => ['tipo' => 'modelo', 'id' => (string) (int) $f['MOD_Modelo'],
                'titulo' => $f['MOD_Nombre'], 'detalle' => $f['MAR_Nombre']]);
        $agregar('especificaciones_faltantes', 'media', "SELECT {$cols}, f.\"FALTAN\" FROM \"INV_ACTIVO\" a JOIN (SELECT a2.\"ACT_Activo\" AS \"ACTIVO\", "
            .'LISTAGG(te."TES_Nombre", \', \') WITHIN GROUP (ORDER BY te."TES_Nombre") AS "FALTAN" FROM "INV_ACTIVO" a2 '
            .'JOIN "INV_ESTADO_OPERATIVO" e2 ON e2."EOP_Estado_Operativo" = a2."EOP_Estado_Operativo" '
            .'JOIN "INV_ESPECIFICACION_TIPO_ACTIVO" eta ON eta."TAC_Tipo_Activo" = a2."TAC_Tipo_Activo" AND eta."ETA_Obligatoria" = 1 '
            .'JOIN "INV_TIPO_ESPECIFICACION" te ON te."TES_Tipo_Especificacion" = eta."TES_Tipo_Especificacion" '
            ."WHERE e2.\"EOP_Nombre\" <> 'Baja' AND NOT EXISTS (SELECT 1 FROM \"INV_ESPECIFICACION\" es WHERE es.\"ACT_Activo\" = a2.\"ACT_Activo\" "
            .'AND es."TES_Tipo_Especificacion" = eta."TES_Tipo_Especificacion") GROUP BY a2."ACT_Activo") f ON f."ACTIVO" = a."ACT_Activo" '
            ."WHERE {$aAct} ORDER BY a.\"ACT_Codigo_Interno\"", $pAct, static fn (array $f): array => [...$activo($f), 'detalle' => $f['FALTAN']]);
        $pares = $this->marcasSimilares();
        $verificaciones[] = ['clave' => 'marcas_similares', 'severidad' => 'media', 'total' => \count($pares), 'items' => \array_slice($pares, 0, self::MAX_ITEMS_VERIFICACION)];
        $pendientes = array_sum(array_column(array_filter($verificaciones, static fn (array $v): bool => 'baja' !== $v['severidad']), 'total'));

        return ['pendientes' => $pendientes, 'verificaciones' => $verificaciones];
    }

    /**
     * Pares de marcas casi iguales (mayúsculas, acentos, signos o 1–2 letras): candidatas a fusionar.
     *
     * @return list<array<string, mixed>>
     */
    private function marcasSimilares(): array
    {
        $normal = static fn (string $n): string => (string) preg_replace('/[^a-z0-9]/', '', ClasesActivo::normalizar(
            (string) preg_replace('/[^\x00-\x7F]/', '', \Normalizer::normalize($n, \Normalizer::FORM_KD) ?: $n)));
        $marcas = array_map(static fn (array $f): array => [(int) $f[0], (string) $f[1], $normal((string) $f[1]), (int) $f[2]],
            $this->conexion->fetchAllNumeric('SELECT ma."MAR_Marca", ma."MAR_Nombre", COUNT(m."MOD_Modelo") FROM "INV_MARCA" ma LEFT JOIN "INV_MODELO" m ON m."MAR_Marca" = ma."MAR_Marca" '
                .'WHERE ma."MAR_Activo" = 1 GROUP BY ma."MAR_Marca", ma."MAR_Nombre"'));
        $pares = [];
        foreach ($marcas as $i => [$idA, $nomA, $nA, $cA]) {
            foreach (\array_slice($marcas, $i + 1) as [$idB, $nomB, $nB, $cB]) {
                $min = min(\strlen($nA), \strlen($nB));
                $tope = $min >= 6 ? 2 : ($min >= 4 ? 1 : 0);
                if ($nA === $nB || ($tope > 0 && abs(\strlen($nA) - \strlen($nB)) <= $tope && levenshtein($nA, $nB) <= $tope)) {
                    [$origen, $destino] = $cA <= $cB ? [[$idA, $nomA], [$idB, $nomB]] : [[$idB, $nomB], [$idA, $nomA]];
                    $pares[] = ['tipo' => 'marca', 'id' => (string) $origen[0], 'titulo' => "{$origen[1]} → {$destino[1]}", 'detalle' => (string) $destino[0]];
                }
            }
        }

        return $pares;
    }

    // ------------------------------------------------------------------
    // Tablero
    // ------------------------------------------------------------------

    /**
     * Indicadores del tablero con las sedes del alcance (sin licencias para quien no ve software).
     *
     * @return array<string, mixed>
     */
    public function tablero(string $rol): array
    {
        [$aAct, $pAct] = $this->alcance->lectura('INV_ACTIVO', 'a');
        [$aMov, $pMov] = $this->alcance->lectura('INV_MOVIMIENTO', 'm');
        [$aMan, $pMan] = $this->alcance->lectura('INV_MANTENIMIENTO', 'mt');
        $hoy = Reloj::ahora()->format('Y-m-d');
        $porMoneda = static function (array $filas): object {
            $r = [];
            foreach ($filas as [$moneda, $total]) {
                if (null !== $moneda && 0.0 !== (float) $total) {
                    $r[$moneda] = (float) $total;
                }
            }

            return (object) $r;
        };
        $total = (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_ACTIVO" a WHERE '.$aAct, $pAct);
        $custodias = (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_MOVIMIENTO" m WHERE m."MOV_Fecha_Devolucion" IS NULL AND '.$aMov, $pMov);
        $porEstado = [];
        foreach ($this->conexion->fetchAllNumeric('SELECT e."EOP_Nombre", COUNT(a."ACT_Activo") FROM "INV_ESTADO_OPERATIVO" e LEFT JOIN "INV_ACTIVO" a '
            .'ON a."EOP_Estado_Operativo" = e."EOP_Estado_Operativo" AND '.$aAct.' GROUP BY e."EOP_Nombre"', $pAct) as [$n, $c]) {
            $porEstado[(string) $n] = (int) $c;
        }
        $porTipo = array_values(array_map(static fn (array $f): array => ['tipo' => $f[0], 'total' => (int) $f[1]], array_filter(
            $this->conexion->fetchAllNumeric('SELECT t."TAC_Nombre", COUNT(a."ACT_Activo") FROM "INV_TIPO_ACTIVO" t LEFT JOIN "INV_ACTIVO" a '
                .'ON a."TAC_Tipo_Activo" = t."TAC_Tipo_Activo" AND '.$aAct.' GROUP BY t."TAC_Nombre"', $pAct), static fn (array $f): bool => (int) $f[1] > 0)));
        $porDepartamento = array_map(static fn (array $f): array => ['departamento' => $f[0], 'total' => (int) $f[1]],
            $this->conexion->fetchAllNumeric('SELECT d."DEP_Nombre", COUNT(m."MOV_Movimiento") FROM "INV_MOVIMIENTO" m JOIN "INV_PERSONA" p ON p."PER_Persona" = m."PER_Persona" '
                .'JOIN "INV_DEPARTAMENTO" d ON d."DEP_Departamento" = p."DEP_Departamento" WHERE m."MOV_Fecha_Devolucion" IS NULL AND '.$aMov
                .' GROUP BY d."DEP_Nombre" ORDER BY COUNT(m."MOV_Movimiento") DESC', $pMov));
        $garantias = array_map(static fn (array $f): array => ['codigo' => $f[0], 'fin_garantia' => substr((string) $f[1], 0, 10)],
            $this->conexion->fetchAllNumeric("SELECT a.\"ACT_Codigo_Interno\", a.\"ACT_Fin_Garantia\" FROM \"INV_ACTIVO\" a WHERE a.\"ACT_Fin_Garantia\" IS NOT NULL "
                ."AND a.\"ACT_Fin_Garantia\" >= TO_DATE(:hoy, 'YYYY-MM-DD') AND a.\"ACT_Fin_Garantia\" <= TO_DATE(:hoy, 'YYYY-MM-DD') + 90 AND {$aAct} "
                .'ORDER BY a."ACT_Fin_Garantia" FETCH FIRST 50 ROWS ONLY', [...$pAct, 'hoy' => $hoy]));
        $vencidas = (int) $this->conexion->fetchOne("SELECT COUNT(*) FROM \"INV_ACTIVO\" a WHERE a.\"ACT_Fin_Garantia\" IS NOT NULL AND a.\"ACT_Fin_Garantia\" < TO_DATE(:hoy, 'YYYY-MM-DD') "
            ."AND {$aAct}", [...$pAct, 'hoy' => $hoy]);
        $mantAbiertos = (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_MANTENIMIENTO" mt WHERE mt."MAN_Fecha_Cierre" IS NULL AND '.$aMan, $pMan);
        $mantTotal = (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_MANTENIMIENTO" mt WHERE '.$aMan, $pMan);
        $costoMant = $porMoneda($this->conexion->fetchAllNumeric('SELECT mt."MAN_Moneda", NVL(SUM(mt."MAN_Costo_Total"), 0) FROM "INV_MANTENIMIENTO" mt WHERE '.$aMan
            .' GROUP BY mt."MAN_Moneda"', $pMan));
        $baja = $this->conexion->fetchOne('SELECT "EOP_Estado_Operativo" FROM "INV_ESTADO_OPERATIVO" WHERE LOWER("EOP_Nombre") = \'baja\' FETCH FIRST 1 ROWS ONLY');
        $costoInventario = $porMoneda($this->conexion->fetchAllNumeric('SELECT a."ACT_Moneda", NVL(SUM(a."ACT_Costo"), 0) FROM "INV_ACTIVO" a WHERE a."ACT_Costo" IS NOT NULL'
            .(false === $baja ? '' : ' AND a."EOP_Estado_Operativo" <> :baja').' AND '.$aAct.' GROUP BY a."ACT_Moneda"', [...$pAct, ...(false === $baja ? [] : ['baja' => (int) $baja])]));
        [$licTotal, $licUsadas] = $this->conexion->fetchNumeric('SELECT NVL(SUM("LIC_Cantidad_Total"), 0), NVL(SUM("LIC_Cantidad_Usada"), 0) FROM "INV_LICENCIA"') ?: [0, 0];
        $porAgotar = array_map(static fn (array $f): array => ['software' => $f[0] ?? '—', 'usadas' => (int) $f[1], 'total' => (int) $f[2]],
            $this->conexion->fetchAllNumeric('SELECT s."SOF_Nombre", l."LIC_Cantidad_Usada", l."LIC_Cantidad_Total" FROM "INV_LICENCIA" l LEFT JOIN "INV_SOFTWARE" s '
                .'ON s."SOF_Software" = l."SOF_Software" WHERE l."LIC_Cantidad_Total" > 0 AND l."LIC_Cantidad_Usada" * 100 >= l."LIC_Cantidad_Total" * 80'));
        $libres = $porEstado['En Bodega'] ?? 0;
        $datos = ['activos_totales' => $total, 'activos_asignados' => $porEstado['Asignado'] ?? 0, 'activos_stock' => $libres, 'custodias_abiertas' => $custodias,
            'licencias_total' => (int) $licTotal, 'licencias_usadas' => (int) $licUsadas, 'licencias_disponibles' => (int) $licTotal - (int) $licUsadas,
            'mantenimientos_abiertos' => $mantAbiertos, 'mantenimientos_total' => $mantTotal, 'disponibles_bodega' => $libres,
            'en_reparacion' => $porEstado['En Reparación'] ?? 0, 'en_baja' => $porEstado['Baja'] ?? 0, 'por_estado' => (object) $porEstado, 'por_tipo' => $porTipo,
            'por_departamento' => $porDepartamento, 'garantias_por_vencer' => $garantias, 'garantias_por_vencer_total' => \count($garantias),
            'garantias_vencidas' => $vencidas, 'costo_inventario_por_moneda' => $costoInventario, 'costo_mantenimiento_por_moneda' => $costoMant,
            'licencias_por_agotar' => $porAgotar];
        if (!\in_array($rol, Roles::LECTURA_SOFTWARE, true)) {
            $datos = array_filter($datos, static fn (string $k): bool => !str_starts_with($k, 'licencias'), \ARRAY_FILTER_USE_KEY);
        }

        return $datos;
    }
}
