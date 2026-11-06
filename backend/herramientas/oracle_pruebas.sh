#!/usr/bin/env bash
# Levanta (si no existe) el Oracle 21c XE desechable del backend PHP y lo deja
# al día con el MIGRADOR: base vacía → migraciones de Doctrine → capa de
# seguridad (VPD, disparadores, kardex) → permisos de INVENTARIO_APP. Sin datos
# de producción ni cuenta raíz (--sin-datos). Idempotente: en una base ya
# migrada no cambia nada.
#
#   bash herramientas/oracle_pruebas.sh        # levanta, espera y migra
#   docker rm -f inv_php_oracle_test           # descartar la base de pruebas
set -euo pipefail
export MSYS_NO_PATHCONV=1
AQUI="$(cd "$(dirname "$0")" && pwd)"
# Ruta nativa en Git Bash de Windows (docker.exe no entiende /c/...).
RAIZ="$(cd "$AQUI/.." && (pwd -W 2>/dev/null || pwd))"
RED="${RED_PRUEBAS:-inv_php_pruebas}"
NOMBRE="${ORACLE_PRUEBAS:-inv_php_oracle_test}"
CLAVE=InventarioTest2026
# Red con inspección TLS: CA_CORPORATIVA=<ruta del .pem> se pasa al build como secreto (no queda en la imagen).
ca=()
[ -n "${CA_CORPORATIVA:-}" ] && ca=(--secret "id=ca_corporativa,src=$CA_CORPORATIVA")

docker network inspect "$RED" >/dev/null 2>&1 || docker network create "$RED" >/dev/null
if [ -z "$(docker ps -q -f "name=^${NOMBRE}$")" ]; then
  docker rm -f "$NOMBRE" >/dev/null 2>&1 || true
  echo "Levantando Oracle 21c XE de pruebas ($NOMBRE, 127.0.0.1:1523)…"
  docker run -d --name "$NOMBRE" --network "$RED" --shm-size 1g -p 127.0.0.1:1523:1521 \
    --health-cmd "healthcheck.sh && test -f /opt/oracle/oradata/.inventario_init_ok" \
    --health-interval 10s --health-timeout 5s --health-retries 30 \
    -e ORACLE_PASSWORD="$CLAVE" -e TEST_SCHEMA_PASSWORD="$CLAVE" \
    -v "$RAIZ/db/pruebas/init:/container-entrypoint-initdb.d:ro" \
    gvenzl/oracle-xe:21-slim-faststart >/dev/null
fi
for _ in $(seq 1 90); do
  [ "$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$NOMBRE")" = healthy ] && break
  sleep 5
done
[ "$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{end}}' "$NOMBRE")" = healthy ] \
  || { echo "Oracle de pruebas no respondió a tiempo (docker logs $NOMBRE)." >&2; exit 1; }

docker build -q "${ca[@]}" -f "$RAIZ/docker/Dockerfile" --target dev -t inventario-php-dev "$RAIZ" >/dev/null
docker run --rm --network "$RED" -v "$RAIZ:/repo" -w /repo/api -e APP_ENV=test -e ORACLE_HOST="$NOMBRE" \
  -e ORACLE_APP_USER=INVENTARIO_APP inventario-php-dev sh -c '
  [ -d vendor ] || composer install --no-interaction --no-progress -q
  php bin/console app:esquema:actualizar --sin-datos --no-ansi' | sed 's/^/  /'
echo "Oracle de pruebas listo y migrado: $NOMBRE (red $RED)."
