#!/bin/bash
# Restaura un respaldo de Data Pump. DESTRUCTIVO: reemplaza las tablas del esquema.
# Uso (stack del repo PHP; ver herramientas/desplegar.sh, que fija el proyecto y los .env):
#   docker compose stop api_php worker_php
#   docker compose run --rm --no-deps --entrypoint /bin/bash -e CONFIRMAR=SI backup_db \
#     /backup/restaurar_db.sh inventario_AAAAMMDD_HHMMSS.dmp
#   docker compose up -d migrador api_php worker_php
# --entrypoint es obligatorio: el de backup_db es respaldo_db.sh y, sin él, se
# ejecutaría un respaldo en vez de la restauración. En Git Bash, anteponga
# MSYS_NO_PATHCONV=1. Los avisos ORA-31684 («already exists») de paquetes y del
# disparador TRG_SIN_VACIAR son esperables.
# El archivo debe estar en BACKUP_DIR/oracle. Al terminar, el migrador
# (app:esquema:actualizar) repone las políticas VPD que se pierden al reemplazar
# las tablas y concede los permisos del usuario de ejecución.
set -euo pipefail
archivo="${1:?Indique el archivo .dmp a restaurar (nombre dentro de BACKUP_DIR/oracle)}"
archivo="$(basename "$archivo")"
[ "${CONFIRMAR:-}" = "SI" ] || { echo "Defina CONFIRMAR=SI para restaurar (reemplaza el esquema actual)."; exit 1; }
[ -f "/opt/oracle/respaldos/$archivo" ] || { echo "No existe /opt/oracle/respaldos/$archivo"; exit 1; }
CONEXION="//${ORACLE_HOST:-oracle}:${ORACLE_PORT:-1521}/${ORACLE_SERVICE_NAME:-XEPDB1}"
PARFILE="$(mktemp)"
chmod 600 "$PARFILE"
trap 'rm -f "$PARFILE"' EXIT
printf 'userid="%s/%s@%s"\n' "$ORACLE_SCHEMA_OWNER" "$ORACLE_OWNER_PASSWORD" "$CONEXION" > "$PARFILE"
# Respaldos anteriores a 006 pueden traer tablas maestras de expdp interrumpidos.
echo "exclude=TABLE:\"LIKE 'SYS!_EXPORT!_%' ESCAPE '!'\"" >> "$PARFILE"
echo "Restaurando $archivo en el esquema $ORACLE_SCHEMA_OWNER…"
# nologfile: el registro sale por consola y Oracle solo necesita LEER el respaldo
# (en un servidor nuevo, la réplica o el NAS pueden no ser escribibles para él).
impdp parfile="$PARFILE" directory=RESPALDO dumpfile="$archivo" \
  nologfile=y table_exists_action=REPLACE || rc=$?
# impdp sale con 5 (EX_SUCC_ERR) ante los avisos «already exists»; cualquier otro
# código es una falla real. Luego se recompila y se cuentan objetos inválidos
# (la contraseña va por stdin, no en los argumentos).
[ "${rc:-0}" = "0" ] || [ "${rc:-0}" = "5" ] || { echo "impdp falló (código $rc)."; exit "$rc"; }
invalidos="$(printf 'connect %s/"%s"@%s\nset heading off feedback off pagesize 0\nexec DBMS_UTILITY.COMPILE_SCHEMA(USER, FALSE)\nselect count(*) from user_objects where status <> %s;\nexit\n' \
  "$ORACLE_SCHEMA_OWNER" "$ORACLE_OWNER_PASSWORD" "$CONEXION" "'VALID'" | sqlplus -s /nolog | tr -d '[:space:]')"
[ "$invalidos" = "0" ] || { echo "Quedaron objetos inválidos tras restaurar: $invalidos"; exit 1; }
echo "Restauración terminada. Ejecute: docker compose up -d migrador api_php worker_php"
