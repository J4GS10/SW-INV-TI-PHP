#!/bin/sh
# Respaldo continuo de los adjuntos (servicio `backup_minio` del compose).
# Espeja el bucket en /backups/minio/<bucket>. Sin --remove: un archivo eliminado
# por error en el sistema sigue en el respaldo.
# - BACKUP_MINIO_CONTINUO=1 (por defecto): espejo completo y luego `mc mirror
#   --watch`, que copia cada adjunto apenas se sube (RPO de segundos). Si la
#   vigilancia se corta (MinIO reiniciado, red), se reintenta con otro espejo
#   completo, así que no se pierde lo subido mientras tanto.
# - BACKUP_MINIO_CONTINUO=0: un espejo completo cada BACKUP_INTERVAL_MINUTES.
set -eu
INTERVALO_MIN="${BACKUP_INTERVAL_MINUTES:-60}"
BUCKET="${S3_BUCKET:-lombardi-adjuntos}"
DEST="/backups/minio/$BUCKET"
mkdir -p "$DEST"

conectar() {
  until mc alias set origen http://minio:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" > /dev/null 2>&1; do
    echo "[backup_minio] MinIO no responde; reintento en 30 s" >&2
    sleep 30
  done
}

respaldar() {
  echo "[backup_minio] $(date -u +%FT%TZ) espejando $BUCKET"
  if mc mirror --overwrite --preserve "origen/$BUCKET" "$DEST"; then
    date -u +%s > /backups/minio/.ultimo_ok
    echo "[backup_minio] OK"
  else
    echo "[backup_minio] ERROR: mc mirror falló" >&2
  fi
}

conectar
if [ "${1:-}" = "--una-vez" ]; then
  respaldar
  exit 0
fi
while true; do
  respaldar
  if [ "${BACKUP_MINIO_CONTINUO:-1}" = "1" ]; then
    echo "[backup_minio] vigilando cambios (mirror --watch)"
    mc mirror --watch --overwrite --preserve "origen/$BUCKET" "$DEST" || true
    echo "[backup_minio] la vigilancia se cortó; nuevo espejo completo en 30 s" >&2
    sleep 30
    conectar
  else
    sleep $((INTERVALO_MIN * 60))
  fi
done
