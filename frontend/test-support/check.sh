#!/bin/sh
set -eu
[ -x node_modules/.bin/vitest ] || npm ci --no-audit --no-fund
if [ "${1:-all}" != test ]; then
  node_modules/.bin/tsc -p tsconfig.json --noEmit --incremental false
  node_modules/.bin/tsc -p tsconfig.test.json --noEmit
  node_modules/.bin/eslint . --quiet
fi
node_modules/.bin/vitest run --reporter=dot
