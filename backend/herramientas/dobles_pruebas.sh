#!/usr/bin/env bash
# Dobles de los servicios externos en la red de pruebas (se reutilizan si ya corren).
# Lo usan herramientas/pruebas.sh y herramientas/resistencia.sh (con `source`).
#
#   mailpit           SMTP de pruebas: los correos se entregan de verdad y se consultan por su API.
#   minio-pruebas     S3 (misma imagen que producción). Nombre con guion: MinIO rechaza un Host con guion bajo.
#   samba-ad-pruebas  Active Directory (Samba AD, docker/pruebas/samba-ad): LDAPS/StartTLS con su CA,
#                     que se copia a api/var para verificar el certificado. Nunca se usa el AD real.
#   redis-pruebas     cerrojos de las tareas (lock:job:*) entre procesos y contenedores.
#
# Requiere RAIZ (ruta nativa del repo), AQUI y RED. CA_CORPORATIVA=<pem> solo para el build de Samba
# en una red con inspección TLS (secreto de BuildKit; no queda en la imagen).

docker network inspect "$RED" >/dev/null 2>&1 || docker network create "$RED" >/dev/null

arrancar() { # nombre, argumentos de docker run…
  local nombre="$1"; shift
  if [ -z "$(docker ps -q -f "name=^${nombre}$")" ]; then
    docker rm -f "$nombre" >/dev/null 2>&1 || true
    docker run -d --name "$nombre" --network "$RED" "$@" >/dev/null
  fi
}

arrancar mailpit axllent/mailpit:v1.27
arrancar minio-pruebas -e MINIO_ROOT_USER=pruebas -e MINIO_ROOT_PASSWORD=pruebas-minio-2026 \
  pgsty/minio@sha256:b6bfe7239bfc83fb90d31612d9704d86039dd714f7904b3f1ad68f211e602372 server /data
arrancar redis-pruebas redis:7-alpine
if [ -z "$(docker ps -q -f name=^samba-ad-pruebas$)" ]; then
  secreto=()
  [ -n "${CA_CORPORATIVA:-}" ] && secreto=(--secret "id=ca_corporativa,src=$CA_CORPORATIVA")
  docker build -q "${secreto[@]}" -t inventario-samba-ad-pruebas "$RAIZ/docker/pruebas/samba-ad" >/dev/null
  arrancar samba-ad-pruebas --hostname dc1 --network-alias dc1.pruebas.local inventario-samba-ad-pruebas
fi
until [ "$(docker inspect -f '{{.State.Health.Status}}' samba-ad-pruebas)" = "healthy" ]; do sleep 2; done
mkdir -p "$AQUI/../api/var"
docker exec samba-ad-pruebas cat /var/lib/samba/private/tls/ca.pem > "$AQUI/../api/var/samba-ad-ca.pem"
