#!/usr/bin/env bash
# Crea deploy/.env y deploy/.env.php para una instalación nueva, con claves
# aleatorias. No imprime ningún secreto y nunca sobrescribe un archivo existente:
# en un servidor que ya tiene datos, las claves no se cambian (sin ellas los
# respaldos y los datos cifrados no se pueden leer).
#
#   bash herramientas/generar_env.sh
#
# Después se editan las secciones «EDITAR» de deploy/.env (docs/PUESTA_EN_MARCHA.md).
set -euo pipefail
DEPLOY="$(cd "$(dirname "$0")/../deploy" && pwd)"

for f in .env .env.php; do
  if [ -e "$DEPLOY/$f" ]; then
    echo "Ya existe deploy/$f: no se toca. Si de verdad es una instalación nueva, muévalo antes a otra carpeta." >&2
    exit 1
  fi
done

aleatorio() { tr -dc "$1" </dev/urandom | head -c "$2"; }
hex32() { od -An -tx1 -N32 /dev/urandom | tr -d ' \n'; }
base64_32() { head -c 32 /dev/urandom | base64 | tr -d '\n'; }

declare -A valor=(
  # Oracle: hasta 30 caracteres, sin comillas (oracle/init/01_usuarios.sh).
  [ORACLE_SYS_PASSWORD]="P$(aleatorio 'A-Za-z0-9' 27)"
  [ORACLE_OWNER_PASSWORD]="P$(aleatorio 'A-Za-z0-9' 27)"
  [ORACLE_APP_PASSWORD]="P$(aleatorio 'A-Za-z0-9' 27)"
  [REDIS_PASSWORD]="$(aleatorio 'A-Za-z0-9' 32)"
  [MINIO_ROOT_USER]="raiz-$(aleatorio 'a-z0-9' 8)"
  [MINIO_ROOT_PASSWORD]="$(aleatorio 'A-Za-z0-9' 32)"
  [MINIO_APP_USER]="app-$(aleatorio 'a-z0-9' 8)"
  [MINIO_APP_PASSWORD]="$(aleatorio 'A-Za-z0-9' 32)"
  # MinIO KMS: «nombre:clave de 32 bytes en base64».
  [MINIO_KMS_SECRET_KEY]="inventario-kms:$(base64_32)"
  [SECRET_KEY]="$(hex32)"
  # Varias claves separadas por coma: la primera firma y las demás solo verifican (rotación).
  [HMAC_KEYS]="$(hex32)"
  # Fernet: 32 bytes en base64 con alfabeto URL.
  [FIELD_ENCRYPTION_KEY]="$(base64_32 | tr '+/' '-_')"
)

umask 077
while IFS= read -r linea || [ -n "$linea" ]; do
  linea="${linea%$'\r'}"
  clave="${linea%%=*}"
  if [[ "$linea" =~ ^[A-Z_0-9]+=$ ]] && [ -n "${valor[$clave]:-}" ]; then
    linea="$clave=${valor[$clave]}"
  fi
  printf '%s\n' "$linea"
done <"$DEPLOY/.env.example" >"$DEPLOY/.env"

sed -e "s/^APP_SECRET=.*/APP_SECRET=$(hex32)/" -e 's/\r$//' "$DEPLOY/.env.php.example" >"$DEPLOY/.env.php"

echo "Creados deploy/.env y deploy/.env.php con claves nuevas (no se muestran)."
echo "Siguiente: edite en deploy/.env las secciones «EDITAR» y guarde una copia de ambos archivos en un gestor de contraseñas."
