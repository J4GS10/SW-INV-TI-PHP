# Pendientes

## Antes de cargar datos reales

Hecho el 2026-11-11: Docker limpiado por completo (contenedores, imágenes, volúmenes y caché de construcción), y el sistema reinstalado desde cero sin caché. Quedaron solo el catálogo mínimo, `sa` y las cuentas de prueba por rol (`app:test-accounts`), todas con la misma clave de prueba, que se pasó solo por variable de entorno. MFA obligatorio para SUPER_ADMIN, ADMIN_SEGURIDAD y ADMIN_TI, con `MFA_CAN_BE_DISABLED=false`. El respaldo de antes de la limpieza es `inventario_20261111_172959.dmp`.

| Ajuste | Hoy | Para el uso real |
|---|---|---|
| Cuentas | `sa` y las cuentas de prueba de [pruebas por rol](PRUEBAS_POR_ROL.md), con una clave sencilla compartida, más sus sedes, departamento y cargo de prueba | Desactivar las cuentas de prueba y crear las reales: al menos dos ADMIN_TI |
| Empresa | «Prueba Lombardi» | Nombre real en *Configuración* |
| Dirección | CA privada de Caddy (`TLS_EMISOR=internal`) para `localhost` y las IP de este PC, que cambia con el Wi-Fi (hoy 192.168.7.122) | Nombre interno fijo del servidor formal (DNS) y la raíz de la CA instalada en los equipos ([puesta en marcha](PUESTA_EN_MARCHA.md#6-certificado-con-la-ca-privada)) |
| Acceso desde otros equipos | El Wi-Fi de este PC es una red *Pública* y la regla «Inventario produccion HTTPS» abre el 443 solo en redes *Dominio* y *Privada*: el teléfono no entra | Cambiar la red a *Privada*, con aprobación. En el servidor formal no aplica |
| Correo | `SMTP_HOST` vacío: no sale ningún correo, tampoco la recuperación de clave | Configurar SMTP más adelante |
| Servidor | Corre en un PC. `BACKUP_DIR` apunta a la carpeta antigua `26.10.13-sw-ti-agente` y la réplica va a otro disco del mismo equipo | Servidor formal en preparación: respaldos en su propia carpeta y copia fuera del equipo |
| Cuenta raíz | `sa` usa la clave de prueba compartida | Cambiarla por una propia y guardarla fuera del sistema |

## Decisiones abiertas

| Prioridad | Tema | Situación | Recomendación |
|---|---|---|---|
| Alta | Tipos de activo iniciales | La base parte sin tipos. Hay una lista depurada de 25, con prefijo y clase; los insumos (tinta, cables, limpieza) van como consumibles. La depreciación ya tiene dónde vivir: cada tipo apunta a una categoría contable (ver «Valor contable») | Confirmar la lista y el prefijo de *Bocina*, y agregarlos al catálogo mínimo con su categoría |
| Alta | Tasas de depreciación | Vienen las tasas máximas de Guatemala (Decreto 10-2012, art. 28) en *Control › Categorías contables*, línea recta desde el mes siguiente a la compra y sin valor residual | Que contabilidad confirme tasas, inicio (mes de compra o siguiente), valor residual y cuentas contables antes de cargar costos reales |
| Alta | Mes cerrado por error | No se puede reabrir | Reapertura con aprobación de otra persona y registro en auditoría |
| Media | Acceso de administración | Oracle y la consola de MinIO no publican puertos; no hay una cuenta para consultar la base | Publicar 1521 y 9001 solo en `127.0.0.1` y entrar por túnel SSH (SQL Developer, DBeaver). Una cuenta `INVENTARIO_LECTURA` de solo lectura creada por el migrador; falta decidir si ve todas las sedes |
| Media | Listas cerradas | Sistema Operativo y Tarjeta Gráfica se escriben a mano | Lista cerrada, al menos para Sistema Operativo |
| Media | Oracle XE | Sin Data Guard; réplica por archivos con hasta 1 hora de pérdida | Confirmar la fecha de soporte de 21c |
| Media | Separar los datos | Todo en una VM ([puesta en marcha](PUESTA_EN_MARCHA.md#11-seguridad-e-infraestructura-en-proxmox)) | Etapa 2 con `inv-app` e `inv-datos` cuando el servidor formal esté listo; falta dividir el compose y que el worker lea el estado de los respaldos sin montar su carpeta |
| Media | Prueba de persistencia | `herramientas/qa/persistencia.sh` dependía de QA: para probar la recuperación borra los volúmenes de Oracle y MinIO y restaura desde respaldo, algo que en producción pone en riesgo los datos reales | Correrla en el servidor formal antes de la puesta en marcha, con su propia base |
| Baja | Anunciar lo que viene | Compras registra el ingreso cuando la mercadería ya llegó | Estado «En camino» en el ingreso, si Compras conoce los pedidos antes |
| Baja | Tablas de órdenes de compra | Sin uso desde que se retiró el módulo, con 0 filas: `INV_ORDEN_COMPRA`, sus líneas y `INV_RECEPCION` | Borrarlas con una migración cuando se confirme que no vuelve |

## Mejoras por prioridad

### Alta: antes del uso real

- Recorrido completo y validación de cada rol según [pruebas por rol](PRUEBAS_POR_ROL.md), con una persona real de cada rol.
- Reportar pérdida o robo hasta aprobarlo: se comprobó el formulario, no la aprobación desde la pantalla.
- Lector de códigos en un teléfono físico.
- Actualización en vivo: probada con pruebas automáticas; falta verla con dos personas a la vez (una cambia el color, otra lo ve sin recargar; cerrar la sesión de alguien y que salga solo).
- Monitor externo (Uptime Kuma) en el servidor formal: hoy nadie se entera si cae la VM o la red.
- Sin SMTP no hay recuperación de clave por correo: solo el SUPER_ADMIN puede restablecerla.

### Media: primeros meses

- Capas: las cuatro listas de excepciones de `backend/api/tests/Unit/architecture-baseline.php` quedaron vacías el 2026-11-14 (negocio sin HTTP, negocio sin SQL, entrega sin SQL, dominio sin framework), así que cualquier dependencia nueva entre capas hace fallar `ArchitectureTest`. `symfony/uid` (y `symfony/polyfill-uuid`) salieron de las dependencias el 2026-11-23: `Dominio/Ident` genera sus UUID con `random_bytes`. Desplegado el mismo día (respaldo `inventario_20261123_164153.dmp`, sin migración) y validado con la suite completa: 207 pruebas en verde. El 2026-11-24 salieron de los controladores las últimas reglas de negocio: los estados operativos del sistema (no se renombran ni se borran) viven en `Catalogo\CatalogoTecnico` y el motivo real de un bloqueo en `Organizacion\Identidades`. Lo que queda en `CatalogosController`, `CatalogoTecnicoController` y `OrganizacionController` es lectura y validación de la forma de la petición, que es de la capa de entrega.
- Suite de navegador por rol (Playwright: Chromium, Firefox, WebKit y móvil): desde el 2026-11-23 corre sobre producción en la misma ventana que la suite del backend (`herramientas/ventana_produccion.sh`): respaldo verificado, cuentas `qa_*` sembradas solo dentro de la ventana, API y worker de prueba con dobles de correo, S3, Redis y AD, un Caddy de prueba sin puertos publicados y restauración con huella idéntica. Corre con `CONFIRMAR=SI bash herramientas/qa/e2e.sh` (la primera vez descarga la imagen de Playwright: solo con Wi-Fi) y prueba la imagen desplegada, así que va después de `desplegar.sh`. El 2026-11-24, tras el despliegue (respaldo `inventario_20261124_180321.dmp`, sin migración): 146 pruebas en verde y 7 omitidas a propósito (la navegación de escritorio en móvil la cubre `movil.spec.ts`), con huella idéntica al restaurar. Se estabilizaron las que fallaban por la prueba y no por el sistema: una cuenta propia para `logout-all`, el origen CORS de la ventana, claves que cumplen la política, esperas en Firefox, y calentamiento más diez muestras en la medición de tiempos del inicio de sesión. El hash señuelo de un usuario inexistente quedó precalculado, para que la primera respuesta de cada worker no cueste el doble.
- PHPStan en nivel 8 desde el 2026-11-24 (antes 6), sin errores, sin lista de ignorados y sin línea base. Los valores que la base puede no devolver (`false`/`null`) se comprueban con un error claro; los importes pasan por `Dominio\Decimal` antes de bcmath, y las pruebas piden servicios con tipo (`Tests\Soporte\Servicios`). Desplegado el 2026-11-25 (respaldo `inventario_20261125_013009.dmp`, sin migración) y validado con la suite completa del backend: 316 pruebas en verde. El código 139 de la primera validación era una recursión infinita en `TareasTest` (un `servicio()` propio que tapaba al del trait y se llamaba a sí mismo), no un fallo de PHP.
- Archivos partidos por capas el 2026-11-25, sin cambiar la API: `GoodsReceiptService` (827 líneas) quedó en registro, confirmación y aprobaciones (264), con `ReceiptInput` (dominio: lectura de la entrada), `ReceiptPresenter` (lectura y forma de la respuesta), `LineClassifier` (conteo y clasificación), `InventoryEntry` (entrada a inventario), `UnitCompletion` (completar unidades y claves) y `ReceiptModels` (marcas, modelos y valores de fábrica). `IntegrationSection.tsx` (1025 líneas) quedó en 205, con las reglas en `integration/integrationForm.ts`, el estado en `useIntegrationForm` y una pieza por sección.
- Dos cambios en vivo que pisaban lo escrito, corregidos el 2026-11-25: el detalle de un ingreso recargaba y borraba la clasificación sin guardar del técnico (ahora espera mientras edita), y en la entrega de puesto el área sugerida reemplazaba el texto que la persona ya estaba escribiendo.
- Concurrencia optimista, desde el 2026-11-24 (migración `Version20261124000000`). Dos personas que editan el mismo registro ya no se pisan: la segunda recibe 412 `RECORD_CHANGED_BY_ANOTHER_USER` y no se guarda nada. Se aplica a 40 tablas editables, 28 rutas de edición y 14 formularios ([arquitectura](ARQUITECTURA.md#concurrencia-e-idempotencia)). La edición en lote y los cambios de un solo campo desde una lista (activar, cambiar el estado de una credencial) no envían versión: no reescriben datos de otros.
- Sin consultas N+1 en los caminos que crecen con los datos. El cierre mensual de consumibles pasó de 3 consultas por consumible a 3 en total; el listado de kits, de 2 por kit a 2 en total. La validación de sedes, especificaciones y proveedores en la edición de usuarios, en el tipo de activo y en la edición en lote usa una consulta por lista. Quedan, a propósito, recorridos fila por fila que escriben en cada fila (edición en lote, salida de una persona) y el bloqueo de varios activos uno a uno en orden fijo, que evita interbloqueos.
- Valor contable, desde el 2026-11-25 (migración `Version20261125000000`, módulo `App\Accounting`). Categorías contables por país (método, tasa anual, valor residual, desde qué mes, cuenta contable y base legal); se siembran las de Guatemala y cada tipo de activo apunta a una. Depreciación en línea recta por meses cerrados, en centavos exactos (bcmath), que termina justo en el valor residual. Se ve en tres lugares, a cualquier fecha: la pestaña *Valor contable* de un equipo (con cada componente instalado, el total del conjunto y la tabla año por año), la pestaña *Valor de la estación* en la ficha de la persona (todo lo que tiene a su cargo, con lo instalado en cada equipo) y *Control › Activos fijos* (cuadro de sumas por categoría, tipo, usuario, departamento o sede, y el registro completo en CSV). Los montos se suman por moneda, nunca entre monedas. Equipo y estación: TI y auditor; libros y categorías: además Compras/Contabilidad, sin el detalle por persona. Cada componente abre sus archivos (foto, factura) desde el equipo, y las fotos se ven en miniatura.
- Dos campos de formulario sin etiqueta asociada en la pantalla de Activos (aviso de accesibilidad del navegador).
- Pruebas de navegador intermitentes en Firefox dentro de `qr.spec.ts` (la descarga de etiquetas y, una vez, el cierre de la toma física): pasan en la corrida siguiente y en los demás navegadores.

- Volumen: tomas probadas con 501 activos. Desde el 2026-11-23, ingresos, movimientos y mantenimiento se paginan, filtran y ordenan en el servidor (`skip`, `limit`, `sort`, `sort_dir` y sus filtros; respuesta `{total, items}`), como Activos, Auditoría y Trazabilidad. Antes se cortaban sin aviso en 200, 50 y 50 filas. Los demás listados son catálogos chicos y cargan completos.
- Prueba de resistencia en el servidor formal, y revisión independiente de código y seguridad.
- Un 503 de un adjunto tras una caída de MinIO, sin causa conocida.
- 1 de 210 peticiones sin respuesta (conexión cortada) en la prueba de resistencia del 2026-11-09, sin causa conocida. No se repitió en 2520 peticiones simultáneas contra producción el 2026-11-10. Desde entonces, una lectura cortada se reintenta sola una vez.

### Baja

- Traducir al mostrarlos los motivos que escribe el servidor en español.
- El CSV del kardex muestra identificadores internos.
- Exportes de Tomas, Modelos y Departamentos. Reintento tras un error de carga fuera de Modelos.
