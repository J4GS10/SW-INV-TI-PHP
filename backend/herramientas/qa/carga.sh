#!/usr/bin/env bash
# Carga, estrés, prueba prolongada e inyección de fallas contra el entorno de QA (nunca producción),
# con k6 en contenedor por el borde de QA (qa.inventario.local). Resumen JSON en api/var/qa/evidencia/carga.
#
#   bash herramientas/qa/carga.sh carga|estres|prolongada|fallas   (DURACION=30m para la prolongada)
#
# «fallas» corre 6 min de carga y, mientras tanto, reinicia Oracle, Redis, MinIO, worker_php y api_php
# y agrega 300 ms de latencia a la API durante 45 s (pumba/netem); al final verifica el sellado del kardex,
# la cola de correos y los cerrojos lock:job:* en Redis.
set -euo pipefail
export MSYS_NO_PATHCONV=1
AQUI="$(cd "$(dirname "$0")" && pwd)"
RAIZ_POSIX="$(cd "$AQUI/../.." && pwd)"
QA="$RAIZ_POSIX/api/var/qa"
EVID="$QA/evidencia/carga"
mkdir -p "$EVID"
ESC="${1:-carga}"
leer() { grep -E "^$1=" "$QA/qa.env" | cut -d= -f2-; }
sql() { docker exec -i inventario_qa-oracle-1 sqlplus -s "INVENTARIO/$(leer ORACLE_OWNER_PASSWORD)@//localhost:1521/XEPDB1" <<<"set heading off feedback off pagesize 0
$1"; }

# Catálogo propio de la corrida (tipo, marca y modelo) y la sede Norte de QA.
s=$(date +%s)
sql "insert into \"INV_TIPO_ACTIVO\" (\"TAC_Nombre\",\"TAC_Prefijo\",\"TAC_Clase\",\"TAC_Aplica_Depreciacion\") values ('K6 Tipo $s','K${s: -4}','COMPUTO',1);
insert into \"INV_MARCA\" (\"MAR_Nombre\") values ('K6 Marca $s');
insert into \"INV_MODELO\" (\"MOD_Nombre\",\"MAR_Marca\",\"TAC_Tipo_Activo\") select 'K6 Modelo $s', m.\"MAR_Marca\", t.\"TAC_Tipo_Activo\" from \"INV_MARCA\" m, \"INV_TIPO_ACTIVO\" t where m.\"MAR_Nombre\"='K6 Marca $s' and t.\"TAC_Nombre\"='K6 Tipo $s';
commit;" >/dev/null
cat=$(sql "select '{\"tipo\":'||t.\"TAC_Tipo_Activo\"||',\"modelo\":'||mo.\"MOD_Modelo\"||',\"estado\":'||(select min(\"EOP_Estado_Operativo\") from \"INV_ESTADO_OPERATIVO\" where \"EOP_Nombre\"='En Bodega')||',\"sede\":'||(select min(\"SED_Sede\") from \"INV_SEDE\" where \"SED_Nombre\"='QA Sede Norte')||'}' from \"INV_TIPO_ACTIVO\" t join \"INV_MODELO\" mo on mo.\"TAC_Tipo_Activo\"=t.\"TAC_Tipo_Activo\" where t.\"TAC_Nombre\"='K6 Tipo $s';" | tr -d '[:space:]')
clave=$(docker run --rm -i ghcr.io/jqlang/jq:1.7.1 -r '.usuarios.qa_tecnico_norte.password' < "$QA/usuarios.json")

fallas() {
  local t0; t0=$(date +%s)
  en() { while [ $(( $(date +%s) - t0 )) -lt "$1" ]; do sleep 1; done; echo "[$(date -u +%T)] $2"; }
  en 40 "falla: reinicio de Oracle";      docker restart inventario_qa-oracle-1 >/dev/null
  en 150 "falla: reinicio de Redis";      docker restart inventario_qa-redis-1 >/dev/null
  en 180 "falla: reinicio de MinIO";      docker restart inventario_qa-minio-1 >/dev/null
  en 210 "falla: reinicio de worker_php"; docker restart inventario_qa-worker_php-1 >/dev/null
  en 240 "falla: reinicio de api_php";    docker restart inventario_qa-api_php-1 >/dev/null
  en 280 "falla: +300 ms de red en api_php durante 45 s"
  docker run --rm -v //var/run/docker.sock:/var/run/docker.sock gaiaadm/pumba:0.11.6 netem --duration 45s \
    --tc-image ghcr.io/alexei-led/pumba-alpine-nettools:latest delay --time 300 inventario_qa-api_php-1 >/dev/null 2>&1 || echo "pumba no disponible"
}

[ "$ESC" = fallas ] && { fallas > "$EVID/fallas_cronologia.txt" 2>&1 & }
docker run --rm --network inventario_qa_salida -v "$(cd "$AQUI" && (pwd -W 2>/dev/null || pwd)):/scripts:ro" \
  -v "$(cd "$EVID" && (pwd -W 2>/dev/null || pwd)):/salida" -e ESCENARIO="$ESC" -e DURACION="${DURACION:-15m}" \
  -e QA_USUARIO=qa_tecnico_norte -e QA_CLAVE="$clave" -e QA_CATALOGO="$cat" \
  grafana/k6:1.3.0 run --quiet --summary-export "/salida/$ESC.json" /scripts/carga.js || echo "k6: umbrales no cumplidos (ver $ESC.json)"
wait
[ "$ESC" = fallas ] && cat "$EVID/fallas_cronologia.txt"
docker run --rm -i ghcr.io/jqlang/jq:1.7.1 -r --arg e "$ESC" '"\($e): \(.metrics.http_reqs.count) peticiones, \(.metrics.http_reqs.rate|floor) req/s, p95 \(.metrics.http_req_duration["p(95)"]|floor) ms, p99 \(.metrics.http_req_duration["p(99)"]|floor) ms, fallidas \((.metrics.http_req_failed.value*10000|floor)/100) %, 5xx \(.metrics.errores_5xx.count // 0)"' < "$EVID/$ESC.json"
