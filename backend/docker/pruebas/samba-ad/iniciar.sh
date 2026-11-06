#!/bin/sh
# Aprovisiona el dominio de pruebas (una vez por contenedor), carga la semilla
# de usuarios y grupos, y deja Samba en primer plano.
set -eu
ADMIN_PASS="${SAMBA_ADMIN_PASS:-Pruebas.Admin.2026}"
SVC_PASS="${SAMBA_SVC_PASS:-Servicio.Inventario.2026}"
CLAVE="Usuario.Prueba.2026"
BASE="DC=pruebas,DC=local"

if [ ! -f /var/lib/samba/private/sam.ldb ]; then
  # Sin xattrs del sistema de archivos (posix:eadb): no hace falta un contenedor privilegiado.
  samba-tool domain provision --realm=PRUEBAS.LOCAL --domain=PRUEBAS --server-role=dc \
    --dns-backend=SAMBA_INTERNAL --adminpass="$ADMIN_PASS" --host-name=dc1 \
    --option="posix:eadb = /var/lib/samba/private/eadb.tdb" --option="tls enabled = yes" >/dev/null
fi

samba --foreground --no-process-group &
PID=$!

if [ ! -f /var/lib/samba/.semilla_ok ]; then
  until ldbsearch -H /var/lib/samba/private/sam.ldb -b "$BASE" -s base dn >/dev/null 2>&1; do sleep 1; done
  sleep 3
  st() { samba-tool "$@" -H /var/lib/samba/private/sam.ldb >/dev/null; }
  st ou add "OU=Usuarios,$BASE"
  st ou add "OU=Grupos,$BASE"
  st ou add "OU=Servicio,$BASE"
  # Cuenta de servicio fuera de la OU sincronizada (bind con UPN).
  st user create svc.inventario "$SVC_PASS" --userou=OU=Servicio --mail-address=svc.inventario@ad.pruebas.local \
    --given-name=Servicio --surname=Inventario
  st user create jefe.ti "$CLAVE" --userou=OU=Usuarios --mail-address=Jefe.TI@AD.Pruebas.Local \
    --given-name="Carlos" --surname="De la Cruz Pérez" --department="Tecnología AD" --job-title="Jefe de TI AD" \
    --telephone-number=22220000
  st user create ana.lopez "$CLAVE" --userou=OU=Usuarios --mail-address=ana.lopez@ad.pruebas.local \
    --given-name="Ana María" --surname="López García" --department="Tecnología AD" --job-title="Analista AD"
  st user create baja.ad "$CLAVE" --userou=OU=Usuarios --mail-address=baja.ad@ad.pruebas.local \
    --given-name="Baja" --surname="Deshabilitada"
  st user disable baja.ad
  # Usuario sin correo: el filtro por defecto (mail=*) no lo devuelve.
  st user create sin.correo "$CLAVE" --userou=OU=Usuarios --given-name="Sin" --surname="Correo"
  # Jefe (atributo manager) de ana.lopez.
  cat > /tmp/jefe.ldif <<EOF
dn: CN=Ana María López García,OU=Usuarios,$BASE
changetype: modify
replace: manager
manager: CN=Carlos De la Cruz Pérez,OU=Usuarios,$BASE
EOF
  ldbmodify -H /var/lib/samba/private/sam.ldb /tmp/jefe.ldif >/dev/null
  # Grupos: Soporte-N2 (con ana.lopez) anidado en Inventario-Admins (con jefe.ti).
  st group add Inventario-Admins --groupou=OU=Grupos
  st group add Soporte-N2 --groupou=OU=Grupos
  st group add "Grupo (especial)" --groupou=OU=Grupos
  st group addmembers Soporte-N2 ana.lopez
  st group addmembers Inventario-Admins jefe.ti,Soporte-N2,baja.ad
  touch /var/lib/samba/.semilla_ok
fi

wait "$PID"
