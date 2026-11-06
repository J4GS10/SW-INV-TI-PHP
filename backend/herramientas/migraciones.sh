#!/usr/bin/env bash
# Pruebas del migrador de esquema (app:esquema:actualizar) sobre bases Oracle 21c
# desechables, comparando la huella del diccionario (app:esquema:huella):
#
#   bash herramientas/migraciones.sh vacia                 # base vacía → migrar dos veces (la 2.ª sin cambios)
#   bash herramientas/migraciones.sh referencia            # base creada con db/pruebas/esquema_base.sql
#   bash herramientas/migraciones.sh produccion            # huella de producción (solo lectura, como dueño)
#   bash herramientas/migraciones.sh restaurada <dmp>      # respaldo Data Pump restaurado → migrar → sin cambios
#   bash herramientas/migraciones.sh comparar <a> <b>      # diferencias entre dos huellas (vacia, referencia, produccion, restaurada…)
#
# Las huellas quedan en api/var/esquema/<nombre>.txt. Cada base desechable se
# borra al terminar (una Oracle XE usa ~2–3 GB de memoria). `produccion` y
# `restaurada` usan los secretos del stack (deploy/.env y deploy/.env.php) sin
# imprimirlos: el migrador corre con la misma configuración que en producción.
set -euo pipefail
export MSYS_NO_PATHCONV=1
AQUI="$(cd "$(dirname "$0")" && pwd)"
RAIZ="$(cd "$AQUI/.." && (pwd -W 2>/dev/null || pwd))"
SALIDA="$AQUI/../api/var/esquema"
RED=inv_php_migraciones
CLAVE=InventarioMigr2026
IMAGEN=gvenzl/oracle-xe:21-slim-faststart
ENV_STACK="$RAIZ/deploy/.env"
ENV_PHP="$RAIZ/deploy/.env.php"
mkdir -p "$SALIDA"
docker network inspect "$RED" >/dev/null 2>&1 || docker network create "$RED" >/dev/null

# Oracle desechable: nombre, scripts de inicio (carpeta), argumentos extra de docker run…
oracle() {
  local nombre="$1" init="$2"; shift 2
  docker rm -f "$nombre" >/dev/null 2>&1 || true
  docker run -d --name "$nombre" --network "$RED" --shm-size 1g \
    --health-cmd "healthcheck.sh && test -f /opt/oracle/oradata/.inventario_init_ok" \
    --health-interval 10s --health-timeout 5s --health-retries 60 \
    -e ORACLE_PASSWORD="$CLAVE" -e TEST_SCHEMA_PASSWORD="$CLAVE" \
    -v "$init:/container-entrypoint-initdb.d:ro" "$@" "$IMAGEN" >/dev/null
  for _ in $(seq 1 120); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' "$nombre")" = healthy ] && return 0
    sleep 5
  done
  docker logs --tail 30 "$nombre" >&2; return 1
}

# Consola de la API contra una Oracle: host, contraseña del dueño, comando…
# (APP_ENV=test: claves de prueba; sin datos de producción).
consola() {
  local host="$1" clave="$2"; shift 2
  docker run --rm --network "$RED" -v "$RAIZ:/repo" -w /repo/api -e APP_ENV=test -e ORACLE_HOST="$host" \
    -e ORACLE_USER=INVENTARIO -e ORACLE_PASSWORD="$clave" -e ORACLE_SCHEMA_OWNER= -e ORACLE_APP_USER=INVENTARIO_APP \
    inventario-php-dev php bin/console --no-ansi "$@"
}

# Igual, con la configuración y los secretos de producción (APP_ENV=prod).
consola_produccion() {
  local red="$1" host="$2" clave_var="$3"; shift 3
  [ -f "$ENV_STACK" ] && [ -f "$ENV_PHP" ] || { echo "Faltan deploy/.env o deploy/.env.php." >&2; exit 1; }
  docker run --rm --network "$red" --env-file "$ENV_STACK" --env-file "$ENV_PHP" -v "$RAIZ:/repo" -w /repo/api \
    -e APP_ENV=prod -e APP_DEBUG=0 -e ORACLE_HOST="$host" -e CLAVE_VAR="$clave_var" inventario-php-dev sh -c '
      export ORACLE_USER="$ORACLE_SCHEMA_OWNER" ORACLE_PASSWORD="$(printenv "$CLAVE_VAR")" ORACLE_SCHEMA_OWNER=
      php bin/console --no-ansi cache:clear -q && php bin/console --no-ansi "$@"' -- "$@"
}

huella() { # nombre de archivo, función de consola y sus argumentos…
  local archivo="$SALIDA/$1.txt"; shift
  "$@" app:esquema:huella > "$archivo"
  echo "Huella: api/var/esquema/$(basename "$archivo") ($(wc -l < "$archivo") líneas, $(sha256sum "$archivo" | cut -c1-16))"
}

case "${1:-}" in
  vacia)
    oracle inv_php_oracle_migr "$RAIZ/db/pruebas/init"
    echo "== Primera migración (base vacía):"
    consola inv_php_oracle_migr "$CLAVE" app:esquema:actualizar --sin-datos | sed 's/^/  /'
    echo "== Segunda migración (debe quedar sin cambios):"
    segunda="$(consola inv_php_oracle_migr "$CLAVE" app:esquema:actualizar --sin-datos)"
    echo "$segunda" | sed 's/^/  /'
    [ "$(echo "$segunda" | grep -c 'sin cambios\|ninguna pendiente')" -ge 3 ] || { echo "La segunda migración cambió algo." >&2; exit 1; }
    huella vacia consola inv_php_oracle_migr "$CLAVE"
    docker rm -f inv_php_oracle_migr >/dev/null
    ;;
  referencia)
    tmp="$(mktemp -d)"
    cat > "$tmp/01_esquema.sh" <<'INIT'
sqlplus -s / as sysdba <<SQL
whenever sqlerror exit failure
alter session set container = XEPDB1;
create user INVENTARIO identified by "${TEST_SCHEMA_PASSWORD}" default tablespace users quota unlimited on users;
grant create session, create table, create sequence, create view, create procedure, create trigger, create type, create synonym to INVENTARIO;
grant create any context, drop any context to INVENTARIO;
grant execute on sys.dbms_rls to INVENTARIO;
grant execute on sys.dbms_crypto to INVENTARIO;
exit
SQL
sqlplus -s "INVENTARIO/${TEST_SCHEMA_PASSWORD}@localhost/XEPDB1" @/opt/esquema/esquema_base.sql > /tmp/esquema.log && touch /opt/oracle/oradata/.inventario_init_ok
INIT
    oracle inv_php_oracle_ref "$(cd "$tmp" && (pwd -W 2>/dev/null || pwd))" -v "$RAIZ/db/pruebas/esquema_base.sql:/opt/esquema/esquema_base.sql:ro"
    huella referencia consola inv_php_oracle_ref "$CLAVE"
    docker rm -f inv_php_oracle_ref >/dev/null
    rm -rf "$tmp"
    ;;
  produccion)
    consola_produccion inventario_internal oracle ORACLE_OWNER_PASSWORD app:esquema:actualizar --verificar || true
    huella produccion consola_produccion inventario_internal oracle ORACLE_OWNER_PASSWORD
    ;;
  restaurada)
    dmp="$(basename "${2:?Indique el respaldo .dmp (en BACKUP_DIR/oracle)}")"
    respaldos="$(grep -E '^BACKUP_DIR=' "$ENV_STACK" | cut -d= -f2-)"
    [ -f "$respaldos/oracle/$dmp" ] || { echo "No existe $respaldos/oracle/$dmp" >&2; exit 1; }
    # Mismos usuarios, perfil y directorio RESPALDO que producción (deploy/oracle/init), claves desechables.
    oracle inv_php_oracle_rest "$RAIZ/deploy/oracle/init" -e ORACLE_SCHEMA_OWNER=INVENTARIO -e ORACLE_OWNER_PASSWORD="$CLAVE" \
      -e ORACLE_APP_USER=INVENTARIO_APP -e ORACLE_APP_PASSWORD="$CLAVE" -v "$respaldos/oracle:/opt/oracle/respaldos:ro" \
      -v "$RAIZ/deploy/backup:/backup:ro"
    echo "== Restaurando $dmp…"
    docker exec -e CONFIRMAR=SI -e ORACLE_HOST=localhost -e ORACLE_SCHEMA_OWNER=INVENTARIO -e ORACLE_OWNER_PASSWORD="$CLAVE" \
      inv_php_oracle_rest bash /backup/restaurar_db.sh "$dmp" 2>&1 | grep -E 'Restaur|completed|ORA-' | grep -v 'ORA-31684' | sed 's/^/  /' || true
    huella restaurada_antes consola inv_php_oracle_rest "$CLAVE"
    # Las claves de producción (HMAC_KEYS, FIELD_ENCRYPTION_KEY) hacen falta para que las huellas de licencias no cambien.
    migrar() {
      docker run --rm --network "$RED" --env-file "$ENV_STACK" --env-file "$ENV_PHP" -v "$RAIZ:/repo" -w /repo/api \
        -e APP_ENV=prod -e APP_DEBUG=0 -e ORACLE_HOST=inv_php_oracle_rest -e ORACLE_USER=INVENTARIO -e ORACLE_PASSWORD="$CLAVE" \
        -e ORACLE_SCHEMA_OWNER= inventario-php-dev sh -c 'php bin/console cache:clear -q && php bin/console --no-ansi app:esquema:actualizar' 2>/dev/null
    }
    # El respaldo del dueño no incluye las políticas VPD: la primera corrida las repone
    # (y registra la línea base sin SQL); la segunda no debe cambiar nada.
    echo "== Migrador con la configuración de producción (primera corrida):"
    migrar | sed 's/^/  /'
    echo "== Segunda corrida (debe quedar sin cambios):"
    segunda="$(migrar)"
    echo "$segunda" | sed 's/^/  /'
    huella restaurada consola inv_php_oracle_rest "$CLAVE"
    [ "$(echo "$segunda" | grep -c 'sin cambios\|ninguna pendiente\|ya existía\|0 filas nuevas\|0 actualizadas')" -ge 6 ] \
      || { echo "La segunda corrida cambió algo." >&2; exit 1; }
    if [ -f "$SALIDA/produccion.txt" ]; then
      diff -q "$SALIDA/produccion.txt" "$SALIDA/restaurada.txt" >/dev/null \
        && echo "La base restaurada y migrada es idéntica a producción." \
        || { echo "La base restaurada difiere de producción:"; diff "$SALIDA/produccion.txt" "$SALIDA/restaurada.txt" | head -40; exit 1; }
    fi
    docker rm -f inv_php_oracle_rest >/dev/null
    ;;
  comparar)
    a="$SALIDA/${2:?huella A}.txt"; b="$SALIDA/${3:?huella B}.txt"
    if diff -q "$a" "$b" >/dev/null; then echo "Huellas iguales: $2 = $3"; else echo "Diferencias $2 → $3:"; diff "$a" "$b"; exit 1; fi
    ;;
  *)
    sed -n '2,13p' "$0"; exit 2
    ;;
esac
