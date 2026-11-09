# Pendientes

## Antes de cargar datos reales

Hecho el 2026-11-09: base y adjuntos reinstalados desde cero, solo con el catálogo mínimo y la cuenta raíz `sa`. MFA obligatorio para SUPER_ADMIN, ADMIN_SEGURIDAD y ADMIN_TI, con `MFA_CAN_BE_DISABLED=false`. El respaldo con los datos de prueba es `inventario_20261109_170833.dmp`.

| Ajuste | Hoy | Para el uso real |
|---|---|---|
| Cuentas | Solo `sa` | `sa` configura MFA al entrar y crea las cuentas reales: al menos dos ADMIN_TI |
| Empresa | «Prueba Lombardi» | Nombre real en *Configuración* |
| Dirección | CA privada de Caddy (`TLS_EMISOR=internal`) para `localhost` y las IP de este PC, que cambia con el Wi-Fi (hoy 192.168.7.122) | Nombre interno fijo del servidor formal (DNS) y la raíz de la CA instalada en los equipos ([puesta en marcha](PUESTA_EN_MARCHA.md#6-certificado-con-la-ca-privada)) |
| Correo | `SMTP_HOST` vacío: no sale ningún correo, tampoco la recuperación de clave | Configurar SMTP más adelante |
| Servidor | Corre en un PC. `BACKUP_DIR` apunta a la carpeta antigua `26.10.13-sw-ti-agente` y la réplica va a otro disco del mismo equipo | Servidor formal en preparación: respaldos en su propia carpeta y copia fuera del equipo |
| Limpieza del equipo | Imágenes viejas de `inventario-php` (fase4, fase5, fase6, qa-nueva, resistencia, qa), `inventario-php-dev` e `inventario-frontend:qa`, y los contenedores del backend en Python (`inventario-backend-1`, que aún monta `inventario_adjuntos_data`, e `inventario-migrator-1`) | Borrarlos con aprobación; conservar `anterior` |

## Decisiones abiertas

| Tema | Situación | Recomendación |
|---|---|---|
| Cerrar el mes con trabajo pendiente | Se puede cerrar con ingresos o aprobaciones sin resolver | Avisar antes de cerrar |
| Mes cerrado por error | No se puede reabrir | Reapertura con aprobación de otra persona y registro en auditoría |
| Listas cerradas | Sistema Operativo y Tarjeta Gráfica se escriben a mano | Lista cerrada, al menos para Sistema Operativo |
| Administradores de TI | Nadie aprueba lo que pidió: hacen falta dos activos | Alerta cuando quede solo uno |
| Oracle XE | Sin Data Guard; réplica por archivos con hasta 1 hora de pérdida | Confirmar la fecha de soporte de 21c |
| Anunciar lo que viene | Compras registra el ingreso cuando la mercadería ya llegó | Estado «En camino» en el ingreso, si Compras conoce los pedidos antes |
| Tipos de activo iniciales | La base parte sin tipos de activo; cada uno lleva el prefijo del código de etiqueta | Definir la lista y sus prefijos, y agregarlos al catálogo mínimo |
| Tablas de órdenes de compra | Sin uso desde que se retiró el módulo, con 0 filas: `INV_ORDEN_COMPRA`, sus líneas y `INV_RECEPCION` | Borrarlas con una migración cuando se confirme que no vuelve |

## Calidad sin confirmar

- E2E completo en todos los navegadores y móvil. `ui/ingreso.spec.ts` debe escribir en la unidad recién creada, no en la primera de la lista.
- Reportar pérdida o robo hasta aprobarlo: se comprobó el formulario, no la aprobación desde la pantalla.
- Prueba de resistencia, revisión independiente de código y seguridad, y prueba con personas reales de cada rol.
- Lector de códigos en un teléfono físico.
- Vista del rol Compras en el navegador, con una cuenta de ese rol.
- Volumen: tomas probadas con 501 activos. Salvo Activos, Auditoría y Trazabilidad, los listados cargan todo de una vez.
- Un 503 de un adjunto tras una caída de MinIO, sin causa conocida.

## Menores

- Traducir al mostrarlos los motivos que escribe el servidor en español.
- El CSV del kardex muestra identificadores internos.
- Exportes de Tomas, Modelos y Departamentos. Reintento tras un error de carga fuera de Modelos.
