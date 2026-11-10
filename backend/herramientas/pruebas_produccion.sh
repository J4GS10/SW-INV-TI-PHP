#!/usr/bin/env bash
# Suite completa del backend contra la base de PRODUCCIÓN (hay un solo entorno), y vuelta
# exacta al estado anterior:
#   1. se detienen api_php y worker_php: el sistema no atiende mientras dura la prueba
#   2. respaldo Data Pump verificado (el punto de vuelta) y se detiene backup_db, para que
#      ningún respaldo automático guarde la base con datos de prueba
#   3. huella de la base: por tabla, filas y suma de hash de cada columna
#   4. suite PHPUnit como dueño del esquema, con dobles de correo, S3, AD y Redis (nunca los reales)
#   5. se restaura el respaldo y el migrador repone VPD, disparadores y permisos
#   6. huella otra vez: debe ser idéntica a la del paso 3; solo entonces se levanta todo
# Si la prueba se corta a mitad, igual se restaura. Si la huella no coincide, el sistema
# queda detenido para revisar (no se levanta con datos distintos del respaldo).
#
#   CONFIRMAR=SI bash herramientas/pruebas_produccion.sh                 # toda la suite
#   CONFIRMAR=SI bash herramientas/pruebas_produccion.sh --filter Login  # argumentos extra para PHPUnit
#   CONFIRMAR=SI SONDEO=SI bash herramientas/pruebas_produccion.sh       # además, datos mal escritos en cada petición:
#                                                                        # 5xx y aceptados quedan en api/var/sondeo-entradas.jsonl
set -uo pipefail
export MSYS_NO_PATHCONV=1
[ "${CONFIRMAR:-}" = "SI" ] || { echo "Detiene el sistema y restaura la base al terminar. Defina CONFIRMAR=SI." >&2; exit 2; }
AQUI="$(cd "$(dirname "$0")" && pwd)"
RAIZ="$(cd "$AQUI/.." && (pwd -W 2>/dev/null || pwd))"
DEPLOY="$RAIZ/deploy"
RED="${RED_PRUEBAS:-inv_php_pruebas}"
SUITE=inv_php_suite_produccion
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

# El dueño tiene EXEMPT ACCESS POLICY para que el respaldo exporte todas las filas. La suite
# corre como dueño: sin quitársela, el VPD no la filtraría (a la aplicación, INVENTARIO_APP, sí).
exencion() { # quitar | devolver
  local sql="grant exempt access policy to $DUENO"
  [ "$1" = quitar ] && sql="revoke exempt access policy from $DUENO"
  printf 'whenever sqlerror exit 1\nalter session set container=%s;\n%s;\nexit\n' "$SERVICIO" "$sql" \
    | docker exec -i inventario-oracle-1 sqlplus -s / as sysdba >/dev/null
}

restaurar() {
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
    bash "$AQUI/desplegar.sh" estado | sed -n '/Prueba de humo/,$p'
    fase=restaurada
  else
    echo "!! La huella NO coincide con la de antes. El sistema queda DETENIDO para revisar:" >&2
    diff "$TMP/antes.txt" "$TMP/despues.txt" | head -20 >&2
    echo "   Respaldo: $dmp (BACKUP_DIR/oracle)." >&2
    fase=diferente
  fi
}

terminar() {
  docker rm -f "$SUITE" mailpit minio-pruebas redis-pruebas samba-ad-pruebas >/dev/null 2>&1 || true
  # Cortada en medio de la suite: la base tiene datos de prueba y se restaura igual.
  [ "$fase" = suite ] && restaurar
  rm -rf "$TMP"
}
trap terminar EXIT

echo "==> Deteniendo la API y el worker (el sistema no atiende durante la prueba)…"
"${stack[@]}" stop api_php worker_php >/dev/null 2>&1
salida="$(bash "$AQUI/desplegar.sh" respaldar 2>&1)"
dmp="$(echo "$salida" | sed -n 's/^Respaldo verificado: \(inventario_[0-9_]*\.dmp\)$/\1/p')"
if [ -z "$dmp" ]; then
  echo "$salida" | tail -5 >&2
  echo "!! Sin respaldo verificado no se prueba. Se vuelve a levantar el sistema sin cambios." >&2
  "${stack[@]}" up -d --no-build api_php worker_php >/dev/null 2>&1
  exit 1
fi
echo "==> Respaldo verificado: $dmp"
"${stack[@]}" stop backup_db >/dev/null 2>&1
huella > "$TMP/antes.txt"
[ -s "$TMP/antes.txt" ] || { echo "!! No se pudo tomar la huella. Se levanta el sistema sin cambios." >&2; "${stack[@]}" up -d --no-build >/dev/null 2>&1; exit 1; }
echo "==> Huella de antes: $(wc -l < "$TMP/antes.txt") tablas."

# Dobles de los servicios externos (nunca el AD ni el SMTP reales).
# shellcheck source=dobles_pruebas.sh
source "$AQUI/dobles_pruebas.sh"
umask 077
{
  echo "APP_ENV=test"
  echo "ORACLE_HOST=oracle"; echo "ORACLE_PORT=1521"; echo "ORACLE_SERVICE_NAME=$SERVICIO"
  echo "ORACLE_USER=$DUENO"; echo "ORACLE_PASSWORD=$(leer ORACLE_OWNER_PASSWORD)"
  echo "ORACLE_APP_PASSWORD=$(leer ORACLE_APP_PASSWORD)"
  # Las claves de producción: los datos cifrados que ya existen se leen igual que en la API.
  for k in SECRET_KEY HMAC_KEYS FIELD_ENCRYPTION_KEY; do echo "$k=$(leer "$k")"; done
  # SONDEO=SI: cada petición de la suite va precedida de sus variantes con datos mal escritos (tests/Soporte/Sondeo.php).
  [ "${SONDEO:-}" = SI ] && echo "SONDEO_ENTRADAS=/repo/api/var/sondeo-entradas.jsonl"
} > "$TMP/suite.env"
[ "${SONDEO:-}" = SI ] && rm -f "$RAIZ/api/var/sondeo-entradas.jsonl"
fase=suite
exencion quitar && echo "==> Dueño sin EXEMPT ACCESS POLICY durante la suite: el VPD lo filtra igual que a la aplicación."
docker rm -f "$SUITE" >/dev/null 2>&1 || true
# docker.exe (Windows) necesita la ruta nativa del archivo.
ENV_SUITE="$(cygpath -w "$TMP/suite.env" 2>/dev/null || echo "$TMP/suite.env")"
if docker create --name "$SUITE" --network "$RED" --env-file "$ENV_SUITE" -v "$RAIZ:/repo" -w /repo/api \
  --user root --entrypoint php inventario-php-dev vendor/bin/phpunit "$@" >/dev/null \
  && docker network connect inventario_internal "$SUITE"; then
  rm -f "$TMP/suite.env"
  echo "==> Suite completa contra la base de producción…"
  docker start -a "$SUITE"
  rc=$?
else
  rm -f "$TMP/suite.env"
  echo "!! No se pudo preparar la suite." >&2
  rc=1
fi
docker rm -f "$SUITE" >/dev/null 2>&1 || true
restaurar
[ "$fase" = restaurada ] || exit 3
exit "$rc"
