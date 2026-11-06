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

## Alertas

El worker revisa cada 5 minutos respaldos, réplica, disco, correos y tomas. Un problema aparece como aviso a los administradores y, si hay SMTP, por correo una vez al día. Queda en la auditoría al abrirse y al cerrarse.

## Acceso desde la red local

- Dirección: `https://<IP del equipo>`. El navegador avisa del certificado de prueba.
- Si la IP cambia (por ejemplo, de cable a wifi), actualice `DOMAIN`, `BACKEND_CORS_ORIGINS`, `FRONTEND_BASE_URL` y `TLS_DEFAULT_SNI` en `deploy/.env`, y ejecute `bash herramientas/desplegar.sh subir`. `DOMAIN` y `BACKEND_CORS_ORIGINS` admiten varias direcciones.
- El sistema funciona mientras este equipo y Docker estén encendidos.
