#!/bin/sh
# Replica BACKUP_DIR fuera del servidor (servicio `backup_replica`, perfil
# «replica» del compose). Gratis: rclone.
# Destinos (se usan los que estén configurados):
#   - /replica: carpeta del host en BACKUP_REPLICA_DIR (otro disco, unidad de red
#     SMB/NFS montada o carpeta sincronizada con otro equipo).
#   - BACKUP_REPLICA_REMOTE: remoto de rclone (sftp:, smb:, s3: compatible…)
#     definido en deploy/backup/rclone.conf (no se versiona).
# Cada BACKUP_REPLICA_INTERVAL_SECONDS (60): copia lo nuevo. Es `copy`, no `sync`:
# borrar o cifrar (ransomware) los respaldos del servidor no se propaga; la
# réplica poda por su cuenta lo que tenga más de BACKUP_REPLICA_RETENTION_DAYS.
set -eu
INTERVALO="${BACKUP_REPLICA_INTERVAL_SECONDS:-60}"
RETENCION="${BACKUP_REPLICA_RETENTION_DAYS:-30}"
# Solo respaldos terminados: expdp escribe *.parcial y lo renombra a .dmp al
# terminar (y *.tmp: temporales); --min-age 2m cubre además los adjuntos recién escritos.
OPCIONES="--min-age 2m --exclude restauracion_*.log --exclude *.parcial --exclude *.parcial.log --exclude *.tmp --transfers 2 --retries 5 --low-level-retries 10"

destinos() {
  [ -d /replica ] && [ -w /replica ] && echo /replica
  [ -n "${BACKUP_REPLICA_REMOTE:-}" ] && echo "$BACKUP_REPLICA_REMOTE"
  return 0
}

[ -n "$(destinos)" ] || { echo "[replica] sin destino: defina BACKUP_REPLICA_DIR o BACKUP_REPLICA_REMOTE" >&2; exit 1; }

ciclo=0
while true; do
  for d in $(destinos); do
    # shellcheck disable=SC2086
    if rclone copy /origen "$d" $OPCIONES --config /config/rclone.conf 2>/tmp/rclone.err; then
      date -u +%s > /tmp/ultimo_ok
    else
      echo "[replica] $(date -u +%FT%TZ) ERROR copiando a $d: $(tail -1 /tmp/rclone.err)" >&2
    fi
    # La poda corre una vez por hora, no en cada ciclo.
    if [ $((ciclo % (3600 / INTERVALO + 1))) -eq 0 ]; then
      rclone delete "$d" --min-age "${RETENCION}d" --config /config/rclone.conf 2>/dev/null || true
    fi
  done
  ciclo=$((ciclo + 1))
  sleep "$INTERVALO"
done
