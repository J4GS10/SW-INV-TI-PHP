#!/bin/bash
# Oracle de PRUEBAS del backend PHP (herramientas/pruebas.sh). Crea los mismos
# usuarios que producción (deploy/oracle/init/01_usuarios.sh), salvo que el dueño
# NO recibe «exempt access policy» (las pruebas pasan por el VPD real):
#   INVENTARIO      dueño del esquema: el migrador (app:esquema:actualizar) crea
#                   tablas, capa de seguridad y permisos; las pruebas se conectan con él.
#   INVENTARIO_APP  usuario de ejecución: solo CREATE SESSION; el migrador le concede DML.
# El esquema NO se carga aquí: lo crean las migraciones (base vacía → migraciones).
# Se carga con `source` (gvenzl/oracle-xe): no usar `exit`.
CLAVE="${TEST_SCHEMA_PASSWORD:-InventarioTest2026}"
if ! sqlplus -s / as sysdba <<SQL
whenever sqlerror exit failure
alter session set container = XEPDB1;
create user INVENTARIO identified by "${CLAVE}"
  default tablespace users quota unlimited on users;
grant create session, create table, create sequence, create view, create procedure,
      create trigger, create type, create synonym to INVENTARIO;
grant create any context, drop any context to INVENTARIO;
grant execute on sys.dbms_rls to INVENTARIO;
grant execute on sys.dbms_crypto to INVENTARIO;
create user INVENTARIO_APP identified by "${CLAVE}";
grant create session to INVENTARIO_APP;
exit
SQL
then
  echo "01_usuarios.sh: falló la creación de los usuarios de prueba" >&2
  return 1
fi
touch /opt/oracle/oradata/.inventario_init_ok
