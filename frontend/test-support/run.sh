#!/usr/bin/env bash
# tsc, lint y pruebas del frontend en Docker, con node_modules en el volumen inventario-front-nm
# (el node_modules de Windows no sirve dentro de Linux). La primera vez instala con npm ci.
#
#   bash test-support/run.sh            # todo
#   bash test-support/run.sh test       # solo pruebas
set -euo pipefail
export MSYS_NO_PATHCONV=1
cd "$(dirname "$0")/.."
RAIZ="$(pwd -W 2>/dev/null || pwd)"
docker volume create inventario-front-nm >/dev/null
docker run --rm -v "$RAIZ":/app -v inventario-front-nm:/app/node_modules -w /app node:24-alpine \
  sh test-support/check.sh "${1:-all}"
