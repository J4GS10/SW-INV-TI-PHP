#!/usr/bin/env bash
# Despliegue de producción con el stack propio del repo PHP (deploy/docker-compose.yml,
# proyecto compose `inventario`).
#
#   bash herramientas/desplegar.sh                  # respaldo verificado, imagen nueva y stack completo
#   bash herramientas/desplegar.sh esquema          # verifica el esquema (migraciones y VPD) sin cambiar nada
#   bash herramientas/desplegar.sh estado           # contenedores, prueba de humo por el borde y worker
#   bash herramientas/desplegar.sh respaldar        # respaldo Data Pump ahora, verificado
#   bash herramientas/desplegar.sh recargar    # recarga el Caddyfile sin cortar conexiones
#
# Secretos: deploy/.env (stack; ver README) y deploy/.env.php (APP_SECRET), no versionados.
# Red con inspección TLS: CA_CORPORATIVA=<pem> se pasa al build como secreto (no queda en la imagen).
set -euo pipefail
export MSYS_NO_PATHCONV=1
AQUI="$(cd "$(dirname "$0")" && pwd)"
RAIZ="$(cd "$AQUI/.." && (pwd -W 2>/dev/null || pwd))"
DEPLOY="$RAIZ/deploy"
secreto=()
[ -n "${CA_CORPORATIVA:-}" ] && secreto=(--secret "id=ca_corporativa,src=$CA_CORPORATIVA")

for f in .env .env.php; do
  [ -f "$DEPLOY/$f" ] || { echo "Falta deploy/$f (ver README, «Producción»)." >&2; exit 1; }
done
stack=(docker compose -p inventario --project-directory "$DEPLOY" --env-file "$DEPLOY/.env" --env-file "$DEPLOY/.env.php"
  -f "$DEPLOY/docker-compose.yml")

humo() {
  local puerto url
  puerto="$(grep -E '^HTTPS_PORT=' "$DEPLOY/.env" | cut -d= -f2 || true)"
  url="https://localhost:${puerto:-443}"
  echo "Prueba de humo por el borde ($url):"
  for ruta in / /health /api/v1/login/sso/providers /api/v1/me /api/v1/gov/config /api/v1/core/activos \
              /api/v1/directorio/estado /api/v1/no-existe /health/full /metrics; do
    printf '  %-32s %s\n' "$ruta" "$(curl -sk -o /dev/null -w '%{http_code}' "$url$ruta")"
  done
  printf '  %-32s %s\n' "worker_php" "$("${stack[@]}" ps --format '{{.Health}}' worker_php 2>/dev/null || echo '-')"
}

# Un despliegue con la API caída no termina en éxito (fase 8): /health debe responder 200.
exigir_salud() {
  local puerto codigo
  puerto="$(grep -E '^HTTPS_PORT=' "$DEPLOY/.env" | cut -d= -f2 || true)"
  for _ in $(seq 1 12); do
    codigo="$(curl -sk -o /dev/null -w '%{http_code}' "https://localhost:${puerto:-443}/health" || true)"
    [ "$codigo" = 200 ] && { echo "Salud verificada: /health 200."; return 0; }
    sleep 5
  done
  echo "La API no responde /health 200 (último código: ${codigo:-sin respuesta}). Revertir la imagen con: docker tag inventario-php:anterior inventario-php:latest" >&2
  exit 1
}

# Respaldo Data Pump inmediato con el servicio de respaldos en marcha, verificado por su log.
respaldar() {
  local salida
  # Dos expdp a la vez sobre el mismo directorio fallan: se espera al que esté en curso
  # (el de arranque de un backup_db recién creado, o el periódico).
  for _ in $(seq 1 90); do
    docker exec inventario-backup_db-1 sh -c 'ls /opt/oracle/respaldos/*.parcial' >/dev/null 2>&1 || break
    sleep 10
  done
  # Con una falla el script sale con 1: se captura igual y decide la verificación de abajo (con su mensaje).
  salida="$(docker exec inventario-backup_db-1 bash /backup/respaldo_db.sh --una-vez 2>&1)" || true
  echo "$salida"
  # Se exige el OK de ESTE respaldo (respaldo_db.sh solo lo imprime si el log de expdp
  # termina «successfully completed» y el .dmp quedó en su lugar).
  echo "$salida" | grep -q '^\[backup_db\] OK' || { echo "El respaldo previo NO se verificó: no se continúa." >&2; exit 1; }
  echo "Respaldo verificado: $(echo "$salida" | sed -n 's/.*-> \(inventario_[0-9_]*\.dmp\).*/\1/p')"
}

case "${1:-subir}" in
  subir)
    # La imagen en uso queda como inventario-php:anterior (reversión de imagen sin reconstruir).
    docker image inspect inventario-php:latest >/dev/null 2>&1 && docker tag inventario-php:latest inventario-php:anterior
    docker build -q "${secreto[@]}" -f "$RAIZ/docker/Dockerfile" --target prod -t inventario-php:latest "$RAIZ" >/dev/null
    # El frontend también: `up --no-build` no lo reconstruye y quedaría el anterior sin avisar.
    docker image inspect inventario-frontend:latest >/dev/null 2>&1 && docker tag inventario-frontend:latest inventario-frontend:anterior
    "${stack[@]}" build -q frontend
    "${stack[@]}" config -q
    respaldar
    # Recrea lo que cambió (Oracle se reinicia si cambia su definición); el migrador
    # corre antes que la API y aborta el despliegue si el esquema no queda completo.
    "${stack[@]}" up -d --no-build --wait --wait-timeout 900
    "${stack[@]}" exec -T caddy caddy reload --config /etc/caddy/Caddyfile >/dev/null 2>&1 || true
    "${stack[@]}" logs --no-log-prefix migrador | tail -12
    humo
    exigir_salud
    ;;
  estado)
    "${stack[@]}" ps
    humo
    ;;
  respaldar)
    respaldar
    ;;
  esquema)
    # Solo lectura: migraciones pendientes y diferencias de la capa de seguridad (VPD, disparadores, kardex).
    "${stack[@]}" run --rm --no-deps migrador php bin/console app:esquema:actualizar --verificar --no-ansi 2>/dev/null
    ;;
  recargar)
    "${stack[@]}" exec caddy caddy reload --config /etc/caddy/Caddyfile
    ;;
  *)
    echo "Uso: $0 [subir|esquema|estado|respaldar|recargar]" >&2
    exit 2
    ;;
esac
