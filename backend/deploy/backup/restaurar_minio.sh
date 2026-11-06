#!/bin/sh
# Restaura los adjuntos desde el respaldo al bucket (no borra objetos existentes).
# Uso (desde deploy/):
#   docker compose run --rm --entrypoint /bin/sh backup_minio /backup/restaurar_minio.sh
# --entrypoint es obligatorio: el de backup_minio es respaldo_minio.sh.
set -eu
BUCKET="${S3_BUCKET:-lombardi-adjuntos}"
ORIGEN="/backups/minio/$BUCKET"
# Un bucket sin adjuntos no deja carpeta en el respaldo (ni en la réplica: rclone
# no copia carpetas vacías). No es un error, pero se avisa por si BACKUP_DIR está mal.
if [ ! -d "$ORIGEN" ]; then
  [ -f /backups/minio/.ultimo_ok ] || { echo "No hay respaldo de adjuntos en /backups/minio: revise BACKUP_DIR." >&2; exit 1; }
  echo "El respaldo no contiene adjuntos del bucket $BUCKET: nada que restaurar."
  exit 0
fi
mc alias set destino http://minio:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD" > /dev/null
mc mirror --overwrite "$ORIGEN" "destino/$BUCKET"
echo "Adjuntos restaurados en $BUCKET."
