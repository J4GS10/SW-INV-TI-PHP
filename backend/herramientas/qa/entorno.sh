#!/usr/bin/env bash
# Entorno de QA de la fase 7 (proyecto compose `inventario_qa`): el mismo
# deploy/docker-compose.yml de producción + deploy/docker-compose.qa.yml, con
# volúmenes inventario_qa_*, puertos solo en 127.0.0.1, Mailpit, Samba AD y la
# base restaurada del último Data Pump de producción (migrada por el migrador).
# Nunca toca el proyecto `inventario` ni sus volúmenes.
#
#   bash herramientas/qa/entorno.sh subir      # imágenes :qa, restauración (solo la primera vez), stack y siembra
#   bash herramientas/qa/entorno.sh sembrar    # (re)siembra usuarios por rol y sede -> api/var/qa/usuarios.json
#   bash herramientas/qa/entorno.sh estado     # contenedores y humo por el borde de QA
#   bash herramientas/qa/entorno.sh compose …  # docker compose del proyecto de QA (p. ej. `compose logs api_php`)
#   bash herramientas/qa/entorno.sh limites-produccion|limites-funcionales
#                                              # límites de tasa de producción (5/min y 100/min) u holgados (suite)
#   bash herramientas/qa/entorno.sh bajar      # detiene el stack de QA (conserva datos)
#   bash herramientas/qa/entorno.sh borrar     # borra contenedores y volúmenes inventario_qa_* y la base restaurada
#   CONFIRMAR=SI bash herramientas/qa/entorno.sh vaciar  # borra todo y levanta QA con la base vacía: solo las cuentas de usuarios_manuales.json
#
# Secretos de QA: api/var/qa/qa.env (generados la primera vez, no versionados; nunca los de producción).
# Red con inspección TLS: CA_CORPORATIVA=<pem> para los builds (secreto de BuildKit; no queda en la imagen).
set -euo pipefail
export MSYS_NO_PATHCONV=1
AQUI="$(cd "$(dirname "$0")" && pwd)"
RAIZ_POSIX="$(cd "$AQUI/../.." && pwd)"
RAIZ="$(cd "$RAIZ_POSIX" && (pwd -W 2>/dev/null || pwd))"
DEPLOY="$RAIZ/deploy"
QA_DIR_POSIX="$RAIZ_POSIX/api/var/qa"
mkdir -p "$QA_DIR_POSIX/respaldos/oracle" "$QA_DIR_POSIX/evidencia"
QA_DIR="$(cd "$QA_DIR_POSIX" && (pwd -W 2>/dev/null || pwd))"
ENV_QA="$QA_DIR/qa.env"
FRONTEND="${FRONTEND_CONTEXT:-$(cd "$RAIZ_POSIX/.." && (pwd -W 2>/dev/null || pwd))/frontend}"
secreto=()
[ -n "${CA_CORPORATIVA:-}" ] && secreto=(--secret "id=ca_corporativa,src=$CA_CORPORATIVA")

qa=(docker compose -p inventario_qa --project-directory "$DEPLOY" --env-file "$ENV_QA"
  -f "$DEPLOY/docker-compose.yml" -f "$DEPLOY/docker-compose.qa.yml")

azar() { openssl rand -hex "$1"; }

generar_env() {
  [ -f "$ENV_QA" ] && return 0
  umask 077
  cat > "$ENV_QA" <<ENV
# Secretos y ajustes del entorno de QA (generados por herramientas/qa/entorno.sh; no versionar).
QA_DIR=$QA_DIR
BACKUP_DIR=$QA_DIR/respaldos
FRONTEND_CONTEXT=$FRONTEND
COMPOSE_PROFILES=
DOMAIN=qa.inventario.local, localhost
HTTP_PORT=127.0.0.1:18080
HTTPS_PORT=127.0.0.1:18443
QA_MAILPIT_PORT=18025
BACKEND_CORS_ORIGINS=["https://qa.inventario.local","https://localhost:18443"]
FRONTEND_BASE_URL=https://qa.inventario.local
APP_SECRET=$(azar 32)
SECRET_KEY=$(azar 32)
HMAC_KEYS=$(azar 32)
FIELD_ENCRYPTION_KEY=$(openssl rand -base64 32 | tr '+/' '-_')
ORACLE_SYS_PASSWORD=Qa$(azar 12)
ORACLE_SCHEMA_OWNER=INVENTARIO
ORACLE_OWNER_PASSWORD=Qa$(azar 12)
ORACLE_APP_USER=INVENTARIO_APP
ORACLE_APP_PASSWORD=Qa$(azar 12)
REDIS_PASSWORD=$(azar 16)
MINIO_ROOT_USER=qa-root
MINIO_ROOT_PASSWORD=$(azar 16)
MINIO_APP_USER=qa-app
MINIO_APP_PASSWORD=$(azar 16)
MINIO_KMS_SECRET_KEY=inventario-kms:$(openssl rand -base64 32)
SUPER_ADMIN_USERNAME=sa
SUPER_ADMIN_EMAIL=sa@qa.inventario.local
SUPER_ADMIN_PASSWORD=Qa$(azar 10)#9z
# Límite de inicio de sesión holgado para la suite funcional (todos los navegadores salen de
# una IP). Las pruebas de seguridad lo vuelven al de producción: `entorno.sh limites-produccion`.
RATE_LIMIT_LOGIN=600/minute
SMTP_FROM_EMAIL=inventario@qa.inventario.local
NOTIFY_ADMIN_EMAILS=admin@qa.inventario.local
ENV
  echo "Secretos de QA generados en api/var/qa/qa.env."
}

# Garantías antes de crear o borrar: todo volumen del proyecto se llama inventario_qa_*
# y ningún puerto se publica fuera de 127.0.0.1 ni en los de producción (80/443).
verificar_volumenes() {
  local config nombre
  config="$("${qa[@]}" config)"
  for nombre in $(echo "$config" | awk '/^volumes:/{f=1;next} /^[a-z]/{f=0} f && $1=="name:"{print $2}'); do
    case "$nombre" in inventario_qa_*) ;; *) echo "Volumen fuera de QA ($nombre): se aborta." >&2; exit 1 ;; esac
  done
  if echo "$config" | grep -qE 'published: "(80|443)"'; then
    echo "El proyecto de QA publicaría los puertos de producción: se aborta." >&2; exit 1
  fi
}

construir() {
  docker build -q "${secreto[@]}" -f "$RAIZ/docker/Dockerfile" --target prod -t inventario-php:qa "$RAIZ" >/dev/null
  docker build -q "${secreto[@]}" -f "$RAIZ/docker/Dockerfile" --target dev -t inventario-php-dev "$RAIZ" >/dev/null
  docker image inspect inventario-samba-ad-pruebas >/dev/null 2>&1 \
    || docker build -q "${secreto[@]}" -t inventario-samba-ad-pruebas "$RAIZ/docker/pruebas/samba-ad" >/dev/null
  "${qa[@]}" build -q frontend
}

esperar_sano() { # contenedor
  for _ in $(seq 1 120); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' "$1" 2>/dev/null)" = healthy ] && return 0
    sleep 5
  done
  echo "$1 no quedó sano." >&2; return 1
}

restaurar() {
  [ -f "$QA_DIR_POSIX/.restaurado" ] && { echo "Base de QA ya restaurada de $(cat "$QA_DIR_POSIX/.restaurado")."; return 0; }
  local origen dmp
  origen="$(grep -E '^BACKUP_DIR=' "$DEPLOY/.env" | cut -d= -f2-)/oracle"
  dmp="$(cd "$origen" && ls -t inventario_*.dmp | head -1)"
  # Solo se copia el último respaldo VERIFICADO (su log termina «successfully completed»).
  grep -q 'successfully completed' "$origen/${dmp%.dmp}.log" || { echo "El último respaldo ($dmp) no está verificado." >&2; exit 1; }
  cp "$origen/$dmp" "$QA_DIR_POSIX/respaldos/oracle/"
  echo "Restaurando $dmp en la Oracle de QA…"
  "${qa[@]}" --profile qa-respaldo run --rm --no-deps --entrypoint /bin/bash -e CONFIRMAR=SI backup_db \
    /backup/restaurar_db.sh "$dmp"
  echo "$dmp" > "$QA_DIR_POSIX/.restaurado"
}

sembrar() {
  # Conexión como dueño a la Oracle de QA por la red interna del proyecto.
  docker run --rm --network inventario_qa_internal --env-file "$ENV_QA" -e ENVIRONMENT=qa -e ORACLE_HOST=oracle -e QA_MFA_REQUIRED="${QA_MFA_REQUIRED:-0}" \
    -v "$RAIZ:/repo:ro" -v "$QA_DIR:/qa" --entrypoint php inventario-php-dev /repo/herramientas/qa/sembrar.php
}

humo() {
  echo "Humo por el borde de QA (https://localhost:18443):"
  for ruta in / /health /api/v1/login/sso/providers /api/v1/me /api/v1/core/activos /health/full /metrics; do
    printf '  %-32s %s\n' "$ruta" "$(curl -sk -o /dev/null -w '%{http_code}' "https://localhost:18443$ruta")"
  done
}

case "${1:-subir}" in
  subir)
    generar_env
    verificar_volumenes
    construir
    "${qa[@]}" up -d samba_ad mailpit oracle
    esperar_sano inventario_qa-samba_ad-1
    docker exec inventario_qa-samba_ad-1 cat /var/lib/samba/private/tls/ca.pem > "$QA_DIR_POSIX/samba-ad-ca.pem"
    esperar_sano inventario_qa-oracle-1
    restaurar
    "${qa[@]}" up -d --no-build --wait --wait-timeout 900
    "${qa[@]}" logs --no-log-prefix migrador | tail -8
    sembrar
    humo
    ;;
  sembrar) sembrar ;;
  estado) "${qa[@]}" ps; humo ;;
  compose) shift; "${qa[@]}" "$@" ;;
  limites-produccion|limites-funcionales)
    # Cambia RATE_LIMIT_LOGIN y RATE_LIMIT_DEFAULT en qa.env y recrea api_php
    # (producción: 5/minute y 100/minute; funcionales: holgados para barridos y navegadores).
    login=600/minute; defecto=100000/minute
    [ "$1" = limites-produccion ] && { login=5/minute; defecto=100/minute; }
    grep -q '^RATE_LIMIT_DEFAULT=' "$QA_DIR_POSIX/qa.env" || echo "RATE_LIMIT_DEFAULT=$defecto" >> "$QA_DIR_POSIX/qa.env"
    sed -i "s#^RATE_LIMIT_LOGIN=.*#RATE_LIMIT_LOGIN=$login#; s#^RATE_LIMIT_DEFAULT=.*#RATE_LIMIT_DEFAULT=$defecto#" "$QA_DIR_POSIX/qa.env"
    "${qa[@]}" up -d --no-build --wait api_php
    echo "RATE_LIMIT_LOGIN=$login y RATE_LIMIT_DEFAULT=$defecto en QA."
    ;;
  bajar) "${qa[@]}" stop ;;
  borrar)
    verificar_volumenes
    "${qa[@]}" --profile qa-respaldo down -v --remove-orphans
    rm -f "$QA_DIR_POSIX/.restaurado" "$QA_DIR_POSIX/usuarios.json" "$QA_DIR_POSIX"/respaldos/oracle/*
    echo "Entorno de QA borrado (secretos y evidencia conservados en api/var/qa)."
    ;;
  vaciar)
    # Borra lo que otros estén probando en QA: nunca sin confirmación expresa.
    [ "${CONFIRMAR:-}" = "SI" ] || { echo "vaciar borra TODOS los datos de QA (nombre, logo, inventario, claves y MFA). Defina CONFIRMAR=SI." >&2; exit 2; }
    verificar_volumenes
    "${qa[@]}" --profile qa-respaldo down -v --remove-orphans
    rm -f "$QA_DIR_POSIX/usuarios.json" "$QA_DIR_POSIX"/respaldos/oracle/*
    # Con la marca no se restaura el respaldo de producción: el migrador crea el esquema vacío.
    echo "base vacía" > "$QA_DIR_POSIX/.restaurado"
    exec bash "$0" subir
    ;;
  *) echo "Uso: $0 [subir|sembrar|estado|compose …|limites-produccion|limites-funcionales|bajar|borrar|vaciar]" >&2; exit 2 ;;
esac
