#!/usr/bin/env bash
# Resistencia y durabilidad: imagen de PRODUCCIÓN (FrankenPHP, modo worker) contra
# la Oracle 21c de pruebas, con peticiones simultáneas reales (tests/Resistencia).
#   1. Ráfagas sobre los puntos de carrera (stock, cupos, correlativos, custodia,
#      aprobación doble, sincronizaciones con el AD a la vez) y carga sostenida.
#   2. Fase 5: dos contenedores worker (Scheduler + Messenger) drenan la cola de
#      correos; uno se reinicia a mitad: ningún correo se pierde ni se duplica.
#   3. Durabilidad: se reinicia el contenedor de la API y se vuelve a cargar y a
#      verificar el sellado del kardex (nada confirmado se pierde).
#
#   bash herramientas/resistencia.sh
set -euo pipefail
export MSYS_NO_PATHCONV=1
AQUI="$(cd "$(dirname "$0")" && pwd)"
RAIZ="$(cd "$AQUI/.." && (pwd -W 2>/dev/null || pwd))"
RED="${RED_PRUEBAS:-inv_php_pruebas}"
API=inv_php_api_resistencia
WORKERS=(inv_php_worker_resistencia_1 inv_php_worker_resistencia_2)
# Red con inspección TLS: CA_CORPORATIVA=<ruta del .pem> se pasa al build como secreto (no queda en la imagen).
secreto=()
[ -n "${CA_CORPORATIVA:-}" ] && secreto=(--secret "id=ca_corporativa,src=$CA_CORPORATIVA")

bash "$AQUI/oracle_pruebas.sh"
docker build -q "${secreto[@]}" -f "$RAIZ/docker/Dockerfile" --target dev -t inventario-php-dev "$RAIZ" >/dev/null
# Etiqueta propia: nunca la de producción (:latest), que un `up` del stack de producción usaría.
IMAGEN="${IMAGEN_RESISTENCIA:-inventario-php:resistencia}"
[ "$IMAGEN" = inventario-php:resistencia ] && docker build -q "${secreto[@]}" -f "$RAIZ/docker/Dockerfile" --target prod -t inventario-php:resistencia "$RAIZ" >/dev/null
# shellcheck source=dobles_pruebas.sh
source "$AQUI/dobles_pruebas.sh"

# Mismas variables que las pruebas (sin comillas) + modo producción, límites holgados y Redis.
ENV_TMP="$RAIZ/api/var/resistencia.env"
grep -E '^[A-Z_]+=' "$AQUI/../api/.env.test" | grep -vE '^(KERNEL_CLASS|APP_SECRET|REDIS_URL)=' | sed -E "s/^([A-Z_]+)='(.*)'$/\1=\2/" > "$ENV_TMP"
comunes=(--network "$RED" --env-file "$ENV_TMP" -e APP_ENV=prod -e APP_DEBUG=0 -e APP_SECRET=resistencia_secreto_de_pruebas
  -e REDIS_URL=redis://redis-pruebas:6379/0 -e LOG_LEVEL=WARNING -e OUTBOX_POLL_SECONDS=2
  -v "$RAIZ/api/var/samba-ad-ca.pem:/app/var/samba-ad-ca.pem:ro"
  --cap-drop ALL --security-opt no-new-privileges:true --memory 1g --cpus 2)
docker rm -f "$API" "${WORKERS[@]}" >/dev/null 2>&1 || true
docker run -d --name "$API" "${comunes[@]}" -e FRANKENPHP_WORKERS=8 -e RATE_LIMIT_DEFAULT=100000/minute "$IMAGEN" >/dev/null
for w in "${WORKERS[@]}"; do
  docker run -d --name "$w" "${comunes[@]}" --no-healthcheck "$IMAGEN" \
    php bin/console messenger:consume scheduler_tareas --no-interaction >/dev/null
done
rm -f "$ENV_TMP"

esperar() {
  for _ in $(seq 1 60); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' "$API" 2>/dev/null)" = "healthy" ] && return 0
    sleep 2
  done
  docker logs --tail 30 "$API"; return 1
}
esperar
echo "API de producción lista ($API) y ${#WORKERS[@]} workers. Ráfagas concurrentes y carga:"

correr() {
  docker run --rm --network "$RED" -v "$RAIZ:/repo" -w /repo/api -e APP_ENV=test -e RESISTENCIA_URL="http://$API:8000" \
    inventario-php-dev php vendor/bin/phpunit --testsuite resistencia "$@"
}
# SOLO_CARGA=1: solo la carga sostenida (comparaciones A/B entre imágenes con IMAGEN_RESISTENCIA).
if [ -n "${SOLO_CARGA:-}" ]; then correr --filter testCargaSostenidaSinErroresYKardexIntegro; docker rm -f "$API" "${WORKERS[@]}" >/dev/null; exit 0; fi
correr --filter '^(?!.*testVariosWorkersDrenanLaCola).*$'

echo "Cola de correos con ${#WORKERS[@]} workers; ${WORKERS[0]} se reinicia a mitad…"
( sleep 6; docker restart "${WORKERS[0]}" >/dev/null ) &
correr --filter testVariosWorkersDrenanLaColaSinPerderNiDuplicarCorreos
wait
docker logs "${WORKERS[0]}" 2>&1 | grep -iE 'error|critical' | head -5 || true

echo "Durabilidad: reinicio de la API y nueva verificación…"
docker restart "$API" >/dev/null
esperar
correr --filter testCargaSostenidaSinErroresYKardexIntegro

docker rm -f "$API" "${WORKERS[@]}" >/dev/null
