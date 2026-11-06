#!/bin/bash
# Se ejecuta UNA vez, al crear la base (gvenzl/oracle-xe: /container-entrypoint-initdb.d).
# Crea en XEPDB1:
#   - ORACLE_SCHEMA_OWNER: dueño del esquema (lo usan el migrador y los respaldos).
#     Privilegios de DDL + CREATE ANY CONTEXT, DBMS_RLS (VPD) y DBMS_CRYPTO (kardex).
#   - ORACLE_APP_USER: usuario de ejecución de la API. Solo CREATE SESSION; el
#     migrador le concede DML sobre las tablas (app/db/permisos.py).
#   - Directorio RESPALDO para Data Pump (/opt/oracle/respaldos).
# El script se carga con `source`: no usar `exit`; un error de SQL detiene el arranque.
for v in ORACLE_SCHEMA_OWNER ORACLE_OWNER_PASSWORD ORACLE_APP_USER ORACLE_APP_PASSWORD; do
  if [ -z "${!v:-}" ]; then
    echo "01_usuarios.sh: falta la variable $v" >&2
    return 1
  fi
done
for v in ORACLE_OWNER_PASSWORD ORACLE_APP_PASSWORD; do
  valor="${!v}"
  if [ "${#valor}" -gt 30 ] || [[ "$valor" == *'"'* ]]; then
    echo "01_usuarios.sh: $v debe tener como máximo 30 caracteres y no contener comillas dobles" >&2
    return 1
  fi
done

if ! sqlplus -s / as sysdba <<SQL
whenever sqlerror exit failure
alter session set container = XEPDB1;

-- Cuentas de servicio: sin caducidad de contraseña (la rotación es manual) y
-- con bloqueo temporal ante intentos fallidos.
create profile INVENTARIO_SERVICIO limit
  password_life_time unlimited
  password_grace_time unlimited
  failed_login_attempts 10
  password_lock_time 1/24;

create user ${ORACLE_SCHEMA_OWNER} identified by "${ORACLE_OWNER_PASSWORD}"
  default tablespace users quota unlimited on users profile INVENTARIO_SERVICIO;
grant create session, create table, create sequence, create view, create procedure,
      create trigger, create type, create synonym to ${ORACLE_SCHEMA_OWNER};
grant create any context, drop any context to ${ORACLE_SCHEMA_OWNER};
grant execute on sys.dbms_rls to ${ORACLE_SCHEMA_OWNER};
grant execute on sys.dbms_crypto to ${ORACLE_SCHEMA_OWNER};
-- Respaldos completos con Data Pump: el dueño ya controla las políticas VPD,
-- así que no gana poder; el usuario de ejecución NUNCA recibe este privilegio.
grant exempt access policy to ${ORACLE_SCHEMA_OWNER};

create user ${ORACLE_APP_USER} identified by "${ORACLE_APP_PASSWORD}" profile INVENTARIO_SERVICIO;
grant create session to ${ORACLE_APP_USER};

create or replace directory RESPALDO as '/opt/oracle/respaldos';
grant read, write on directory RESPALDO to ${ORACLE_SCHEMA_OWNER};
exit
SQL
then
  echo "01_usuarios.sh: falló la creación de usuarios" >&2
  return 1
fi
unset valor
# Marca de inicialización completa (vive en el volumen de datos). El healthcheck
# del compose la exige: la imagen se declara sana al abrir la base, ANTES de
# terminar estos scripts, y el migrador o el primer expdp competirían con ellos.
touch /opt/oracle/oradata/.inventario_init_ok
