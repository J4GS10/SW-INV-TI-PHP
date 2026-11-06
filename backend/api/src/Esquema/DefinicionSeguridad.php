<?php

declare(strict_types=1);

namespace App\Esquema;

/**
 * Objetos PL/SQL de la seguridad en la base (Oracle 21c), en orden de instalación:
 *
 * - INV_CTX: único que puede escribir el contexto de aplicación `<ESQUEMA>_CTX`
 *   (usuario de la petición; modo PURGA de la retención de la bitácora).
 * - INV_VPD: funciones de las políticas DBMS_RLS (predicados en INV_VPD_PREDICADO).
 * - INV_KARDEX_PKG + trg_kardex_sellar: sello encadenado del kardex. Al insertar,
 *   la base asigna KDX_Secuencia (consecutiva) y KDX_Hash = SHA-256(hash anterior
 *   + '|' + JSON del evento); el último eslabón vive en la fila centinela
 *   INV_KARDEX_SELLO, que el disparador bloquea (los sellados se serializan).
 * - Disparadores de inmutabilidad: bitácora y kardex de solo inserción,
 *   aprobaciones sin borrado, identidades solo en modo elevado y sin TRUNCATE.
 *
 * Es el mismo código que instaló el migrador anterior (equivalente salvo
 * comentarios y espacios: ver FuentePlsql), así reinstalar no recompila nada.
 */
final readonly class DefinicionSeguridad
{
    // Errores propios (RAISE_APPLICATION_ERROR); App\Db\ErroresOracle los traduce.
    public const int ERR_AUDITORIA_INMUTABLE = 20001;
    public const int ERR_KARDEX_INMUTABLE = 20002;
    public const int ERR_IDENTIDAD_ELEVADA = 20003;
    public const int ERR_APROBACION_SIN_BORRADO = 20004;
    public const int ERR_TABLA_SIN_VACIADO = 20005;

    // Columnas de INV_USUARIO que deciden el acceso: solo se escriben en modo elevado.
    public const array COLUMNAS_IDENTIDAD = ['USU_Usuario', 'USU_Username', 'USU_Rol', 'USU_Alcance_Global', 'USU_Estado', 'PER_Persona'];

    /**
     * @param list<string> $columnasKardex columnas de INV_KARDEX (todas: el hash se excluye aquí)
     * @param list<string> $rolesGlobales  roles que nunca reciben predicado
     * @param list<string> $rolesSinDatos  roles que administran el sistema: no leen datos de negocio
     */
    public function __construct(
        public string $esquema,
        private array $columnasKardex,
        private array $rolesGlobales,
        private array $rolesSinDatos = [],
    ) {
        if (1 !== preg_match('/^[A-Z][A-Z0-9_]{0,123}$/', $esquema)) {
            throw new \InvalidArgumentException("Esquema inválido: {$esquema}");
        }
    }

    public function contexto(): string
    {
        return "{$this->esquema}_CTX";
    }

    public function ddlContexto(): string
    {
        return "CREATE OR REPLACE CONTEXT {$this->contexto()} USING {$this->esquema}.INV_CTX";
    }

    /** @return list<ObjetoPlsql> especificaciones antes que cuerpos; disparadores al final */
    public function objetos(): array
    {
        $ctx = $this->contexto();
        $usuario = "SYS_CONTEXT('{$ctx}', 'USUARIO')";
        $modo = "SYS_CONTEXT('{$ctx}', 'MODO')";
        $globales = implode(', ', array_map(static fn (string $r): string => "'{$r}'", $this->rolesGlobales));
        $sinDatos = implode(', ', array_map(static fn (string $r): string => "'{$r}'", $this->rolesSinDatos)) ?: 'NULL';
        // Lo único que necesitan para vincular cuentas y revisar la bitácora.
        $tablasDeSistema = "'INV_PERSONA', 'INV_AUDITORIA_SISTEMA'";
        $identidad = implode(', ', array_map(static fn (string $c): string => "\"{$c}\"", self::COLUMNAS_IDENTIDAD));
        $eventoTabla = $this->jsonEvento('k');
        $eventoNuevo = $this->jsonEvento(':NEW');
        [$errAuditoria, $errKardex, $errIdentidad, $errAprobacion, $errVaciado] = [self::ERR_AUDITORIA_INMUTABLE,
            self::ERR_KARDEX_INMUTABLE, self::ERR_IDENTIDAD_ELEVADA, self::ERR_APROBACION_SIN_BORRADO, self::ERR_TABLA_SIN_VACIADO];
        $p = ObjetoPlsql::PAQUETE;
        $c = ObjetoPlsql::CUERPO;
        $t = ObjetoPlsql::DISPARADOR;

        return [
            new ObjetoPlsql($p, 'INV_KARDEX_PKG', <<<'SQL'
                CREATE OR REPLACE PACKAGE INV_KARDEX_PKG AUTHID DEFINER AS
                    FUNCTION sello(p_anterior IN VARCHAR2, p_evento IN CLOB) RETURN VARCHAR2;
                    FUNCTION hash_evento(p_evento IN NUMBER) RETURN VARCHAR2;
                END INV_KARDEX_PKG;
                SQL),
            new ObjetoPlsql($p, 'INV_CTX', <<<'SQL'
                CREATE OR REPLACE PACKAGE INV_CTX AUTHID DEFINER AS
                    PROCEDURE fijar_usuario(p_usuario IN VARCHAR2);
                    PROCEDURE limpiar;
                    PROCEDURE modo(p_modo IN VARCHAR2);
                END INV_CTX;
                SQL),
            new ObjetoPlsql($p, 'INV_VPD', <<<'SQL'
                CREATE OR REPLACE PACKAGE INV_VPD AUTHID DEFINER AS
                    FUNCTION lectura(p_esquema IN VARCHAR2, p_objeto IN VARCHAR2) RETURN VARCHAR2;
                    FUNCTION escritura(p_esquema IN VARCHAR2, p_objeto IN VARCHAR2) RETURN VARCHAR2;
                    FUNCTION insercion(p_esquema IN VARCHAR2, p_objeto IN VARCHAR2) RETURN VARCHAR2;
                    FUNCTION persona_sede(p_persona IN VARCHAR2) RETURN NUMBER;
                END INV_VPD;
                SQL),
            new ObjetoPlsql($c, 'INV_KARDEX_PKG', <<<SQL
                CREATE OR REPLACE PACKAGE BODY INV_KARDEX_PKG AS
                    FUNCTION sello(p_anterior IN VARCHAR2, p_evento IN CLOB) RETURN VARCHAR2 IS
                    BEGIN
                        RETURN LOWER(RAWTOHEX(DBMS_CRYPTO.HASH(
                            TO_CLOB(p_anterior) || TO_CLOB('|') || p_evento, DBMS_CRYPTO.HASH_SH256)));
                    END;

                    FUNCTION hash_evento(p_evento IN NUMBER) RETURN VARCHAR2 IS
                        v_anterior VARCHAR2(64);
                        v_json CLOB;
                    BEGIN
                        SELECT k."KDX_Hash_Anterior", {$eventoTabla}
                          INTO v_anterior, v_json
                          FROM "INV_KARDEX" k WHERE k."KDX_Evento" = p_evento;
                        RETURN sello(v_anterior, v_json);
                    END;
                END INV_KARDEX_PKG;
                SQL),
            // El modo MANTENIMIENTO (vaciar tablas inmutables) no existe en este esquema.
            new ObjetoPlsql($c, 'INV_CTX', <<<SQL
                CREATE OR REPLACE PACKAGE BODY INV_CTX AS
                    c_ns CONSTANT VARCHAR2(128) := '{$ctx}';
                    c_permite_mantenimiento CONSTANT BOOLEAN := FALSE;

                    PROCEDURE fijar_usuario(p_usuario IN VARCHAR2) IS
                    BEGIN
                        IF p_usuario IS NULL OR NOT REGEXP_LIKE(p_usuario, '^[0-9a-f]{32}$') THEN
                            RAISE_APPLICATION_ERROR(-20010, 'Usuario de contexto inválido');
                        END IF;
                        DBMS_SESSION.CLEAR_CONTEXT(c_ns);
                        DBMS_SESSION.SET_CONTEXT(c_ns, 'USUARIO', p_usuario);
                    END;

                    PROCEDURE limpiar IS
                    BEGIN
                        DBMS_SESSION.CLEAR_CONTEXT(c_ns);
                    END;

                    PROCEDURE modo(p_modo IN VARCHAR2) IS
                    BEGIN
                        IF p_modo = 'MANTENIMIENTO' AND NOT c_permite_mantenimiento THEN
                            RAISE_APPLICATION_ERROR(-20011, 'El modo mantenimiento solo existe en esquemas de prueba');
                        ELSIF p_modo NOT IN ('MANTENIMIENTO', 'PURGA') THEN
                            RAISE_APPLICATION_ERROR(-20012, 'Modo de contexto desconocido');
                        END IF;
                        DBMS_SESSION.SET_CONTEXT(c_ns, 'MODO', p_modo);
                    END;
                END INV_CTX;
                SQL),
            new ObjetoPlsql($c, 'INV_VPD', <<<SQL
                CREATE OR REPLACE PACKAGE BODY INV_VPD AS
                    g_omitir BOOLEAN := FALSE;

                    FUNCTION predicado(p_objeto IN VARCHAR2, p_operacion IN VARCHAR2) RETURN VARCHAR2 IS
                        v_usuario VARCHAR2(64) := {$usuario};
                        v_global NUMBER;
                        v_sin_datos NUMBER;
                        v_predicado VARCHAR2(4000);
                    BEGIN
                        IF v_usuario IS NULL OR g_omitir THEN
                            RETURN NULL;  -- modo sistema
                        END IF;
                        IF p_operacion = 'LECTURA' AND p_objeto NOT IN ({$tablasDeSistema}) THEN
                            SELECT COUNT(*) INTO v_sin_datos FROM "INV_USUARIO" u
                             WHERE u."USU_Usuario" = v_usuario AND u."USU_Rol" IN ({$sinDatos});
                            IF v_sin_datos > 0 THEN
                                RETURN '1 = 0';
                            END IF;
                        END IF;
                        SELECT COUNT(*) INTO v_global FROM "INV_USUARIO" u
                         WHERE u."USU_Usuario" = v_usuario AND u."USU_Estado" = 1
                           AND (u."USU_Alcance_Global" = 1 OR u."USU_Rol" IN ({$globales}));
                        IF v_global > 0 THEN
                            -- Alcance global no da acceso a archivos de licencia: solo a administradores de TI.
                            IF p_objeto = 'INV_ADJUNTO' AND p_operacion = 'LECTURA' THEN
                                SELECT COUNT(*) INTO v_global FROM "INV_USUARIO" u
                                 WHERE u."USU_Usuario" = v_usuario AND u."USU_Rol" = 'ADMIN_TI';
                                IF v_global = 0 THEN
                                    RETURN '"LIC_Licencia" IS NULL';
                                END IF;
                            END IF;
                            RETURN NULL;
                        END IF;
                        SELECT "VPD_Predicado" INTO v_predicado FROM "INV_VPD_PREDICADO"
                         WHERE "VPD_Tabla" = p_objeto AND "VPD_Operacion" = p_operacion;
                        RETURN v_predicado;
                    EXCEPTION
                        WHEN NO_DATA_FOUND THEN
                            RETURN '1 = 0';  -- seguro por defecto
                    END;

                    FUNCTION lectura(p_esquema IN VARCHAR2, p_objeto IN VARCHAR2) RETURN VARCHAR2 IS
                    BEGIN RETURN predicado(p_objeto, 'LECTURA'); END;

                    FUNCTION escritura(p_esquema IN VARCHAR2, p_objeto IN VARCHAR2) RETURN VARCHAR2 IS
                    BEGIN RETURN predicado(p_objeto, 'ESCRITURA'); END;

                    FUNCTION insercion(p_esquema IN VARCHAR2, p_objeto IN VARCHAR2) RETURN VARCHAR2 IS
                    BEGIN RETURN predicado(p_objeto, 'INSERCION'); END;

                    FUNCTION persona_sede(p_persona IN VARCHAR2) RETURN NUMBER IS
                        v_sede NUMBER;
                    BEGIN
                        g_omitir := TRUE;
                        BEGIN
                            SELECT p."SED_Sede" INTO v_sede FROM "INV_PERSONA" p WHERE p."PER_Persona" = p_persona;
                        EXCEPTION
                            WHEN NO_DATA_FOUND THEN v_sede := NULL;
                        END;
                        g_omitir := FALSE;
                        RETURN v_sede;
                    EXCEPTION
                        WHEN OTHERS THEN
                            g_omitir := FALSE;
                            RAISE;
                    END;
                END INV_VPD;
                SQL),
            new ObjetoPlsql($t, 'TRG_KARDEX_SELLAR', <<<SQL
                CREATE OR REPLACE TRIGGER trg_kardex_sellar
                    BEFORE INSERT ON "INV_KARDEX" FOR EACH ROW
                    DECLARE
                        v_secuencia NUMBER;
                        v_anterior VARCHAR2(64);
                        v_json CLOB;
                    BEGIN
                        -- Un solo sellado a la vez: la secuencia queda consecutiva y sin bifurcaciones.
                        SELECT "SEL_Secuencia", "SEL_Hash" INTO v_secuencia, v_anterior
                          FROM "INV_KARDEX_SELLO" WHERE "SEL_Id" = 1 FOR UPDATE;
                        :NEW."KDX_Secuencia" := v_secuencia + 1;
                        :NEW."KDX_Hash_Anterior" := v_anterior;
                        :NEW."KDX_Hash" := NULL;
                        SELECT {$eventoNuevo} INTO v_json FROM DUAL;
                        :NEW."KDX_Hash" := INV_KARDEX_PKG.sello(v_anterior, v_json);
                        UPDATE "INV_KARDEX_SELLO" SET "SEL_Secuencia" = :NEW."KDX_Secuencia", "SEL_Hash" = :NEW."KDX_Hash"
                         WHERE "SEL_Id" = 1;
                    END;
                SQL),
            new ObjetoPlsql($t, 'TRG_KARDEX_APPEND_ONLY', <<<SQL
                CREATE OR REPLACE TRIGGER trg_kardex_append_only
                    BEFORE UPDATE OR DELETE ON "INV_KARDEX" FOR EACH ROW
                    BEGIN
                        IF DELETING AND {$modo} = 'MANTENIMIENTO' THEN
                            RETURN;
                        END IF;
                        RAISE_APPLICATION_ERROR(-{$errKardex}, 'INV_KARDEX es de solo inserción');
                    END;
                SQL),
            new ObjetoPlsql($t, 'TRG_AUDITORIA_APPEND_ONLY', <<<SQL
                CREATE OR REPLACE TRIGGER trg_auditoria_append_only
                    BEFORE UPDATE OR DELETE ON "INV_AUDITORIA_SISTEMA" FOR EACH ROW
                    BEGIN
                        IF DELETING AND {$modo} = 'MANTENIMIENTO' THEN
                            RETURN;
                        END IF;
                        -- Retención: solo registros de más de un año (App\\Tareas\\MantenimientoSeguridad).
                        IF DELETING AND {$modo} = 'PURGA' AND :OLD."AUD_Fecha_Hora" < SYSTIMESTAMP - INTERVAL '365' DAY THEN
                            RETURN;
                        END IF;
                        RAISE_APPLICATION_ERROR(-{$errAuditoria}, 'INV_AUDITORIA_SISTEMA is append-only');
                    END;
                SQL),
            new ObjetoPlsql($t, 'TRG_APROBACION_SIN_BORRADO', <<<SQL
                CREATE OR REPLACE TRIGGER trg_aprobacion_sin_borrado
                    BEFORE DELETE ON "INV_APROBACION" FOR EACH ROW
                    BEGIN
                        IF {$usuario} IS NOT NULL THEN
                            RAISE_APPLICATION_ERROR(-{$errAprobacion},
                                'Las solicitudes de aprobación no se borran: se resuelven o anulan');
                        END IF;
                    END;
                SQL),
            new ObjetoPlsql($t, 'TRG_USUARIO_IDENTIDAD', <<<SQL
                CREATE OR REPLACE TRIGGER trg_usuario_identidad
                    BEFORE INSERT OR DELETE OR UPDATE OF {$identidad} ON "INV_USUARIO" FOR EACH ROW
                    BEGIN
                        IF {$usuario} IS NOT NULL THEN
                            RAISE_APPLICATION_ERROR(-{$errIdentidad},
                                'Las identidades solo se modifican en modo elevado');
                        END IF;
                    END;
                SQL),
            new ObjetoPlsql($t, 'TRG_USUARIO_SEDE_IDENTIDAD', <<<SQL
                CREATE OR REPLACE TRIGGER trg_usuario_sede_identidad
                    BEFORE INSERT OR UPDATE OR DELETE ON "INV_USUARIO_SEDE" FOR EACH ROW
                    BEGIN
                        IF {$usuario} IS NOT NULL THEN
                            RAISE_APPLICATION_ERROR(-{$errIdentidad},
                                'El alcance por sede solo se modifica en modo elevado');
                        END IF;
                    END;
                SQL),
            new ObjetoPlsql($t, 'TRG_SIN_VACIAR', <<<SQL
                CREATE OR REPLACE TRIGGER trg_sin_vaciar
                    BEFORE TRUNCATE ON SCHEMA
                    BEGIN
                        IF ORA_DICT_OBJ_NAME IN ('INV_AUDITORIA_SISTEMA', 'INV_KARDEX')
                           AND NVL({$modo}, '-') <> 'MANTENIMIENTO' THEN
                            RAISE_APPLICATION_ERROR(-{$errVaciado}, ORA_DICT_OBJ_NAME || ' no se puede vaciar');
                        END IF;
                    END;
                SQL),
        ];
    }

    /**
     * JSON_OBJECT del evento: todas las columnas salvo el propio hash, en orden
     * binario (el mismo texto al sellar y al verificar), sin las nulas.
     * `$origen` es ':NEW' (disparador) o el alias de la tabla.
     */
    private function jsonEvento(string $origen): string
    {
        $columnas = array_values(array_filter($this->columnasKardex, static fn (string $c): bool => 'KDX_Hash' !== $c));
        usort($columnas, strcmp(...));
        $partes = array_map(
            static fn (string $c): string => "'{$c}' VALUE {$origen}.\"{$c}\"".('KDX_Detalle' === $c ? ' FORMAT JSON' : ''),
            $columnas,
        );

        return 'JSON_OBJECT('.implode(', ', $partes).' ABSENT ON NULL RETURNING CLOB)';
    }
}
