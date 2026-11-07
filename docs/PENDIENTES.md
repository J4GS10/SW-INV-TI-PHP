# Pendientes

## Antes de cargar datos reales

| Ajuste | Hoy | Para el uso real |
|---|---|---|
| Cuentas | Una por rol con clave sencilla | Desactivarlas y crear las reales |
| MFA | Apagado; `MFA_CAN_BE_DISABLED=true` | Encender y `MFA_CAN_BE_DISABLED=false` |
| Datos | Activos, sedes y personas de prueba, y empresa «prueba» | Partir de datos limpios |
| Dirección | IP con certificado de prueba | Dominio y certificado definitivos |
| Correo | `SMTP_HOST` vacío: no sale ningún correo, tampoco la recuperación de clave | Configurar SMTP |
| Servidor | Corre en un PC; los respaldos, en otro disco del mismo equipo | Servidor definitivo y copia fuera del equipo |

## Decisiones abiertas

| Tema | Situación | Recomendación |
|---|---|---|
| Cerrar el mes con trabajo pendiente | Se puede cerrar con ingresos o aprobaciones sin resolver | Avisar antes de cerrar |
| Mes cerrado por error | No se puede reabrir | Reapertura con aprobación de otra persona y registro en auditoría |
| Listas cerradas | Sistema Operativo y Tarjeta Gráfica se escriben a mano | Lista cerrada, al menos para Sistema Operativo |
| Administradores de TI | Nadie aprueba lo que pidió: hacen falta dos activos | Alerta cuando quede solo uno |
| Oracle XE | Sin Data Guard; réplica por archivos con hasta 1 hora de pérdida | Confirmar la fecha de soporte de 21c |
| Tablas de órdenes de compra | Sin uso desde que se retiró el módulo, con 0 filas: `INV_ORDEN_COMPRA`, sus líneas y `INV_RECEPCION` | Borrarlas con una migración cuando se confirme que no vuelve |

## Calidad sin confirmar

- E2E completo en todos los navegadores y móvil. `ui/ingreso.spec.ts` debe escribir en la unidad recién creada, no en la primera de la lista.
- Reportar pérdida o robo hasta aprobarlo: se comprobó el formulario, no la aprobación desde la pantalla.
- Prueba de resistencia, revisión independiente de código y seguridad, y prueba con personas reales de cada rol.
- Lector de códigos en un teléfono físico.
- Volumen: tomas probadas con 501 activos. Salvo Activos, Auditoría y Trazabilidad, los listados cargan todo de una vez.
- Un 503 de un adjunto tras una caída de MinIO, sin causa conocida.

## Menores

- Traducir al mostrarlos los motivos que escribe el servidor en español.
- El CSV del kardex muestra identificadores internos.
- Exportes de Tomas, Modelos y Departamentos. Reintento tras un error de carga fuera de Modelos.
