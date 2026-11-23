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
# shellcheck source=ventana_produccion.sh
source "$AQUI/ventana_produccion.sh"
RED="${RED_PRUEBAS:-inv_php_pruebas}"
SUITE=inv_php_suite_produccion

terminar() {
  docker rm -f "$SUITE" mailpit minio-pruebas redis-pruebas samba-ad-pruebas >/dev/null 2>&1 || true
  # Cortada en medio de la suite: la base puede tener datos de prueba y se restaura igual.
  [ "$fase" = abierta ] && cerrar_ventana
  rm -rf "$TMP"
}
trap terminar EXIT

abrir_ventana || exit 1

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
cerrar_ventana
[ "$fase" = restaurada ] || exit 3
exit "$rc"
