#!/bin/bash
# Respaldo periódico de Oracle con Data Pump (servicio `backup_db` del compose).
# - expdp del esquema ORACLE_SCHEMA_OWNER (tablas, datos, paquetes, disparadores
#   y permisos; las políticas VPD las reinstala el migrador al restaurar).
# - Lo escribe el servidor en el directorio RESPALDO (/opt/oracle/respaldos), que
#   es BACKUP_DIR/oracle en el host: inventario_AAAAMMDD_HHMMSS.dmp + .log
# - Cada BACKUP_INTERVAL_MINUTES (60 por defecto): el RPO es ese intervalo. Es
#   consistente (flashback_time) y no bloquea a los usuarios.
# - Retención escalonada: se conservan TODOS los respaldos de las últimas
#   BACKUP_RETENTION_HOURS horas (48) y, de ahí hacia atrás, el primero de cada
#   día hasta BACKUP_RETENTION_DAYS días (14).
# - Verifica cada respaldo (el log debe terminar "successfully completed") y deja
#   la hora del último correcto en .ultimo_ok: el healthcheck y el monitoreo la usan.
set -euo pipefail
DEST=/opt/oracle/respaldos
INTERVALO_MIN="${BACKUP_INTERVAL_MINUTES:-$(( ${BACKUP_INTERVAL_HOURS:-1} * 60 ))}"
RETENCION_HORAS="${BACKUP_RETENTION_HOURS:-48}"
RETENCION_DIAS="${BACKUP_RETENTION_DAYS:-14}"
RETENCION_MINIMA="${BACKUP_RETENTION_MIN_COUNT:-3}"
CONEXION="//${ORACLE_HOST:-oracle}:${ORACLE_PORT:-1521}/${ORACLE_SERVICE_NAME:-XEPDB1}"

# La contraseña va en un parfile privado, no en la línea de comandos (ps).
PARFILE="$(mktemp)"
chmod 600 "$PARFILE"
trap 'rm -f "$PARFILE"' EXIT
printf 'userid="%s/%s@%s"\n' "$ORACLE_SCHEMA_OWNER" "$ORACLE_OWNER_PASSWORD" "$CONEXION" > "$PARFILE"
# Las tablas maestras de trabajos Data Pump (SYS_EXPORT_SCHEMA_NN…) no son datos
# de la aplicación: no van en el respaldo.
echo 'exclude=TABLE:"IN (SELECT job_name FROM user_datapump_jobs)"' >> "$PARFILE"

# Un expdp interrumpido deja su tabla maestra en el esquema (trabajo NOT RUNNING
# sin sesiones): se elimina antes de respaldar. La contraseña va por stdin.
limpiar_trabajos_huerfanos() {
  printf 'connect %s/"%s"@%s\nset feedback off\nbegin\n  for j in (select job_name from user_datapump_jobs where state = %s and attached_sessions = 0) loop\n    execute immediate %s || j.job_name || %s;\n  end loop;\nend;\n/\nexit\n' \
    "$ORACLE_SCHEMA_OWNER" "$ORACLE_OWNER_PASSWORD" "$CONEXION" "'NOT RUNNING'" "'drop table \"'" "'\" purge'" \
    | sqlplus -s /nolog > /dev/null 2>&1 || echo "[backup_db] aviso: no se pudieron limpiar trabajos Data Pump huérfanos" >&2
}

podar() {
  ahora=$(date +%s)
  frecuentes=$(( ahora - RETENCION_HORAS * 3600 ))
  diarios=$(( ahora - RETENCION_DIAS * 86400 ))
  dias_vistos=" "
  # Los RETENCION_MINIMA más recientes nunca se borran: si expdp falla durante semanas, la poda por
  # antigüedad no deja la base sin ningún respaldo válido (fase 8).
  conservar=" $(ls -1 "$DEST"/inventario_*.dmp 2>/dev/null | sort | tail -n "$RETENCION_MINIMA" | tr '\n' ' ') "
  # Del más antiguo al más nuevo: el primero de cada día es el que se conserva.
  for dmp in $(ls -1 "$DEST"/inventario_*.dmp 2>/dev/null | sort); do
    case "$conservar" in *" $dmp "*) continue ;; esac
    base="${dmp%.dmp}"
    dia="$(basename "$dmp" | cut -d_ -f2)"
    edad=$(stat -c %Y "$dmp")
    if [ "$edad" -lt "$diarios" ]; then
      rm -f "$dmp" "$base.log"
    elif [ "$edad" -lt "$frecuentes" ]; then
      case "$dias_vistos" in
        *" $dia "*) rm -f "$dmp" "$base.log" ;;
        *) dias_vistos="$dias_vistos$dia " ;;
      esac
    else
      dias_vistos="$dias_vistos$dia "
    fi
  done
}

# Un respaldo interrumpido (corte de luz, `docker compose down` a mitad del
# expdp) deja un .parcial: nunca se confunde con un respaldo válido.
limpiar_parciales() {
  for p in "$DEST"/inventario_*.parcial; do
    [ -e "$p" ] || continue
    rm -f "$p"
    [ -e "$p.log" ] && mv -f "$p.log" "${p%.parcial}.error.log"
  done
  # Los logs de respaldos fallidos se guardan para diagnóstico, con la retención diaria.
  limite=$(( $(date +%s) - RETENCION_DIAS * 86400 ))
  for l in "$DEST"/inventario_*.error.log; do
    [ -e "$l" ] && [ "$(stat -c %Y "$l")" -lt "$limite" ] && rm -f "$l"
  done
  return 0
}

respaldar() {
  local rc=0
  nombre="inventario_$(date -u +%Y%m%d_%H%M%S)"
  echo "[backup_db] $(date -u +%FT%TZ) iniciando -> $nombre.dmp"
  limpiar_trabajos_huerfanos
  # Se escribe como .parcial y solo pasa a .dmp cuando el log confirma que terminó.
  # 0 errores: con EXEMPT ACCESS POLICY el VPD no filtra el respaldo.
  if expdp parfile="$PARFILE" schemas="$ORACLE_SCHEMA_OWNER" directory=RESPALDO \
       dumpfile="$nombre.parcial" logfile="$nombre.parcial.log" compression=METADATA_ONLY \
       flashback_time=systimestamp > /dev/null 2>&1 \
     && grep -q "successfully completed at" "$DEST/$nombre.parcial.log"; then
    mv -f "$DEST/$nombre.parcial.log" "$DEST/$nombre.log"
    mv -f "$DEST/$nombre.parcial" "$DEST/$nombre.dmp"
    echo "[backup_db] OK $(du -h "$DEST/$nombre.dmp" | cut -f1)"
    date -u +%s > "$DEST/.ultimo_ok"
  else
    echo "[backup_db] ERROR: expdp falló (p. ej. disco lleno), ver $nombre.error.log" >&2
    rc=1
  fi
  limpiar_parciales
  podar
  # Fase 7: el código de salida refleja la falla (con --una-vez, quien lo llama lo ve).
  return $rc
}

# Healthcheck: sano si el último respaldo correcto tiene menos de 2 intervalos
# (más 15 min de margen para el primero tras arrancar).
if [ "${1:-}" = "--salud" ]; then
  ultimo=$(cat "$DEST/.ultimo_ok" 2>/dev/null || echo 0)
  [ $(( $(date -u +%s) - ultimo )) -lt $(( INTERVALO_MIN * 120 + 900 )) ]
  exit $?
fi
if [ "${1:-}" = "--una-vez" ]; then
  respaldar
  exit $?
fi
limpiar_parciales
while true; do
  # Una falla no detiene el servicio: el siguiente intento llega en el próximo intervalo
  # y el healthcheck (--salud) avisa si no hay un respaldo correcto reciente.
  respaldar || true
  sleep $((INTERVALO_MIN * 60))
done
