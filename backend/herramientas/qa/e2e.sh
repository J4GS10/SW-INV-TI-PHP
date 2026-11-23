#!/usr/bin/env bash
# Suite de navegador (Playwright: Chromium, Firefox, WebKit y vista móvil) contra PRODUCCIÓN, en la
# misma ventana que la suite del backend (herramientas/ventana_produccion.sh): el sistema no atiende,
# hay un respaldo verificado, y al terminar (o si se corta) se restaura y se compara la huella de la base.
#
#   1. abre la ventana: detiene api_php y worker_php, respalda, detiene backup_db, toma la huella
#   2. dobles de los servicios externos (Mailpit, MinIO, Redis, Samba AD; nunca el SMTP ni el AD reales)
#      y un canario que anota cualquier petición (pruebas de SSRF)
#   3. siembra las cuentas qa_* (sembrar.php), solo dentro de la ventana
#   4. una API y un worker de prueba con la imagen de producción, apuntando a los dobles, y un Caddy
#      de prueba SIN puertos publicados (mismo Caddyfile) delante del frontend de producción: nadie de
#      la red llega al sistema mientras dura la prueba
#   5. Playwright contra https://e2e.inventario.local
#   6. cierra la ventana: restaura, migrador, huella idéntica o el sistema queda detenido para revisar
#
#   CONFIRMAR=SI bash herramientas/qa/e2e.sh                          # toda la suite, todos los navegadores
#   CONFIRMAR=SI bash herramientas/qa/e2e.sh --project=chromium       # argumentos extra para `playwright test`
#   CONFIRMAR=SI bash herramientas/qa/e2e.sh pruebas/ui/login.spec.ts
#   CONFIRMAR=SI QA_LIMITES=produccion bash herramientas/qa/e2e.sh    # con los límites de peticiones reales
#
# La imagen de Playwright se construye la primera vez: descarga navegadores y paquetes, así que hágalo
# solo con Wi-Fi y sin cable (nunca por la LAN corporativa). Evidencia (informe HTML, JSON, capturas,
# trazas): api/var/qa/evidencia/e2e (no versionada).
set -uo pipefail
export MSYS_NO_PATHCONV=1
[ "${CONFIRMAR:-}" = "SI" ] || { echo "Detiene el sistema y restaura la base al terminar. Defina CONFIRMAR=SI." >&2; exit 2; }
AQUI="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=../ventana_produccion.sh
source "$AQUI/../ventana_produccion.sh"
RED="${RED_PRUEBAS:-inv_php_pruebas}"
SITIO=e2e.inventario.local
API=inventario-e2e-api
WORKER=inventario-e2e-worker
BORDE=inventario-e2e-caddy
CANARIO=inventario-e2e-canario
SIEMBRA=inventario-e2e-siembra
NAVEGADOR=inventario-e2e-playwright
BUCKET=e2e-adjuntos
nativa() { cygpath -w "$1" 2>/dev/null || echo "$1"; }
E2E="$(nativa "$AQUI/e2e")"
mkdir -p "$HERRAMIENTAS/../api/var/qa/evidencia/e2e"
EVIDENCIA="$(nativa "$(cd "$HERRAMIENTAS/../api/var/qa/evidencia/e2e" && pwd)")"

terminar() {
  docker rm -f "$NAVEGADOR" "$API" "$WORKER" "$BORDE" "$CANARIO" "$SIEMBRA" \
    mailpit minio-pruebas redis-pruebas samba-ad-pruebas >/dev/null 2>&1 || true
  # Cortada a mitad: la base puede tener datos de prueba y se restaura igual.
  [ "$fase" = abierta ] && cerrar_ventana
  rm -rf "$TMP"
}
trap terminar EXIT

# Las imágenes se preparan ANTES de abrir la ventana: si falta red, no se detiene el sistema en vano.
secreto=()
[ -n "${CA_CORPORATIVA:-}" ] && secreto=(--secret "id=ca_corporativa,src=$CA_CORPORATIVA")
docker build -q "${secreto[@]}" -t inventario-qa-e2e "$E2E" >/dev/null || { echo "!! No se pudo construir la imagen de Playwright." >&2; exit 1; }
docker image inspect inventario-php-dev >/dev/null 2>&1 \
  || docker build -q -f "$RAIZ/docker/Dockerfile" --target dev -t inventario-php-dev "$RAIZ" >/dev/null \
  || { echo "!! No se pudo construir inventario-php-dev." >&2; exit 1; }

abrir_ventana || exit 1

# Dobles (dobles_pruebas.sh usa AQUI = herramientas) y bucket propio en el MinIO de pruebas.
( AQUI="$HERRAMIENTAS"; source "$HERRAMIENTAS/dobles_pruebas.sh" )
docker run --rm --network "$RED" --entrypoint sh pgsty/mc@sha256:cfc83108c3abb371f8fb84d99c1fdc88f8c237e022409b0081fb7c0a3be634dd -c \
  "mc alias set p http://minio-pruebas:9000 pruebas pruebas-minio-2026 >/dev/null && mc mb --ignore-existing p/$BUCKET >/dev/null"
# Canario de SSRF: anota cada petición y sirve su propio registro en /registro.txt.
docker run -d --name "$CANARIO" --network "$RED" --network-alias canario.qa nginx:1.30.6-alpine sh -c \
  'printf "server { listen 80; access_log /usr/share/nginx/html/registro.txt; location / { root /usr/share/nginx/html; } }" > /etc/nginx/conf.d/default.conf && touch /usr/share/nginx/html/registro.txt && exec nginx -g "daemon off;"' >/dev/null

# Claves: solo en archivos de entorno temporales (umask 077), nunca en la línea de comandos ni en la salida.
umask 077
{
  echo "ORACLE_HOST=oracle"; echo "ORACLE_SERVICE_NAME=$SERVICIO"
  echo "ORACLE_SCHEMA_OWNER=$DUENO"; echo "ORACLE_OWNER_PASSWORD=$(leer ORACLE_OWNER_PASSWORD)"
  echo "FIELD_ENCRYPTION_KEY=$(leer FIELD_ENCRYPTION_KEY)"
} > "$TMP/siembra.env"
{
  echo "QA_ORACLE_DUENO=$DUENO"; echo "QA_ORACLE_DUENO_CLAVE=$(leer ORACLE_OWNER_PASSWORD)"
  echo "QA_ORACLE_APP=$(leer ORACLE_APP_USER)"; echo "QA_ORACLE_APP_CLAVE=$(leer ORACLE_APP_PASSWORD)"
} > "$TMP/navegador.env"
mkdir -p "$TMP/qa"
cp "$AQUI/e2e/rutas.md" "$TMP/qa/rutas.md"

echo "==> Sembrando las cuentas de la suite…"
if ! docker run --rm --name "$SIEMBRA" --network inventario_internal --user root --env-file "$(nativa "$TMP/siembra.env")" \
  -e E2E_VENTANA=SI -e QA_SALIDA=/qa/usuarios.json -v "$RAIZ:/repo:ro" -v "$(nativa "$TMP/qa"):/qa" \
  --entrypoint php inventario-php-dev /repo/herramientas/qa/sembrar.php; then
  rm -f "$TMP/siembra.env" "$TMP/navegador.env"
  echo "!! No se pudo sembrar." >&2
  cerrar_ventana
  exit 1
fi
rm -f "$TMP/siembra.env"

# API y worker de prueba: la imagen y la configuración de producción, con los servicios externos en los dobles.
limites=(-e RATE_LIMIT_LOGIN=1000/minute -e RATE_LIMIT_REFRESH=1000/minute -e RATE_LIMIT_DEFAULT=10000/minute)
[ "${QA_LIMITES:-}" = produccion ] && limites=()
dobles=(
  -e SMTP_HOST=mailpit -e SMTP_PORT=1025 -e SMTP_USER= -e SMTP_PASSWORD= -e SMTP_TLS=false -e SMTP_STARTTLS=false
  -e EMAIL_ENABLED=true -e NOTIFY_ADMIN_EMAILS=admin@qa.inventario.local
  -e REDIS_URL=redis://redis-pruebas:6379/0
  -e S3_ENDPOINT_URL=http://minio-pruebas:9000 -e S3_ACCESS_KEY=pruebas -e S3_SECRET_KEY=pruebas-minio-2026 -e S3_BUCKET="$BUCKET"
  -e UPLOAD_DIR=/tmp/adjuntos-e2e
  -e AD_ENABLED=false -e AD_SYNC_INTERVAL_MINUTES=0 -e AD_CA_CERT_FILE=/run/e2e/samba-ad-ca.pem
  -e SSO_ENABLED=false
  -e FRONTEND_BASE_URL="https://$SITIO" -e BACKEND_CORS_ORIGINS="[\"https://$SITIO\"]"
  -e MERCURE_HUB_URL="http://$API:8000/.well-known/mercure"
  -v "$(nativa "$HERRAMIENTAS/../api/var/samba-ad-ca.pem"):/run/e2e/samba-ad-ca.pem:ro"
)
"${stack[@]}" run -d --no-deps --name "$API" "${dobles[@]}" "${limites[@]}" api_php >/dev/null
"${stack[@]}" run -d --no-deps --name "$WORKER" "${dobles[@]}" "${limites[@]}" worker_php >/dev/null
docker network connect "$RED" "$API"
docker network connect "$RED" "$WORKER"
lista=no
for _ in $(seq 90); do
  [ "$(docker inspect -f '{{.State.Health.Status}}' "$API" 2>/dev/null)" = healthy ] && { lista=si; break; }
  [ "$(docker inspect -f '{{.State.Running}}' "$API" 2>/dev/null)" = true ] || break
  sleep 2
done
if [ "$lista" != si ]; then
  docker logs --tail 20 "$API" >&2
  rm -f "$TMP/navegador.env"
  echo "!! La API de prueba no quedó sana en 3 minutos." >&2
  cerrar_ventana
  exit 1
fi

# Caddy de prueba: el Caddyfile de producción (mismas cabeceras y rutas) hacia la API de prueba, sin puertos publicados.
sed "s/api_php:8000/$API:8000/" "$DEPLOY/Caddyfile" > "$TMP/Caddyfile"
docker run -d --name "$BORDE" --network inventario_salida --network-alias "$SITIO" \
  -e DOMAIN="$SITIO" -e TLS_DEFAULT_SNI="$SITIO" -e TLS_EMISOR=internal \
  -v "$(nativa "$TMP/Caddyfile"):/etc/caddy/Caddyfile:ro" caddy:2-alpine >/dev/null

echo "==> Suite de navegador contra https://$SITIO…"
docker create --name "$NAVEGADOR" --network inventario_salida --ipc=host --init --env-file "$(nativa "$TMP/navegador.env")" \
  -e QA_URL="https://$SITIO" -e QA_LIMITES="${QA_LIMITES:-funcionales}" -e QA_MAILPIT=http://mailpit:8025 \
  -e QA_ORACLE="oracle:1521/$SERVICIO" -e QA_USUARIOS=/qa/usuarios.json -e QA_EVIDENCIA=/evidencia \
  -v "$E2E:/e2e/pruebas_src:ro" -v "$(nativa "$TMP/qa"):/qa:ro" -v "$EVIDENCIA:/evidencia" \
  -w /e2e inventario-qa-e2e \
  sh -c 'cp -r /e2e/pruebas_src/pruebas /e2e/pruebas_src/playwright.config.ts /e2e/ && npx playwright test "$@"' -- "$@" >/dev/null
rm -f "$TMP/navegador.env"
docker network connect inventario_internal "$NAVEGADOR"
docker network connect "$RED" "$NAVEGADOR"
docker start -a "$NAVEGADOR"
rc=$?

docker rm -f "$NAVEGADOR" "$API" "$WORKER" "$BORDE" "$CANARIO" >/dev/null 2>&1 || true
cerrar_ventana
[ "$fase" = restaurada ] || exit 3
exit "$rc"
