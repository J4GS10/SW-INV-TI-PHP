#!/usr/bin/env bash
# Ventana de prueba sobre PRODUCCIÓN (hay un solo entorno) y vuelta exacta al estado anterior.
# La comparten herramientas/pruebas_produccion.sh (PHPUnit) y herramientas/qa/e2e.sh (Playwright),
# que la cargan con `source`:
#
#   abrir_ventana   detiene api_php y worker_php (el sistema no atiende), hace un respaldo Data Pump
#                   verificado (el punto de vuelta), detiene backup_db (ningún respaldo automático
#                   guarda datos de prueba) y toma la huella de la base: por tabla, filas y suma de
#                   hash de cada columna
#   cerrar_ventana  restaura el respaldo, corre el migrador (VPD, disparadores, permisos) y compara la
#                   huella: solo si es idéntica levanta todo; si no, deja el sistema detenido para revisar
#   exencion        quitar | devolver EXEMPT ACCESS POLICY al dueño del esquema
#
# Define HERRAMIENTAS, RAIZ, DEPLOY, stack, leer, DUENO, SERVICIO, TMP, dmp y fase
# (inicio → abierta → restaurada | diferente). Quien la carga llama a cerrar_ventana al salir si
# fase=abierta (también si la prueba se corta a mitad).
export MSYS_NO_PATHCONV=1
HERRAMIENTAS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RAIZ="$(cd "$HERRAMIENTAS/.." && (pwd -W 2>/dev/null || pwd))"
DEPLOY="$RAIZ/deploy"
stack=(docker compose -p inventario --project-directory "$DEPLOY" --env-file "$DEPLOY/.env" --env-file "$DEPLOY/.env.php"
  -f "$DEPLOY/docker-compose.yml")
leer() { grep -E "^$1=" "$DEPLOY/.env" | head -1 | cut -d= -f2-; }
DUENO="$(leer ORACLE_SCHEMA_OWNER)"; DUENO="${DUENO:-INVENTARIO}"
SERVICIO="$(leer ORACLE_SERVICE_NAME)"; SERVICIO="${SERVICIO:-XEPDB1}"
TMP="$(mktemp -d)"
fase=inicio
dmp=""

# Por tabla: filas y suma de ORA_HASH por columna (de los LOB, su largo). Igual antes y después = mismos datos.
huella() {
  printf 'connect %s/"%s"@//localhost:1521/%s\n%s\n' "$DUENO" "$(leer ORACLE_OWNER_PASSWORD)" "$SERVICIO" "$(cat <<'SQL'
set serveroutput on size unlimited feedback off heading off pagesize 0 linesize 400
-- Las filas con borrado lógico (ROW ARCHIVAL) también cuentan: la vuelta debe dejarlas idénticas.
alter session set row archival visibility = all;
declare
  v_sql varchar2(32767); v_res varchar2(4000); v_h number;
begin
  for t in (select table_name from user_tables where temporary = 'N' and table_name not like 'SYS!_EXPORT!_%' escape '!'
            and table_name not like 'BIN$%' order by table_name) loop
    v_sql := 'select to_char(count(*))';
    for c in (select column_name, data_type from user_tab_columns where table_name = t.table_name order by column_id) loop
      if c.data_type in ('CLOB', 'BLOB', 'NCLOB') then
        v_sql := v_sql || q'[||':'||nvl(sum(dbms_lob.getlength("]' || c.column_name || q'[")),0)]';
      elsif c.data_type not like 'LONG%' and c.data_type not like '%XML%' then
        v_sql := v_sql || q'[||':'||nvl(sum(ora_hash("]' || c.column_name || q'[")),0)]';
      end if;
    end loop;
    execute immediate v_sql || ' from "' || t.table_name || '"' into v_res;
    select ora_hash(v_res) into v_h from dual;
    dbms_output.put_line(t.table_name || ' ' || regexp_substr(v_res, '^[0-9]+') || ' ' || v_h);
  end loop;
end;
/
exit
SQL
)" | docker exec -i inventario-oracle-1 sqlplus -s /nolog | grep -E '^[A-Z0-9_$#]+ [0-9]+ [0-9]+$'
}

# El dueño tiene EXEMPT ACCESS POLICY para que el respaldo exporte todas las filas.
exencion() { # quitar | devolver
  local sql="grant exempt access policy to $DUENO"
  [ "$1" = quitar ] && sql="revoke exempt access policy from $DUENO"
  printf 'whenever sqlerror exit 1\nalter session set container=%s;\n%s;\nexit\n' "$SERVICIO" "$sql" \
    | docker exec -i inventario-oracle-1 sqlplus -s / as sysdba >/dev/null
}

# 0 = ventana abierta (respaldo y huella tomados); 1 = no se pudo y el sistema vuelve a atender sin cambios.
abrir_ventana() {
  echo "==> Deteniendo la API y el worker (el sistema no atiende durante la prueba)…"
  "${stack[@]}" stop api_php worker_php >/dev/null 2>&1
  local salida
  salida="$(bash "$HERRAMIENTAS/desplegar.sh" respaldar 2>&1)"
  dmp="$(echo "$salida" | sed -n 's/^Respaldo verificado: \(inventario_[0-9_]*\.dmp\)$/\1/p')"
  if [ -z "$dmp" ]; then
    echo "$salida" | tail -5 >&2
    echo "!! Sin respaldo verificado no se prueba. Se vuelve a levantar el sistema sin cambios." >&2
    "${stack[@]}" up -d --no-build api_php worker_php >/dev/null 2>&1
    return 1
  fi
  echo "==> Respaldo verificado: $dmp"
  "${stack[@]}" stop backup_db >/dev/null 2>&1
  huella > "$TMP/antes.txt"
  if [ ! -s "$TMP/antes.txt" ]; then
    echo "!! No se pudo tomar la huella. Se levanta el sistema sin cambios." >&2
    "${stack[@]}" up -d --no-build >/dev/null 2>&1
    return 1
  fi
  echo "==> Huella de antes: $(wc -l < "$TMP/antes.txt") tablas."
  fase=abierta
}

cerrar_ventana() {
  # La restauración y los respaldos la necesitan: se devuelve antes que nada.
  exencion devolver || echo "!! No se pudo devolver EXEMPT ACCESS POLICY a $DUENO." >&2
  echo "==> Restaurando $dmp…"
  "${stack[@]}" run --rm --no-deps --entrypoint /bin/bash -e CONFIRMAR=SI backup_db /backup/restaurar_db.sh "$dmp" 2>&1 | grep -vE 'ORA-31684|already exists' | tail -6
  echo "==> Migrador (VPD, disparadores, permisos)…"
  "${stack[@]}" run --rm --no-deps migrador 2>&1 | tail -6
  huella > "$TMP/despues.txt"
  if [ -s "$TMP/despues.txt" ] && cmp -s "$TMP/antes.txt" "$TMP/despues.txt"; then
    echo "==> Huella idéntica a la de antes: $(wc -l < "$TMP/antes.txt") tablas, mismas filas y mismos valores."
    "${stack[@]}" up -d --no-build --wait --wait-timeout 900 >/dev/null 2>&1
    bash "$HERRAMIENTAS/desplegar.sh" estado | sed -n '/Prueba de humo/,$p'
    fase=restaurada
  else
    echo "!! La huella NO coincide con la de antes. El sistema queda DETENIDO para revisar:" >&2
    diff "$TMP/antes.txt" "$TMP/despues.txt" | head -20 >&2
    echo "   Respaldo: $dmp (BACKUP_DIR/oracle)." >&2
    fase=diferente
  fi
}
