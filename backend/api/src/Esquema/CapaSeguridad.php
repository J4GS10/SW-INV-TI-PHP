<?php

declare(strict_types=1);

namespace App\Esquema;

use App\Dominio\Roles;
use Doctrine\DBAL\Connection;

/**
 * Instala o verifica la seguridad en la base: contexto de aplicación, paquetes,
 * disparadores, centinela del kardex, predicados y políticas DBMS_RLS.
 *
 * Idempotente y convergente: compara con lo instalado y solo cambia lo que
 * difiere. En una base ya correcta no recompila ningún paquete ni quita ninguna
 * política (no hay ventana sin VPD ni ORA-04068 en las sesiones de la API).
 * Tras restaurar un respaldo con Data Pump repone las políticas que se perdieron
 * al reemplazar las tablas. Se ejecuta conectado como DUEÑO del esquema.
 */
final readonly class CapaSeguridad
{
    public function __construct(
        private Connection $conexion,
        private EjecutorDdl $ddl,
    ) {
    }

    public function definicion(): DefinicionSeguridad
    {
        $esquema = $this->esquemaPropio();
        $columnas = array_map('strval', $this->conexion->fetchFirstColumn(
            "SELECT column_name FROM user_tab_columns WHERE table_name = 'INV_KARDEX'"));
        if ([] === $columnas) {
            throw new \RuntimeException('No existe INV_KARDEX: ejecute primero las migraciones.');
        }

        return new DefinicionSeguridad($esquema, $columnas, Roles::SIEMPRE_GLOBALES, Roles::SIN_DATOS_DE_NEGOCIO);
    }

    /** @return list<string> cambios hechos (vacía si ya estaba instalada) */
    public function instalar(): array
    {
        $definicion = $this->definicion();
        $cambios = [];
        // Idempotente y sin efecto sobre los valores de las sesiones abiertas.
        $this->ddl->ejecutar($definicion->ddlContexto());

        foreach ($definicion->objetos() as $objeto) {
            $estado = $this->estadoObjeto($objeto);
            if (!$objeto->equivaleA($this->fuenteInstalada($objeto))) {
                $this->ddl->crearPlsql($objeto);
                $cambios[] = (null === $estado ? 'creado ' : 'actualizado ').$objeto->etiqueta();
            } elseif ('VALID' !== $estado) {
                $this->ddl->ejecutar('ALTER '.$this->tipoAlter($objeto).' '.$objeto->nombre.' COMPILE'
                    .(ObjetoPlsql::CUERPO === $objeto->tipo ? ' BODY' : ''));
                $cambios[] = "recompilado {$objeto->etiqueta()}";
            }
            if (ObjetoPlsql::DISPARADOR === $objeto->tipo && 'ENABLED' !== $this->conexion->fetchOne(
                'SELECT status FROM user_triggers WHERE trigger_name = :n', ['n' => $objeto->nombre])) {
                $this->ddl->ejecutar("ALTER TRIGGER {$objeto->nombre} ENABLE");
                $cambios[] = "habilitado {$objeto->etiqueta()}";
            }
        }

        $cambios = [...$cambios, ...$this->asegurarSello(), ...$this->asegurarPredicados($definicion), ...$this->asegurarPoliticas($definicion)];
        $faltas = $this->verificar();
        if ([] !== $faltas) {
            throw new \RuntimeException('La capa de seguridad quedó incompleta: '.implode('; ', $faltas));
        }

        return $cambios;
    }

    /**
     * Diferencias entre lo instalado y lo esperado, sin cambiar nada.
     *
     * @return list<string> vacía si la capa está completa
     */
    public function verificar(): array
    {
        $definicion = $this->definicion();
        $faltas = [];
        foreach ($definicion->objetos() as $objeto) {
            $estado = $this->estadoObjeto($objeto);
            if (null === $estado) {
                $faltas[] = "falta {$objeto->etiqueta()}";
            } elseif ('VALID' !== $estado) {
                $faltas[] = "{$objeto->etiqueta()} inválido";
            } elseif (!$objeto->equivaleA($this->fuenteInstalada($objeto))) {
                $faltas[] = "{$objeto->etiqueta()} distinto del esperado";
            }
        }
        $deshabilitados = $this->conexion->fetchFirstColumn(
            "SELECT trigger_name FROM user_triggers WHERE trigger_name LIKE 'TRG\\_%' ESCAPE '\\' AND status <> 'ENABLED'");
        foreach ($deshabilitados as $t) {
            $faltas[] = "disparador {$t} deshabilitado";
        }
        if (1 !== (int) $this->conexion->fetchOne('SELECT COUNT(*) FROM "INV_KARDEX_SELLO" WHERE "SEL_Id" = 1')) {
            $faltas[] = 'falta la fila centinela de INV_KARDEX_SELLO';
        }
        $predicados = $this->predicadosInstalados();
        $instaladas = $this->politicasInstaladas();
        $tablas = $this->tablas();
        foreach ((new PoliticasVpd($definicion->contexto()))->todas() as $p) {
            if (!isset($tablas[$p->tabla])) {
                continue;
            }
            if (($predicados["{$p->tabla}|{$p->operacion}"] ?? null) !== $p->predicado) {
                $faltas[] = "predicado {$p->tabla}/{$p->operacion} distinto";
            }
            if (!$this->politicaCorrecta($instaladas["{$p->tabla}|{$p->nombre()}"] ?? null, $p, $definicion->esquema)) {
                $faltas[] = "política {$p->nombre()} de {$p->tabla} ausente o distinta";
            }
        }

        return $faltas;
    }

    /** @return list<string> */
    private function asegurarSello(): array
    {
        // El centinela arranca en el último eslabón existente (0 en una base nueva).
        $filas = $this->conexion->executeStatement(<<<'SQL'
            MERGE INTO "INV_KARDEX_SELLO" s
            USING (SELECT 1 AS id FROM DUAL) d ON (s."SEL_Id" = d.id)
            WHEN NOT MATCHED THEN INSERT ("SEL_Id", "SEL_Secuencia", "SEL_Hash")
            VALUES (1,
                    NVL((SELECT MAX("KDX_Secuencia") FROM "INV_KARDEX"), 0),
                    (SELECT "KDX_Hash" FROM "INV_KARDEX" WHERE "KDX_Secuencia" =
                        (SELECT MAX("KDX_Secuencia") FROM "INV_KARDEX")))
            SQL);

        return $filas > 0 ? ['creada la fila centinela de INV_KARDEX_SELLO'] : [];
    }

    /** @return list<string> */
    private function asegurarPredicados(DefinicionSeguridad $definicion): array
    {
        $tablas = $this->tablas();
        $esperados = [];
        foreach ((new PoliticasVpd($definicion->contexto()))->todas() as $p) {
            if (isset($tablas[$p->tabla])) {
                $esperados["{$p->tabla}|{$p->operacion}"] = $p;
            }
        }
        $instalados = $this->predicadosInstalados();
        $cambios = [];
        $this->conexion->transactional(function (Connection $c) use ($esperados, $instalados, &$cambios): void {
            foreach ($esperados as $clave => $p) {
                $actual = $instalados[$clave] ?? null;
                if (null === $actual) {
                    $c->executeStatement('INSERT INTO "INV_VPD_PREDICADO" ("VPD_Tabla", "VPD_Operacion", "VPD_Predicado") VALUES (:t, :o, :p)',
                        ['t' => $p->tabla, 'o' => $p->operacion, 'p' => $p->predicado]);
                    $cambios[] = "predicado {$p->tabla}/{$p->operacion} creado";
                } elseif ($actual !== $p->predicado) {
                    $c->executeStatement('UPDATE "INV_VPD_PREDICADO" SET "VPD_Predicado" = :p WHERE "VPD_Tabla" = :t AND "VPD_Operacion" = :o',
                        ['t' => $p->tabla, 'o' => $p->operacion, 'p' => $p->predicado]);
                    $cambios[] = "predicado {$p->tabla}/{$p->operacion} actualizado";
                }
            }
            foreach (array_diff_key($instalados, $esperados) as $clave => $_) {
                [$tabla, $operacion] = explode('|', (string) $clave);
                $c->executeStatement('DELETE FROM "INV_VPD_PREDICADO" WHERE "VPD_Tabla" = :t AND "VPD_Operacion" = :o', ['t' => $tabla, 'o' => $operacion]);
                $cambios[] = "predicado {$tabla}/{$operacion} quitado";
            }
        });

        return $cambios;
    }

    /** @return list<string> */
    private function asegurarPoliticas(DefinicionSeguridad $definicion): array
    {
        $tablas = $this->tablas();
        $instaladas = $this->politicasInstaladas();
        $cambios = [];
        $esperadas = [];
        foreach ((new PoliticasVpd($definicion->contexto()))->todas() as $p) {
            if (!isset($tablas[$p->tabla])) {
                continue;
            }
            $clave = "{$p->tabla}|{$p->nombre()}";
            $esperadas[$clave] = true;
            $actual = $instaladas[$clave] ?? null;
            if ($this->politicaCorrecta($actual, $p, $definicion->esquema)) {
                continue;
            }
            if (null !== $actual) {
                $this->quitarPolitica($p->tabla, $p->nombre());
            }
            $this->conexion->executeStatement(
                'BEGIN DBMS_RLS.ADD_POLICY(object_schema => USER, object_name => :t, policy_name => :n, '
                .'function_schema => USER, policy_function => :f, statement_types => :s, '
                .'update_check => TRUE, enable => TRUE, policy_type => DBMS_RLS.DYNAMIC); END;',
                ['t' => $p->tabla, 'n' => $p->nombre(), 'f' => $p->funcion(), 's' => $p->tiposSentencia()],
            );
            $cambios[] = (null === $actual ? 'creada' : 'recreada')." la política {$p->nombre()} de {$p->tabla}";
        }
        foreach (array_diff_key($instaladas, $esperadas) as $clave => $_) {
            [$tabla, $nombre] = explode('|', (string) $clave);
            $this->quitarPolitica($tabla, $nombre);
            $cambios[] = "quitada la política {$nombre} de {$tabla}";
        }

        return $cambios;
    }

    /** @param array<string, mixed>|null $instalada fila de USER_POLICIES */
    private function politicaCorrecta(?array $instalada, PoliticaVpd $p, string $esquema): bool
    {
        if (null === $instalada) {
            return false;
        }
        $esperada = [...$p->banderas(), 'PF_OWNER' => $esquema, 'PACKAGE' => 'INV_VPD', 'FUNCTION' => $p->operacion,
            'CHK_OPTION' => 'YES', 'ENABLE' => 'YES', 'POLICY_TYPE' => 'DYNAMIC'];
        foreach ($esperada as $columna => $valor) {
            if ((string) ($instalada[$columna] ?? '') !== $valor) {
                return false;
            }
        }

        return true;
    }

    private function quitarPolitica(string $tabla, string $nombre): void
    {
        $this->conexion->executeStatement('BEGIN DBMS_RLS.DROP_POLICY(USER, :t, :n); END;', ['t' => $tabla, 'n' => $nombre]);
    }

    /** @return array<string, array<string, mixed>> «tabla|política» => fila de USER_POLICIES */
    private function politicasInstaladas(): array
    {
        $filas = $this->conexion->fetchAllAssociative(
            'SELECT object_name, policy_name, pf_owner, package, function, sel, ins, upd, del, chk_option, enable, policy_type '
            ."FROM user_policies WHERE policy_name LIKE 'INV\\_RLS\\_%' ESCAPE '\\'");
        $mapa = [];
        foreach ($filas as $f) {
            $mapa["{$f['OBJECT_NAME']}|{$f['POLICY_NAME']}"] = $f;
        }

        return $mapa;
    }

    /** @return array<string, string> «tabla|operación» => predicado */
    private function predicadosInstalados(): array
    {
        $mapa = [];
        foreach ($this->conexion->fetchAllAssociative('SELECT "VPD_Tabla", "VPD_Operacion", "VPD_Predicado" FROM "INV_VPD_PREDICADO"') as $f) {
            $mapa["{$f['VPD_Tabla']}|{$f['VPD_Operacion']}"] = (string) $f['VPD_Predicado'];
        }

        return $mapa;
    }

    /** @return array<string, true> */
    private function tablas(): array
    {
        return array_fill_keys(array_map('strval', $this->conexion->fetchFirstColumn('SELECT table_name FROM user_tables')), true);
    }

    private function estadoObjeto(ObjetoPlsql $objeto): ?string
    {
        $estado = $this->conexion->fetchOne('SELECT status FROM user_objects WHERE object_name = :n AND object_type = :t',
            ['n' => $objeto->nombre, 't' => $objeto->tipo]);

        return false === $estado ? null : (string) $estado;
    }

    private function fuenteInstalada(ObjetoPlsql $objeto): ?string
    {
        $lineas = $this->conexion->fetchFirstColumn('SELECT text FROM user_source WHERE name = :n AND type = :t ORDER BY line',
            ['n' => $objeto->nombre, 't' => $objeto->tipo]);

        return [] === $lineas ? null : implode('', array_map('strval', $lineas));
    }

    private function tipoAlter(ObjetoPlsql $objeto): string
    {
        return ObjetoPlsql::DISPARADOR === $objeto->tipo ? 'TRIGGER' : 'PACKAGE';
    }

    /** Nombre del esquema; exige que la conexión sea la del dueño (no la del usuario de ejecución). */
    private function esquemaPropio(): string
    {
        $f = $this->conexion->fetchAssociative("SELECT USER AS usuario, SYS_CONTEXT('USERENV', 'CURRENT_SCHEMA') AS esquema FROM DUAL");
        if (false === $f || $f['USUARIO'] !== $f['ESQUEMA']) {
            throw new \RuntimeException('El migrador debe conectarse como el dueño del esquema (ORACLE_USER = ORACLE_SCHEMA_OWNER).');
        }

        return (string) $f['USUARIO'];
    }
}
