# Pendientes

## Antes de cargar datos reales

Hecho el 2026-11-09: base y adjuntos reinstalados desde cero, solo con el catálogo mínimo y la cuenta raíz `sa`. MFA obligatorio para SUPER_ADMIN, ADMIN_SEGURIDAD y ADMIN_TI, con `MFA_CAN_BE_DISABLED=false`. El respaldo con los datos de prueba es `inventario_20261109_170833.dmp`.

| Ajuste | Hoy | Para el uso real |
|---|---|---|
| Cuentas | `sa` y las cuentas de prueba de [pruebas por rol](PRUEBAS_POR_ROL.md), con una clave sencilla compartida, más sus sedes, departamento y cargo de prueba | Desactivar las cuentas de prueba y crear las reales: al menos dos ADMIN_TI |
| Empresa | «Prueba Lombardi» | Nombre real en *Configuración* |
| Dirección | CA privada de Caddy (`TLS_EMISOR=internal`) para `localhost` y las IP de este PC, que cambia con el Wi-Fi (hoy 192.168.7.122) | Nombre interno fijo del servidor formal (DNS) y la raíz de la CA instalada en los equipos ([puesta en marcha](PUESTA_EN_MARCHA.md#6-certificado-con-la-ca-privada)) |
| Acceso desde otros equipos | El Wi-Fi de este PC es una red *Pública* y la regla «Inventario produccion HTTPS» abre el 443 solo en redes *Dominio* y *Privada*: el teléfono no entra | Cambiar la red a *Privada*, con aprobación. En el servidor formal no aplica |
| Correo | `SMTP_HOST` vacío: no sale ningún correo, tampoco la recuperación de clave | Configurar SMTP más adelante |
| Servidor | Corre en un PC. `BACKUP_DIR` apunta a la carpeta antigua `26.10.13-sw-ti-agente` y la réplica va a otro disco del mismo equipo | Servidor formal en preparación: respaldos en su propia carpeta y copia fuera del equipo |
| Limpieza del equipo | Imágenes viejas de `inventario-php` (fase4, fase5, fase6, qa-nueva, resistencia, qa), `inventario-php-dev` e `inventario-frontend:qa`, y los contenedores del backend en Python (`inventario-backend-1`, que aún monta `inventario_adjuntos_data`, e `inventario-migrator-1`) | Borrarlos con aprobación; conservar `anterior` |

## Decisiones abiertas

| Prioridad | Tema | Situación | Recomendación |
|---|---|---|---|
| Alta | Tipos de activo iniciales | La base parte sin tipos. Hay una lista depurada de 25, con prefijo y clase; los insumos (tinta, cables, limpieza) van como consumibles | Confirmar la lista, el prefijo de *Bocina* y la depreciación, y agregarlos al catálogo mínimo |
| Alta | Cerrar el mes con trabajo pendiente | Se puede cerrar con ingresos o aprobaciones sin resolver | Avisar antes de cerrar |
| Alta | Mes cerrado por error | No se puede reabrir | Reapertura con aprobación de otra persona y registro en auditoría |
| Media | Acceso de administración | Oracle y la consola de MinIO no publican puertos; no hay una cuenta para consultar la base | Publicar 1521 y 9001 solo en `127.0.0.1` y entrar por túnel SSH (SQL Developer, DBeaver). Una cuenta `INVENTARIO_LECTURA` de solo lectura creada por el migrador; falta decidir si ve todas las sedes |
| Media | Administradores de TI | Nadie aprueba lo que pidió: hacen falta dos activos | Alerta cuando quede solo uno |
| Media | Listas cerradas | Sistema Operativo y Tarjeta Gráfica se escriben a mano | Lista cerrada, al menos para Sistema Operativo |
| Media | Oracle XE | Sin Data Guard; réplica por archivos con hasta 1 hora de pérdida | Confirmar la fecha de soporte de 21c |
| Media | Separar los datos | Todo en una VM ([puesta en marcha](PUESTA_EN_MARCHA.md#11-seguridad-e-infraestructura-en-proxmox)) | Etapa 2 con `inv-app` e `inv-datos` cuando el servidor formal esté listo; falta dividir el compose y que el worker lea el estado de los respaldos sin montar su carpeta |
| Alta | Pruebas automáticas y un solo entorno | Hay un solo entorno: producción (QA se retiró el 2026-11-10). La suite del backend (`pruebas.sh`, `resistencia.sh`, `migraciones.sh vacia`) usa una Oracle propia que se crea y se borra en cada corrida, porque sus pruebas borran y crean datos a propósito; correrla sobre producción los destruiría. La suite de navegador (`herramientas/qa/e2e`, `persistencia.sh`) dependía de QA y hoy no corre | Decidir: conservar esa base temporal solo para la suite del código, o retirarla. Adaptar las pruebas de navegador a producción con las cuentas de prueba, sin restablecerlas ni borrar datos |
| Baja | Anunciar lo que viene | Compras registra el ingreso cuando la mercadería ya llegó | Estado «En camino» en el ingreso, si Compras conoce los pedidos antes |
| Baja | Tablas de órdenes de compra | Sin uso desde que se retiró el módulo, con 0 filas: `INV_ORDEN_COMPRA`, sus líneas y `INV_RECEPCION` | Borrarlas con una migración cuando se confirme que no vuelve |

## Mejoras por prioridad

### Alta: antes del uso real

- Recorrido completo y validación de cada rol según [pruebas por rol](PRUEBAS_POR_ROL.md), con una persona real de cada rol.
- Reportar pérdida o robo hasta aprobarlo: se comprobó el formulario, no la aprobación desde la pantalla.
- Lector de códigos en un teléfono físico, y pruebas de navegador en todos los navegadores y en móvil, en producción. `ui/ingreso.spec.ts` debe escribir en la unidad recién creada, no en la primera de la lista.
- Actualización en vivo: probada con pruebas automáticas; falta verla con dos personas a la vez (una cambia el color, otra lo ve sin recargar; cerrar la sesión de alguien y que salga solo).
- Monitor externo (Uptime Kuma) en el servidor formal: hoy nadie se entera si cae la VM o la red.
- Sin SMTP no hay recuperación de clave por correo: solo el SUPER_ADMIN puede restablecerla.

### Media: primeros meses

- Volumen: tomas probadas con 501 activos. Salvo Activos, Auditoría y Trazabilidad, los listados cargan todo de una vez; paginar en el servidor los que crecen (ingresos, movimientos, mantenimiento).
- Prueba de resistencia en el servidor formal, y revisión independiente de código y seguridad.
- Un 503 de un adjunto tras una caída de MinIO, sin causa conocida.
- 1 de 210 peticiones sin respuesta (conexión cortada) en la prueba de resistencia del 2026-11-09, sin causa conocida. No se repitió en 2520 peticiones simultáneas contra producción el 2026-11-10. Desde entonces, una lectura cortada se reintenta sola una vez.

### Baja

- Traducir al mostrarlos los motivos que escribe el servidor en español.
- El CSV del kardex muestra identificadores internos.
- Exportes de Tomas, Modelos y Departamentos. Reintento tras un error de carga fuera de Modelos.
