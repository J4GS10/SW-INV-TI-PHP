#!/usr/bin/env bash
# Comprueba que los datos sobreviven: escribe datos reales en QA por la API y los verifica tras
#   1) reiniciar todos los servicios, 2) bajar y volver a crear los contenedores (como un despliegue,
#   con el migrador), 3) matar Oracle y MinIO de golpe, 4) perder los volúmenes de Oracle y MinIO y
#   restaurar desde respaldo.
# Solo QA: nunca toca el proyecto `inventario`. No usa `entorno.sh sembrar`, que restablece las cuentas.
#
#   bash herramientas/qa/persistencia.sh
#
# Evidencia: api/var/qa/evidencia/persistencia.txt (no versionada).
set -uo pipefail
export MSYS_NO_PATHCONV=1
AQUI="$(cd "$(dirname "$0")" && pwd)"
RAIZ_POSIX="$(cd "$AQUI/../.." && pwd)"
RAIZ="$(cd "$RAIZ_POSIX" && (pwd -W 2>/dev/null || pwd))"
QA_POSIX="$RAIZ_POSIX/api/var/qa"
QA_DIR="$(cd "$QA_POSIX" && (pwd -W 2>/dev/null || pwd))"
ENV_QA="$QA_DIR/qa.env"
EVIDENCIA="$QA_POSIX/evidencia/persistencia.txt"
compose() { bash "$AQUI/entorno.sh" compose "$@"; }
fallos=0

registrar() { tee -a "$EVIDENCIA"; }

ejecutar() { # write | verify <ronda>
  local nombre=inventario_qa_persistencia_$$
  docker create --name "$nombre" --network inventario_qa_salida --env-file "$ENV_QA" -e ENVIRONMENT=qa \
    -e QA_URL=https://qa.inventario.local -e QA_MAILPIT=http://mailpit:8025 \
    -v "$RAIZ:/repo:ro" -v "$QA_DIR:/qa" --entrypoint php inventario-php-dev /repo/herramientas/qa/persistencia.php "$@" >/dev/null
  docker network connect inventario_qa_internal "$nombre"
  docker start -a "$nombre" 2>&1 | registrar
  local rc=${PIPESTATUS[0]}
  docker rm -f "$nombre" >/dev/null 2>&1
  return "$rc"
}

esperar_api() {
  for _ in $(seq 1 120); do
    [ "$(curl -sk -o /dev/null -w '%{http_code}' https://localhost:18443/health)" = 200 ] && return 0
    sleep 5
  done
  echo "La API de QA no respondió." | registrar; return 1
}

esperar_sano() {
  for _ in $(seq 1 120); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' "$1" 2>/dev/null)" = healthy ] && return 0
    sleep 5
  done
  echo "$1 no quedó sano." | registrar; return 1
}

comprobar() { ejecutar verify "$1" || fallos=$((fallos + 1)); }

: > "$EVIDENCIA"
echo "Persistencia en QA, inicio $(date '+%F %T')" | registrar
ejecutar write || { echo "No se pudieron escribir los datos de prueba." | registrar; exit 3; }
comprobar "Línea base, sin reiniciar"

echo "--- Reinicio de todos los servicios" | registrar
compose restart >/dev/null 2>&1; esperar_api
comprobar "Tras reiniciar todos los servicios"

echo "--- Contenedores nuevos, como un despliegue" | registrar
compose down >/dev/null 2>&1
compose up -d --no-build --wait --wait-timeout 900 >/dev/null 2>&1; esperar_api
comprobar "Tras recrear contenedores y correr el migrador"

echo "--- Caída abrupta de Oracle y MinIO" | registrar
docker kill inventario_qa-oracle-1 inventario_qa-minio-1 >/dev/null 2>&1
compose up -d --no-build --wait --wait-timeout 900 >/dev/null 2>&1; esperar_api
comprobar "Tras matar Oracle y MinIO"

echo "--- Desastre: se pierden los volúmenes y se restaura" | registrar
# Dos expdp a la vez sobre el mismo directorio fallan: se espera al periódico si está en curso.
for _ in $(seq 1 90); do
  ls "$QA_POSIX"/respaldos/oracle/*.parcial >/dev/null 2>&1 || break
  sleep 10
done
compose --profile qa-respaldo run --rm --no-deps --entrypoint /bin/bash backup_db /backup/respaldo_db.sh --una-vez 2>&1 | grep -E "OK|rror|FAIL" | cut -c1-120 | registrar
compose --profile qa-respaldo run --rm --no-deps --entrypoint /bin/sh backup_minio /backup/respaldo_minio.sh --una-vez 2>&1 | grep -E "OK|ERROR" | cut -c1-120 | registrar
dmp="$(ls -t "$QA_POSIX"/respaldos/oracle/inventario_*.dmp | head -1 | xargs -n1 basename)"
echo "Respaldo: $dmp" | registrar
compose down >/dev/null 2>&1
docker volume rm inventario_qa_oracle_data inventario_qa_minio_data 2>&1 | tr '\n' ' ' | registrar; echo | registrar
compose up -d samba_ad mailpit oracle >/dev/null 2>&1
esperar_sano inventario_qa-oracle-1
compose --profile qa-respaldo run --rm --no-deps --entrypoint /bin/bash -e CONFIRMAR=SI backup_db /backup/restaurar_db.sh "$dmp" > "$QA_POSIX/evidencia/persistencia_impdp.txt" 2>&1
echo "impdp: $(tail -1 "$QA_POSIX/evidencia/persistencia_impdp.txt" | cut -c1-120)" | registrar
grep -E "ORA-[0-9]+" "$QA_POSIX/evidencia/persistencia_impdp.txt" | cut -c1-110 | sort | uniq -c | sort -rn | head -12 | registrar
compose up -d --no-build --wait --wait-timeout 900 >/dev/null 2>&1; esperar_api
compose --profile qa-respaldo run --rm --no-deps --entrypoint /bin/sh backup_minio /backup/restaurar_minio.sh 2>&1 | tail -1 | cut -c1-120 | registrar
comprobar "Tras restaurar Oracle y MinIO desde respaldo"

echo "Fin $(date '+%F %T'). Rondas con fallos: $fallos" | registrar
exit "$fallos"
