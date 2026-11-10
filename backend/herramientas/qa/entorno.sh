#!/usr/bin/env bash
# El sistema tiene UN solo entorno: producción (proyecto compose `inventario`). Todo se
# prueba ahí, con las cuentas de prueba por rol (docs/PRUEBAS_POR_ROL.md). El entorno de
# QA (`inventario_qa`) se retiró el 2026-11-10 y no se vuelve a levantar.
#
#   bash herramientas/qa/entorno.sh borrar   # quita restos de QA (contenedores y volúmenes inventario_qa_*)
set -euo pipefail
export MSYS_NO_PATHCONV=1

case "${1:-}" in
  borrar)
    docker ps -aq --filter "label=com.docker.compose.project=inventario_qa" | xargs -r docker rm -f >/dev/null
    docker volume ls -q --filter "label=com.docker.compose.project=inventario_qa" | { grep '^inventario_qa_' || true; } | xargs -r docker volume rm >/dev/null
    docker network ls -q --filter "label=com.docker.compose.project=inventario_qa" | xargs -r docker network rm >/dev/null
    echo "Sin restos de QA. Producción (inventario) no se tocó."
    ;;
  *)
    echo "No hay entorno de QA: el sistema tiene un solo entorno, producción. Ver docs/PRUEBAS_POR_ROL.md." >&2
    exit 2
    ;;
esac
