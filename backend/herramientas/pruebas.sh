#!/usr/bin/env bash
# Suite del backend PHP contra Oracle 21c XE real (VPD, disparadores y bitácora
# incluidos; la base se crea vacía y la deja al día el migrador, ver
# oracle_pruebas.sh) y dobles de los servicios externos (correo, S3, Active Directory,
# Redis; ver herramientas/dobles_pruebas.sh). Todo en Docker: no requiere PHP
# ni Composer en el equipo.
#
#   bash herramientas/pruebas.sh                       # toda la suite
#   bash herramientas/pruebas.sh --filter LoginTest    # argumentos extra para PHPUnit
#   bash herramientas/pruebas.sh --unit                # solo pruebas sin base de datos
#   bash herramientas/pruebas.sh --imagen-produccion   # toda la suite dentro de la imagen de producción
set -euo pipefail
export MSYS_NO_PATHCONV=1
AQUI="$(cd "$(dirname "$0")" && pwd)"
RAIZ="$(cd "$AQUI/.." && (pwd -W 2>/dev/null || pwd))"
RED="${RED_PRUEBAS:-inv_php_pruebas}"

args=("$@")
imagen=inventario-php-dev
if [ "${1:-}" = "--unit" ]; then
  args=(--testsuite unitarias "${@:2}")
elif [ "${1:-}" = "--imagen-produccion" ]; then
  # La suite corre DENTRO de la imagen de producción (runtime recortado: Instant Client,
  # extensiones y librerías dinámicas), con el código y el vendor de desarrollo montados.
  imagen=inventario-php:latest
  args=("${@:2}")
  bash "$AQUI/oracle_pruebas.sh"
else
  bash "$AQUI/oracle_pruebas.sh"
fi

# Red con inspección TLS: CA_CORPORATIVA=<ruta del .pem> se pasa a los builds como secreto (no queda en la imagen).
ca=()
[ -n "${CA_CORPORATIVA:-}" ] && ca=(--secret "id=ca_corporativa,src=$CA_CORPORATIVA")
docker build -q "${ca[@]}" -f "$RAIZ/docker/Dockerfile" --target dev -t inventario-php-dev "$RAIZ" >/dev/null
# shellcheck source=dobles_pruebas.sh
source "$AQUI/dobles_pruebas.sh"
docker run --rm --network "$RED" -v "$RAIZ:/repo" -w /repo/api -e APP_ENV=test inventario-php-dev sh -c \
  '[ -d vendor ] || composer install --no-interaction --no-progress -q'
if [ "$imagen" != inventario-php-dev ]; then
  docker build -q "${ca[@]}" -f "$RAIZ/docker/Dockerfile" --target prod -t "$imagen" "$RAIZ" >/dev/null
fi
docker run --rm --network "$RED" -v "$RAIZ:/repo" -w /repo/api -e APP_ENV=test --user root --entrypoint php "$imagen" \
  vendor/bin/phpunit "${args[@]}"
