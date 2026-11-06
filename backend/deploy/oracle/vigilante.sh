#!/bin/bash
# PID 1 del contenedor Oracle: lanza el entrypoint de la imagen y lo vigila.
#
# Si la instancia muere dentro del contenedor (PMON ausente: falla de memoria,
# proceso matado, ORA-00600…), el entrypoint de la imagen sigue vivo con el
# `tail -f` del alert log y el contenedor nunca termina: Docker no lo reinicia y
# el sistema queda caído (probado: 2026-10-26). Aquí se detecta y se sale con
# error para que `restart: always` lo levante (recuperación de instancia
# automática, ~15 s). Un `docker stop` sigue haciendo `shutdown immediate`.
set -u
/opt/oracle/container-entrypoint.sh "$@" &
hijo=$!

detener() {
  kill -TERM "$hijo" 2>/dev/null
  wait "$hijo"
  exit $?
}
trap detener TERM INT

pmon_vivo() {
  local p
  for p in /proc/[0-9]*; do
    case "$(tr -d '\0' < "$p/cmdline" 2>/dev/null)" in
      *_pmon_*) return 0 ;;
    esac
  done
  return 1
}

arrancada=0
while kill -0 "$hijo" 2>/dev/null; do
  if pmon_vivo; then
    arrancada=1
  elif [ "$arrancada" = 1 ]; then
    echo "VIGILANTE: la instancia Oracle se detuvo (sin PMON); se reinicia el contenedor." >&2
    kill -TERM "$hijo" 2>/dev/null
    for _ in $(seq 20); do kill -0 "$hijo" 2>/dev/null || break; sleep 1; done
    exit 1
  fi
  # sleep en segundo plano + wait: la señal TERM se atiende de inmediato.
  sleep 10 &
  wait $!
done
wait "$hijo"
