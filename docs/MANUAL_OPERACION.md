# Manual breve de operación

## Quién hace qué

| Rol | Tarea |
|---|---|
| Administrador del sistema (`SUPER_ADMIN`) | Usuarios, configuración, logo, directorio, MFA y auditoría. No ve datos de negocio. |
| Administrador de seguridad (`ADMIN_SEGURIDAD`) | Consulta usuarios, sesiones y auditoría, y puede cerrar sesiones. No modifica cuentas. |
| Administrador de TI (`ADMIN_TI`) | Inventario, catálogos, aprobaciones y cierre mensual. Nadie aprueba lo que pidió. |
| Técnico (`TECNICO`) | Confirmar ingresos, completar series, movimientos, mantenimiento y tomas. Sin costos. |
| Auditor (`AUDITOR`) | Lectura del inventario y de la auditoría. |
| Consulta (`CONSULTA`) | Lectura del inventario. Sin costos ni personas. |
| Compras y Contabilidad (`COMPRAS`) | Ingresos, proveedores, stock y asignaciones, cierre mensual y exportes. No opera el inventario. |

Para aprobar bajas y diferencias hacen falta **al menos dos** administradores de TI.

## Ingreso a bodega

1. **Compras** abre *Ingresos a bodega → Nuevo ingreso*, indica proveedor, factura, productos, cantidades y costos, y adjunta la hoja.
2. **TI** recibe el aviso, abre el ingreso, cuenta lo que llegó y clasifica cada línea como activo, consumible, licencia o no inventariable.
3. Sin diferencias, el ingreso entra al inventario. Con diferencias, **otro** administrador de TI lo aprueba o lo rechaza; hasta entonces no entra nada.
4. **Compras** descarga el *acta de recibido*.
5. **El técnico** abre *Por completar*, captura la serie con el lector y las especificaciones, e imprime las etiquetas.

Un activo por completar no se puede asignar ni aparece como esperado en una toma.
El ingreso a bodega es la única vía normal de entrada. Hay dos excepciones, ambas del administrador de TI:- **Nuevo activo:** solo para equipos sin factura, como una donación.- **Importar desde Excel:** solo para la carga inicial; deja de estar disponible con el primer cierre mensual.

## Baja por pérdida o robo

En la ficha o en el menú de la fila del activo, use *Reportar pérdida o robo* (administrador de TI): no hace falta registrar una devolución. Al aprobarse, la custodia se cierra y queda anotada en el kardex.

## Cierre mensual

Compras y Contabilidad consulta el cierre en cualquier momento. Un administrador de TI lo congela con *Cerrar el mes*, solo cuando el mes terminó. Después de eso sus cifras ya no cambian.

## Toma de inventario con muchos activos

Una toma de hasta 500 activos esperados se cierra al momento. Una mayor pasa a *Cerrando* y el worker la termina en el siguiente minuto. Si falla, sigue en *Cerrando*, queda en el registro `toma.cierre_fallido` y se reintenta sola.

## Exportar activos

El exporte usa los filtros de la pantalla. Si el resultado supera 10.000 filas, el sistema lo indica y pide acotar con filtros: nunca entrega un archivo incompleto sin avisar.

## Logo

En *Configuración*, use *Subir imagen* (PNG, JPG, WebP o GIF de hasta 2 MB). También puede elegir *Usar un enlace*: debe empezar con `https://` y mostrar una imagen; si no, el sistema lo indica y no lo guarda.

## Respaldos

- `bash herramientas/desplegar.sh respaldar` hace un respaldo verificado. Es obligatorio antes de cada despliegue.
- El servicio `backup_db` respalda cada 60 minutos: en el peor caso se pierde hasta una hora de datos.
- `BACKUP_REPLICA_DIR` apunta a otro disco (`D:`) de este equipo. Protege si falla el disco principal, no si se daña o se pierde el equipo.
- Para restaurar: `CONFIRMAR=SI` con `deploy/backup/restaurar_db.sh <archivo.dmp>`; después se levantan `migrador`, `api_php` y `worker_php`.

## Acceso de emergencia

Se usa cuando nadie puede entrar como SUPER_ADMIN para restablecer un MFA: teléfono y códigos de recuperación perdidos, o todas esas cuentas bloqueadas. Cualquier otra cuenta la recupera un SUPER_ADMIN desde *Usuarios* (*Restablecer MFA*).

- Para prevenirlo, tenga **dos cuentas SUPER_ADMIN** de personas distintas, cada una con su MFA y sus códigos de recuperación impresos y guardados fuera del equipo.
- Si aun así se pierde el acceso, quien administra el equipo del sistema ejecuta:

  ```
  docker exec -it inventario-api_php-1 php bin/console app:acceso-emergencia <usuario> \
      --confirmar=<usuario> --motivo="<por qué>" --operador="<quién lo ejecuta>"
  ```

- Quita el MFA, los códigos de recuperación, los equipos recordados, las sesiones abiertas y los bloqueos. En el siguiente ingreso el sistema obliga a configurar un MFA nuevo.
- Con `--contrasena-temporal` emite además una contraseña temporal, que se muestra una sola vez y debe cambiarse al entrar. Úsela solo si también se perdió la clave.
- Solo sirve para cuentas SUPER_ADMIN activas. El motivo (mínimo 10 caracteres) y el operador son obligatorios.
- Queda en la *Auditoría del sistema* como `EMERGENCY_ACCESS_RESET`, con motivo y operador. Revise ese registro después de cada uso.
- Solo puede ejecutarlo quien tiene acceso de administrador al equipo donde corre el sistema: proteja ese acceso como el de la propia base de datos.

## Alertas

El worker revisa cada 5 minutos respaldos, réplica, disco, correos y tomas. Un problema aparece como aviso a los administradores y, si hay SMTP, por correo una vez al día. Queda en la auditoría al abrirse y al cerrarse.

## Acceso desde la red local

- Dirección: `https://<IP del equipo>`. El navegador avisa del certificado mientras el equipo no tenga instalada la raíz de la CA privada de Caddy.
- Si la IP cambia (por ejemplo, de cable a wifi), actualice `DOMAIN`, `BACKEND_CORS_ORIGINS`, `FRONTEND_BASE_URL` y `TLS_DEFAULT_SNI` en `deploy/.env`, y ejecute `bash herramientas/desplegar.sh subir`. `DOMAIN` y `BACKEND_CORS_ORIGINS` admiten varias direcciones.
- El sistema funciona mientras este equipo y Docker estén encendidos.

### Nombre formal: `inventario.lombardi.group`

El nombre formal es `https://inventario.lombardi.group`. Con él se arman los QR de las etiquetas (*Configuración → Dirección del sistema*). El servidor ya lo atiende con su certificado (está en `DOMAIN` y `BACKEND_CORS_ORIGINS`); falta que la red lo conozca.

1. **IP fija del servidor.** Reserve la IP del equipo en el DHCP, ligada a su MAC (Wi-Fi o cable, la tarjeta con la que va a quedar), para que no cambie.
   - Servidor de Windows: consola *DHCP* → el ámbito → *Reservas* → *Nueva reserva*.
   - Router: *DHCP* → *Reserva de dirección* o *IP estática*.
2. **Registro DNS.** Cree un registro A con el nombre `inventario` en la zona `lombardi.group`, que apunte a esa IP.
   - DNS de Windows Server (Active Directory): *Administrador de DNS* → *Zonas de búsqueda directa* → `lombardi.group` (si no existe, *Zona nueva*, principal e integrada en AD) → *Host nuevo (A o AAAA)*. Nombre: `inventario`, dirección: la IP reservada. Sin marcar «Crear registro PTR» si no hay zona inversa.
   - Router con DNS local: *DNS local*, *DNS estático* o *Host local* → `inventario.lombardi.group` → la IP.
   - Si `lombardi.group` es un dominio público de la empresa, el registro A también puede ir en su proveedor de DNS, aun con la IP privada: dentro de la oficina abre y desde afuera no lleva a nada.
3. **Validarlo** desde un equipo de la red:
   - `nslookup inventario.lombardi.group` (o, en PowerShell, `Resolve-DnsName inventario.lombardi.group`) debe responder la IP del servidor.
   - En el navegador, `https://inventario.lombardi.group/health` debe responder `ok`.
   - En el teléfono, conectado al mismo Wi-Fi, abrir `https://inventario.lombardi.group`; escanear el QR de una etiqueta nueva debe abrir la ficha.
   - El aviso de certificado desaparece al instalar la raíz de la CA privada en cada equipo ([puesta en marcha](PUESTA_EN_MARCHA.md#6-certificado-con-la-ca-privada)).
4. **Cuando valide:** en `deploy/.env` ponga `FRONTEND_BASE_URL=https://inventario.lombardi.group` y `TLS_DEFAULT_SNI=inventario.lombardi.group` (los enlaces de los correos y el regreso del inicio con Microsoft o Google) y ejecute `bash herramientas/desplegar.sh subir`. Reimprima las etiquetas una vez. Desde ahí, si la IP cambia, solo se actualiza el registro DNS.
