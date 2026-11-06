#!/usr/bin/env bash
# Pruebas de punta a punta de la fase 7 contra el entorno de QA (herramientas/qa/entorno.sh).
# Playwright en contenedor (Chromium, Firefox, WebKit y vista móvil) unido a las redes del
# proyecto inventario_qa: llega al sitio como https://qa.inventario.local (Caddy de QA), a
# Mailpit y a la Oracle de QA (verificaciones en la base). Nunca a producción.
#
#   bash herramientas/qa/e2e.sh                         # toda la suite, todos los navegadores
#   bash herramientas/qa/e2e.sh --project=chromium      # argumentos extra para `playwright test`
#   bash herramientas/qa/e2e.sh pruebas/qr.spec.ts
#
# Evidencia (informe HTML, JSON, capturas, trazas): api/var/qa/evidencia/e2e (no versionado).
set -euo pipefail
export MSYS_NO_PATHCONV=1
AQUI="$(cd "$(dirname "$0")" && pwd)"
RAIZ_POSIX="$(cd "$AQUI/../.." && pwd)"
RAIZ="$(cd "$RAIZ_POSIX" && (pwd -W 2>/dev/null || pwd))"
QA_DIR="$(cd "$RAIZ_POSIX/api/var/qa" && (pwd -W 2>/dev/null || pwd))"
E2E="$(cd "$AQUI/e2e" && (pwd -W 2>/dev/null || pwd))"
mkdir -p "$RAIZ_POSIX/api/var/qa/evidencia/e2e"
# Siembra fresca en cada corrida: contraseñas, 2FA, bloqueos y preferencias de los usuarios de QA conocidos.
QA_MFA_REQUIRED=1 bash "$AQUI/entorno.sh" sembrar >/dev/null
secreto=()
[ -n "${CA_CORPORATIVA:-}" ] && secreto=(--secret "id=ca_corporativa,src=$CA_CORPORATIVA")
docker build -q "${secreto[@]}" -t inventario-qa-e2e "$E2E" >/dev/null

# Canario de SSRF: Nginx en la red de QA (canario.qa) que anota cada petición y sirve su propio
# registro en /registro.txt; la prueba comprueba que la API nunca lo contactó.
if [ -z "$(docker ps -q -f name=^inventario_qa_canario$)" ]; then
  docker rm -f inventario_qa_canario >/dev/null 2>&1 || true
  docker run -d --name inventario_qa_canario --network inventario_qa_salida --network-alias canario.qa nginx:1.30.6-alpine sh -c \
    'printf "server { listen 80; access_log /usr/share/nginx/html/registro.txt; location / { root /usr/share/nginx/html; } }" > /etc/nginx/conf.d/default.conf && touch /usr/share/nginx/html/registro.txt && exec nginx -g "daemon off;"' >/dev/null
  docker network connect --alias canario.qa inventario_qa_internal inventario_qa_canario
fi

# Solo lo necesario del .env de QA: la contraseña del dueño (verificaciones en la base sin VPD)
# y la del usuario de ejecución (pruebas de que el VPD no se salta).
leer() { grep -E "^$1=" "$RAIZ_POSIX/api/var/qa/qa.env" | cut -d= -f2-; }
nombre=inventario_qa_e2e_$$
docker create --name "$nombre" --network inventario_qa_salida --ipc=host --init \
  -e QA_URL=https://qa.inventario.local -e QA_LIMITES="${QA_LIMITES:-funcionales}" -e QA_MAILPIT=http://mailpit:8025 -e QA_ORACLE=oracle:1521/XEPDB1 \
  -e QA_ORACLE_DUENO_CLAVE="$(leer ORACLE_OWNER_PASSWORD)" -e QA_ORACLE_APP_CLAVE="$(leer ORACLE_APP_PASSWORD)" \
  -e QA_USUARIOS=/qa/usuarios.json -e QA_EVIDENCIA=/evidencia \
  -v "$E2E:/e2e/pruebas_src:ro" -v "$QA_DIR/usuarios.json:/qa/usuarios.json:ro" -v "$QA_DIR/evidencia/e2e:/evidencia" \
  -v "$E2E/rutas.md:/qa/rutas.md:ro" \
  -w /e2e inventario-qa-e2e \
  sh -c 'cp -r /e2e/pruebas_src/pruebas /e2e/pruebas_src/playwright.config.ts /e2e/ && npx playwright test "$@"' -- "$@" >/dev/null
docker network connect inventario_qa_internal "$nombre"
# Al terminar, QA vuelve a su estado de uso manual (sin MFA obligatorio).
trap 'docker rm -f "$nombre" >/dev/null 2>&1 || true; bash "$AQUI/entorno.sh" sembrar >/dev/null || true' EXIT
docker start -a "$nombre"
