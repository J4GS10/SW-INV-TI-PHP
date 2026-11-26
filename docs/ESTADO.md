# Estado del trabajo

Corte del 2026-11-25. Lo que sigue abierto a largo plazo está en [pendientes](PENDIENTES.md); aquí va lo de la última tanda de trabajo y cómo retomarla.

## Dónde está el sistema

- Producción es el único entorno (proyecto compose `inventario`). Reinstalado de cero el 2026-11-25 con `sa`, las cuentas de prueba y los datos de muestra ([pruebas por rol](PRUEBAS_POR_ROL.md)).
- GitHub `main` tiene todo lo de esta tanda (subido el 2026-11-25).
- Docker se limpió otra vez el 2026-11-25, a pedido: «solo lo reciente, lo formal».
  - Se borraron las imágenes de reversión `:anterior`, 166 volúmenes anónimos de pruebas viejas, la red `inv_php_pruebas` y 14,8 GB de caché de construcción.
  - Quedó solo el proyecto `inventario` con sus cinco volúmenes de datos (Oracle, MinIO, adjuntos y los dos de Caddy), más las imágenes de trabajo: `inventario-php-dev`, `inventario-frontend-check`, `inventario-qa-e2e`, `inventario-samba-ad-pruebas`, mailpit, shellcheck y graphify.
  - El siguiente `desplegar.sh` construye sin caché, así que va con Wi-Fi sin cable. Ese despliegue vuelve a crear `:anterior` para poder revertir.
- Ficha del equipo › *Componentes*:
  - Arriba van el resumen y las acciones.
  - Los instalados se agrupan por tipo y cada característica aparece como etiqueta.
  - El retiro ofrece motivos de un clic, y el historial muestra los tres últimos eventos, con «Ver todo».
  - El alta arranca en el modelo, Enter en la serie registra, y «Registrar y seguir con otro igual» deja tipo, modelo y características para la siguiente pieza.

## Hecho en esta tanda

| Commit | Qué | Validado en producción |
|---|---|---|
| `1785863` | El serial de una licencia va enmascarado («•••• 9876») y se ve solo al revelarlo, con auditoría y kardex. Una sola descarga para toda la app (`saveBlob`), que Firefox ya no corta. Filtros de Activos accesibles | Sí |
| `bc7a41f` | En el tipo de activo, las especificaciones aparecen como componentes que admite | Sí |
| `894659f` | Componente nuevo registrado e instalado en un paso desde el equipo. Un alta copia los valores de fábrica del modelo, y el contexto del modelo trae `ultima_compra`. Los tipos de componente nacen con sus características por nombre. Autorrelleno en tipos, modelos y licencias | Sí |
| *(último)* | Ingreso a bodega: sección «Lo que registró Compras» y campos de clasificación en una grilla que se acomoda al ancho. El técnico ve qué archivos adjuntó Compras, pero no los abre | Sí (ingreso.spec y navegacion.spec, 24 en verde) |

### Cómo funciona ahora

- **Componentes:** en el equipo, *Componentes › Registrar componente nuevo*.
  - Se elige el tipo (solo los que admite el equipo) y el modelo; si el modelo no existe, se crea ahí mismo.
  - Las características vienen llenas con los valores de fábrica del modelo. Basta con escribir la serie y, si el rol ve costos, el costo.
  - Sede, área, fecha, garantía, proveedor, factura y moneda salen del equipo.
  - Lo que se escribe por primera vez queda como valor de fábrica del modelo.
  - Cada componente instalado se lee con su tipo, modelo y características.
- **Plantillas de componente por nombre:**
  - RAM: capacidad, tipo y velocidad.
  - Disco: capacidad, tipo e interfaz.
  - Batería: mAh, salud y ciclos.
  - Tarjeta gráfica: memoria de video y chipset.
  - Procesador: modelo, núcleos y frecuencia.
  - Fuente: potencia.
  - Red: velocidad y MAC.
- **Autorrelleno:**
  - El alta de un activo arranca con fecha de hoy.
  - Costo, moneda y garantía salen de la última unidad del mismo modelo; el costo solo para quien ve costos.
  - El prefijo de un tipo sale de su nombre y la marca de un modelo sale del inicio de su nombre.
  - Una licencia empieza hoy.
- **Ingreso a bodega:** TI ve el proveedor, el documento, la fecha, la sede, la moneda, las notas y quién lo registró, además de la lista de archivos.
  - Abrir la factura sigue siendo solo para Compras, Administración de TI y Auditoría, porque muestra precios. Lo decidió el usuario el 2026-11-25.

### Catálogo de activos rehecho (2026-11-25, en código, sin desplegar)

- **Tipos de activo:** una sola pantalla en tres pasos: 1. ¿Qué es? (nombre, prefijo, clase y categoría contable), 2. ¿Qué se anota de cada equipo? (características, con orden, obligatorias y valores permitidos, y se crean ahí mismo con su tipo de dato y unidad) y 3. ¿Qué componentes internos lleva?
  - Arriba, la lista «Listo para registrar activos» (`typeReadiness.ts`).
  - La tabla de tipos muestra características, componentes, categoría y estado.
  - «Especificaciones por tipo» dejó de ser una lista aparte: vive dentro del tipo, y los enlaces viejos redirigen.
  - «Tipos de especificación» ahora se llama «Biblioteca de características».
- **Componentes obligatorios** (migración `Version20261126000000`: `TCP_Obligatorio` y `TCP_Cantidad` en `INV_TIPO_COMPONENTE`):
  - El tipo dice qué componentes exige y cuántos.
  - Al registrar un equipo, la pantalla de éxito los pide ahí mismo, y «Terminar después» los deja pendientes.
  - Lo pendiente se ve en la ficha (aviso con un botón por componente) y en la bandeja (`COMPONENTES_POR_REGISTRAR`).
  - `PUT /cat/tipos-activo/{id}/componentes` acepta ids (como antes) u objetos `{tipo, obligatorio, cantidad}`, y `GET …?detalle=1` devuelve el detalle.
- **Métodos de activación:** los 14 campos de cada fila (7 interruptores sin texto propio, que decían todos «Pide detalle escrito», y 7 etiquetas) pasan a un diálogo «Qué pide al registrar». Muestra cada dato con su explicación, el nombre en el formulario y una vista previa. Sirve para cualquier catálogo con campos `…_Pide_X` / `…_Etiqueta_X`.
- **Pruebas:**
  - Backend: `ActivosTest::testComponentesObligatoriosQuedanPendientesHastaRegistrarlos`.
  - Navegador: segunda prueba de `ui/componentes.spec.ts` (tipo configurado en el diálogo, y su equipo avisa del componente que falta).

**Validación del 2026-11-25 23:42–00:25:**
- Desplegado con el respaldo `inventario_20261125_234221.dmp` y la migración `Version20261126000000` aplicada.
- Backend: 344 pruebas en verde (12 omitidas) y huella idéntica.
- Navegador: 149 en verde y 7 fallidas, todas corregidas:
  - **El aviso tapaba Guardar:** los avisos salían abajo a la derecha, encima de los botones de los diálogos. Se movieron arriba al centro (`App.tsx`). Era un defecto real de la interfaz.
  - **La búsqueda contaba como escritura:** `componentes.spec` contaba `POST /search`, que solo lee.
  - **Navegaciones cortadas tras el login:** en Firefox y WebKit, en `componentes.spec` y en `login.spec` (MFA en WebKit).
- 2026-11-26 13:54: frontend desplegado (respaldo `inventario_20261126_135449.dmp`). Repetición de `componentes.spec` y `login.spec` (respaldo `inventario_20261126_140328.dmp`, huella idéntica): 42 en verde, 4 fallidas. Causas y arreglos:
  - El tipo recién creado quedaba en la página 2 de «Tipos de activo» (17 tipos, 10 por página) y la pantalla no tenía buscador: se agregó «Buscar por nombre o prefijo…» (es/en/it).
  - En WebKit la lista de «Agregar un componente» quedaba fuera de la vista: el selector resalta la primera opción (`autoHighlight`), así que Enter la elige; la prueba usa Enter.
  - WebKit: tras el login del SUPER_ADMIN hay una segunda redirección a /usuarios; `irA` (reintento único) pasó a `pruebas/soporte.ts` y lo usan ambos specs.
  - tsc, eslint, vitest (56/56) y la carga de los specs en verde. Falta desplegar y repetir esos dos specs.
- 2026-11-26 15:11: desplegado (respaldo `inventario_20261126_151144.dmp`). Arreglos de la segunda repetición:
  - Prefijo libre (`prefijoLibre` en `utils/prefijo.ts`, con pruebas): el tipo sugiere un prefijo que nadie usa (BQA → BQA2…) y el componente creado al vuelo lo toma igual, reintentando hasta 3 veces si otro usuario lo ocupa en el mismo instante.
  - 32 códigos de error del backend sin texto en `api_errors` (ASSET_TYPE_PREFIX_ALREADY_EXISTS, *_ALREADY_EXISTS, GOODS_RECEIPT_*, INTERNAL_SERVER_ERROR…) traducidos en es/en/it.
  - Métodos de activación (captura del usuario): la descripción va bajo el nombre, el diálogo «Qué pide» explica el rótulo vacío, y una prueba nueva recorre el Centro de catálogos comprobando que ninguna pantalla muestra claves sin traducir. Usa su propia cuenta TOTP, `qa_catalogos_ti`.
  - Login.spec en verde en los 3 navegadores; `componentes.spec` 1 y 3 en verde, la 2 pendiente de repetir con los prefijos libres.
- 2026-11-26 15:25, incidente: la corrida de `componentes.spec` se cortó por falta de memoria, pero `e2e.sh` siguió vivo; al cerrar la ventana a mano hubo dos restauraciones a la vez y las filas quedaron duplicadas (INV_ACTIVO 168). La huella lo detectó y el sistema quedó detenido. 16:38: restaurado una sola vez `inventario_20261126_152551.dmp` (previo a la prueba), migrador al día, las 90 tablas con las mismas filas que el registro del respaldo y prueba de humo en verde. Lección: antes de restaurar a mano, comprobar con `ps -ef | grep e2e.sh` que no quede otra corrida.
- 2026-11-26 17:00, desplegado (pedido del usuario al configurar «Laptop»):
  - Al abrir un tipo existente se borraba su prefijo: el efecto que propone el prefijo de un alta corría con el estado del render anterior. Ahora mira la prop `typeToEdit` y al editar no lo toca.
  - Los pasos 2 y 3 pedían lo mismo (Memoria RAM como característica y como pieza). Ahora se llaman «Datos de la ficha del equipo» (lo del equipo completo: procesador, pantalla, sistema operativo) y «Piezas internas con número de serie» (lo que se cambia o pasa a otro equipo, con su propio código y datos). Al agregar una pieza que ya estaba como característica, sale de las características con un aviso; si un tipo ya las tiene repetidas, el paso 2 lo advierte con el botón «Quitar de aquí» (`specComponentOverlap.ts`, con pruebas). tsc, eslint y vitest 60/60 en verde.
- 2026-11-26 17:40, desplegado: visor de archivos sin descargar (`components/file-preview/`, sin dependencias nuevas).
  - PDF con el visor del propio navegador dentro de la página; imágenes con zoom, ajuste y giro; texto y CSV (como tabla, 200 filas). Word y Excel muestran «Vista previa no disponible» con el botón de descarga. Si el navegador no muestra PDF en la página (Chrome en Android), ofrece abrirlo en una pestaña o descargarlo. Flechas ← → pasan de un archivo a otro; siempre hay Descargar.
  - Dónde: adjuntos del activo (clic en el nombre, la miniatura o «Ver»), documentos del ingreso a bodega («Ver», solo con permiso de documentos de compra) y los PDF que genera el sistema (actas de entrega y descargo, acta de recibido, etiquetas, acta consolidada de la persona); el Word de las actas se sigue descargando.
  - CSP de nginx: `frame-src 'self' blob:` (el PDF va en un marco con la dirección local del archivo). Las imágenes SVG no se muestran.
  - tsc, eslint y vitest 69/69 en verde. Falta la prueba de navegador (entra en la próxima ventana).
- 2026-11-26 18:32, ventana aprobada (respaldo `inventario_20261126_183218.dmp`, huella idéntica al cerrar): `componentes.spec` y el nuevo `visor.spec` (cuenta propia `qa_visor_ti`). 11 en verde, 1 fallida: en WebKit el PDF del visor quedaba en blanco, porque Safari aplica al archivo local (blob:) la CSP de la página y `frame-ancestors 'none'` le prohíbe ir en un marco. Arreglo desplegado: `frame-ancestors 'self'` (ningún otro sitio puede incrustar el sistema; X-Frame-Options sigue en DENY). Falta repetir `visor.spec`.
- 2026-11-26 19:20, desplegado: la devolución pregunta qué software se libera (pedido del usuario: devolvió la LAP 13 y sus licencias quedaron ocupadas).
  - Causa: la baja y la salida de una persona liberaban las licencias del equipo; la devolución no hacía nada con ellas.
  - Devolución de un equipo: el diálogo muestra las licencias instaladas, todas marcadas para liberar; quien opera desmarca las que siguen con el equipo. `POST /trazabilidad/devolucion` acepta `liberar_instalaciones` (ids). Una instalación que no está activa en ese equipo hace fallar toda la devolución (409 `INSTALLATION_NOT_FOUND_OR_ALREADY_REMOVED`). Responde `licencias_liberadas`.
  - En lote: si los equipos tienen software, se pregunta una vez (liberar o dejar instaladas), sin respuesta por defecto. `POST /trazabilidad/devolucion-lote` acepta `liberar_software`.
  - Cada licencia liberada devuelve cupo y clave, queda en el kardex (LICENCIA_RETIRADA, «Devolución del equipo») y en la auditoría (UNINSTALL). Si no se pudo leer el software del equipo, no se deja devolver.
  - Lo ya devuelto antes de este cambio no se tocó: las licencias de la LAP 13 se liberan desde la pestaña de software del equipo.
  - PHPStan sin errores, 121 unitarias, tsc, eslint y vitest 73/73. Prueba de integración nueva `TrazabilidadTest::testDevolucionPreguntaQueSoftwareSeLibera`, pendiente de correr (necesita ventana).
- 2026-11-26 19:55, desplegado (el usuario probó y «no funciona»; y el QR «no lleva a nada»):
  - Devolución: sí funcionaba, pero LAP00013 se devolvió a las 19:04, antes del despliegue de las 19:20, y las dos pruebas siguientes (LAP00005 en lote, TEC00014) eran de equipos sin software, donde el diálogo no mostraba nada. Ahora siempre lo dice: «Software revisado: este equipo no tiene licencias instaladas». LAP00013 sigue con Microsoft 365 y Windows ocupados (instalaciones 1 y 2): se liberan desde su pestaña de software.
  - QR: la etiqueta guardaba solo el código (LAP00013), que la cámara del teléfono lee como texto. Ahora guarda la dirección de la ficha, `FRONTEND_BASE_URL/activos/<código>` (`Documentos/Etiquetas::enlace`). El escáner del sistema ya aceptaba direcciones (`codigoDeQr`), así que las etiquetas viejas siguen sirviendo ahí; para el teléfono hay que reimprimirlas. Sin sesión, el login vuelve a la ficha pedida (`utils/destinoLogin.ts`, con pruebas; solo rutas internas).
  - Dirección del sitio: `FRONTEND_BASE_URL` apuntaba a 192.168.7.115, pero el PC está hoy en 192.168.7.105. Se agregó la .105 a `DOMAIN` y `BACKEND_CORS_ORIGINS`, y `FRONTEND_BASE_URL` y `TLS_DEFAULT_SNI` pasaron a ella (procedimiento de MANUAL_OPERACION.md), con `desplegar.sh subir`. Si la red cambia, los QR nuevos llevan la IP de ese momento: hace falta un nombre fijo (DNS), ver PENDIENTES.md.
  - `qr.spec` actualizado: los QR del lote deben ser `URL_QA/activos/<código>`. tsc, eslint, vitest 75/75, PHPStan y 121 unitarias en verde.
- 2026-11-26 20:00–20:20, ventanas aprobadas por el usuario (huella idéntica en las tres; producción arriba):
  - `pruebas_produccion.sh --filter 'TrazabilidadTest|ArchivosTest'`: 18/18, incluida la devolución que pregunta qué software liberar.
  - `qr.spec` + `visor.spec` (5 proyectos: Chromium, Firefox, WebKit, Android, iPhone): 26 en verde; el visor ya pasa en WebKit. Fallaba la prueba del lote del escáner porque esperaba que «Etiquetas» descargara el PDF, que ahora se abre en el visor; se ajustó (abre el visor, Descargar y lee los QR) y, repetida, 5/5 en verde: los QR son la dirección de la ficha.
- 2026-11-26 20:32, desplegado (migración `Version20261127000000`): la dirección pública la configura el administrador del sistema.
  - Pedido del usuario: el nombre será `lombardi.inventario.group`, con IP fija, y lo debe poder configurar el SUPER_ADMIN. El DNS queda pendiente del área de redes.
  - `SYS_CONFIGURACION.SYS_URL_Publica` (vacía por defecto). *Configuración → Dirección del sistema* la edita (solo SUPER_ADMIN; `PUT /gov/config`, https con nombre o IP y puerto opcional, sin ruta; se guarda en minúsculas; null vuelve a la del servidor) y muestra cómo queda el QR. `GET /gov/config` devuelve también `url_publica_servidor`. Las etiquetas usan `ConfiguracionGlobal::urlPublica()`.
  - `deploy/.env`: `lombardi.inventario.group` agregado a `DOMAIN` y `BACKEND_CORS_ORIGINS`; el servidor ya responde con ese nombre (comprobado con `curl --resolve`). `FRONTEND_BASE_URL` sigue en la IP actual mientras no exista el DNS.
  - PHPStan, 121 unitarias, tsc, eslint y vitest 75/75 en verde. `GobiernoTest::testDireccionPublicaParaLosQr` escrita, pendiente de ventana.
  - Ojo: con el Wi-Fi como red *Pública*, el firewall no deja entrar al teléfono (ver PENDIENTES.md), tampoco por el QR.

## En curso: rol «Escáner» y código de operador (acordado el 2026-11-26)

Decisiones del usuario:
- Dispositivo: los dos usos. Una tableta o teléfono compartido de bodega con la cuenta «Escáner», y el teléfono propio de cada técnico con su cuenta. En ambos casos, antes de guardar un cambio se pide el código de operador.
- El rol «Escáner» (ESCANER) ve una sola pantalla, pensada para el teléfono:
  - Qué ve del equipo escaneado: datos, estado, componentes y sus detalles, software instalado, quién lo tiene asignado y el historial de todo eso.
  - Qué puede hacer: asignar o entregar, devolver o liberar (con la pregunta del software), transferir y reportar una falla (ticket de mantenimiento).
  - No ve costos, reportes, catálogos ni configuración, y respeta el alcance por sede.
- Código de operador: lo tienen solo ADMIN_TI y TECNICO.
  - Son 6 dígitos que cada uno define en su perfil. Se guardan con hash, nadie los ve y el administrador solo puede restablecerlos.
  - No se aceptan códigos obvios y se bloquea tras 5 intentos fallidos.
  - Solo vale dentro de una sesión abierta y si el rol del dueño permite la acción.
- Registro: el kardex y la auditoría guardan quién autorizó (el dueño del código) y la cuenta desde la que se hizo.

Fase 1, base de datos y backend: desplegada el 2026-11-26 a las 21:05 (migración `Version20261128000000`). Sin commit.
- Rol `ESCANER` en `Roles` y en `CK_USUARIO_ROL_VALIDO`. Se suma a NEGOCIO, CON_ALCANCE, LECTURA_PERSONAS, LECTURA_SOFTWARE y SIN_COSTOS; no está en OPERATIVO, así que no escribe nada por sí mismo. La VPD lo trata como cualquier rol con alcance por sede.
- Código de operador:
  - Se guarda en `INV_USUARIO.USU_Codigo_Operador_Hash` (Argon2id), junto con `USU_Codigo_Operador_En`, `USU_Codigo_Intentos` y `USU_Codigo_Bloqueado_Hasta`.
  - La regla está en `Dominio/CodigoOperador`: 6 dígitos, sin repetidos, secuencias ni códigos obvios. Solo pueden tenerlo ADMIN_TI y TECNICO.
  - El servicio es `Seguridad/CodigosOperador`. Un usuario inexistente o un código incorrecto dan la misma respuesta, y 5 fallos bloquean el código 15 minutos; cada fallo queda auditado como OPERATOR_CODE_FAILED.
- Rutas con `#[ConCodigoOperador]`: `POST /trazabilidad/movimientos`, `/devolucion`, `/transferencia`, `/movimientos/revertir`, `/entrega-lote`, `/devolucion-lote` y `POST /mantenimiento`.
  - Si llegan las cabeceras `X-Operator-User` y `X-Operator-Code`, o si la cuenta es ESCANER, `AutenticacionListener` verifica el código. Luego la petición corre como el dueño del código (`Autenticador::actuarComo`: sus roles, sus sedes y el contexto VPD) y `Seguridad/Delegacion` guarda la cuenta de origen.
  - ESCANER sin código recibe 403 `OPERATOR_CODE_REQUIRED`. Las demás cuentas operan igual que antes cuando no envían código.
- Registro: el kardex guarda `USU_Usuario` del dueño del código y `KDX_Detalle.cuenta`; la auditoría guarda el dueño y `desde_cuenta`.
- Rutas nuevas: `PUT /me/operator-code` (`{code, current_password}`, solo OPERATIVO), el estado del código en `GET /me` (`operator_code`) y `POST /org/usuarios/{id}/operator-code/reset` (solo SUPER_ADMIN).
- Frontend: ESCANER se puede elegir al crear una cuenta, con su nombre traducido, y hay textos para los seis errores `OPERATOR_CODE_*`.
- Pruebas: PHPStan sin errores, 133 unitarias (incluida `CodigoOperadorTest`), tsc, eslint y vitest 75/75. La prueba de integración `Integracion/CodigoOperadorTest` está escrita y espera ventana de producción.
- Siguiente: fase 2, el código en el perfil y el restablecimiento en Usuarios.

Ventana del 2026-11-26 a las 21:20 (huella idéntica):
- `CodigoOperadorTest`, `GobiernoTest`, `RoleResponsibilitiesTest` y `TrazabilidadTest` en verde, salvo un intento fallido del código que daba 500 (ORA-00932: `CASE` con un TIMESTAMP enlazado).
- Se separó en dos UPDATE y, al vencer el bloqueo, el contador vuelve a cero. Repetida, 15/15 en verde.
- Commit `2f4af53`, sin push.

Fases 2 y 3, desplegadas el 2026-11-26 a las 21:50. Sin commit al cerrar esta nota.
- Perfil: «Código de operador» en Seguridad de la cuenta (`features/auth/security/OperatorCodeSection.tsx`). Solo TI y técnicos lo ven. Muestra su estado (definido, sin definir o bloqueado hasta) y el formulario (código, repetirlo y la contraseña actual). La regla del lado del cliente está en `utils/operatorCode.ts`, con pruebas.
- Usuarios: la acción «Restablecer código de operador» (solo SUPER_ADMIN, y solo si la cuenta lo definió). La cuenta trae `codigo_operador_configurado`.
- Interceptor `features/operator-code/OperatorCodeProvider.tsx`:
  - Pide el código antes de enviar las 7 rutas con código (`operatorCodeRoutes.ts`, con pruebas) cuando la cuenta es ESCANER, o cuando una pantalla de escaneo está abierta (`useScannerMode`; también a TI y técnicos).
  - Si la petición ya trae `X-Operator-Code` (reintento o refresco del token), no pide de nuevo.
  - Cancelar devuelve `OPERATOR_CODE_CANCELLED`, sin reintento ni cierre de sesión.
  - En la cuenta compartida recuerda el último usuario, nunca el código.
- Escáner (ESCANER):
  - Entra directo a `/scanner`; el menú solo muestra el escáner y `/activos` lo devuelve ahí.
  - El QR (`/activos/<código>`) lo lleva a `/scanner?codigo=…` con el equipo abierto.
  - Ve la ficha completa (información, software, componentes e historial), sin costos.
  - Puede asignar, devolver, transferir y reportar una falla; la falla abre el diálogo de mantenimiento en la misma pantalla. Lo demás queda oculto.
- Pruebas: tsc, eslint, vitest 83/83 (incluido el interceptor), PHPStan y unitarias en verde. La prueba de navegador `ui/escaner.spec.ts` (cuentas `qa_escaner` y `qa_tecnico_codigo`) quedó escrita, pero el clasificador del modo automático bloqueó la ventana e2e: la corre el usuario con `!`.
- 2026-11-26 21:59, ventana corrida por el usuario (respaldo `inventario_20261126_215925.dmp`, huella idéntica): `escaner.spec` 5/5 en verde y `qr.spec` 26 de 31.
  - Las 5 fallas eran de la prueba del lote: esperaba los QR con la dirección de pruebas, pero en producción ya hay una dirección pública configurada (`https://inventario.lombardi.group`) y los QR la usan, como corresponde. La prueba ahora comprueba `https://…/activos/<código>` con cualquier dirección.
  - Atención: esa dirección no coincide con el nombre que dio el usuario (`lombardi.inventario.group`, el que atiende el servidor), y ninguno de los dos existe todavía en el DNS. Las etiquetas que se impriman ahora no abren en el teléfono. Mientras no exista el DNS, conviene dejar vacía la Dirección del sistema.

## Lo que queda pendiente

1. **Ingreso a bodega:** hecho, validado y subido. Queda una prueba de navegador que entre como técnico y compruebe que ve la lista de archivos sin poder abrirlos.
2. **Autorrelleno en el resto de formularios:** hecho en código el 2026-11-25, con comprobaciones estáticas en verde (tsc, eslint, vitest, PHPStan 8). Falta desplegarlo y validarlo. Las reglas puras están en `frontend/src/utils/autofill.ts`, con sus pruebas.
   - Personas: el correo sale como `nombre.apellido@dominio`, con el dominio más usado entre las personas registradas, sin tildes ni ñ, y si ya existe se agrega la inicial del segundo apellido o un número. Si se elige primero el cargo, el departamento es aquel donde más se repite, y con él llega el jefe.
   - Consumibles: la unidad, el mínimo (la mediana) y la bodega salen de los consumibles de la misma categoría.
   - Proveedores: no hay fuente pública gratuita del NIT, porque la consulta de la SAT pide credenciales. El NIT se normaliza y se avisa si ya lo tiene otro proveedor.
   - Mantenimiento: las fechas y el técnico ya los ponía el servidor. Ahora se sugieren el tipo «Correctivo» y la moneda del equipo, y al cerrar, el costo total es la suma de los detalles cargados en ese momento.
   - Ingreso: al elegir un proveedor, la moneda y la sede salen de su último ingreso (`GET /goods-receipts/form-context/suppliers/{id}`, con su prueba en `GoodsReceiptFlowTest`).
   - Bajas: pérdida o robo llevan a «No recuperado», y garantía o fin de arrendamiento a «Devolución al proveedor».
   - Tomas y préstamos no cambian: la sede única ya se prellena y el préstamo ya propone 7 días.
3. **Pruebas de navegador de lo nuevo:** escritas el 2026-11-25 y sin correr todavía.
   - `ui/componentes.spec.ts`: componente con modelo nuevo desde la ficha del equipo, con una sola escritura al servidor. Usa su propia cuenta `qa_componentes_ti`, sembrada en `sembrar.php`.
   - `ui/ingreso.spec.ts`: Compras adjunta una foto, el técnico ve el archivo, el proveedor y el aviso, pero no el botón de descarga, y Compras sí lo descarga.
   - Las dos cargan con `playwright test --list`.
4. **Toma física en Firefox:** el 409 de una lectura no volvió a salir en las dos últimas corridas, y `qr.spec` muestra el cuerpo de la respuesta si reaparece. Hay que observarlo.

## Qué conviene mejorar

| Prioridad | Mejora | Por qué |
|---|---|---|
| ~~Alta~~ Hecho en código | Registrar el componente en una sola operación del servidor (modelo nuevo + activo + valores de fábrica) | `POST /core/activos` acepta `nuevo_modelo` y `recordar_valores_fabrica` en la misma transacción. Si el alta falla, no queda el modelo. Reutiliza el modelo de igual nombre en la marca y no pisa los valores de fábrica que ya existen (prueba en `ActivosTest`). Falta desplegar |
| ~~Alta~~ Hecho en código | Que el tipo de componente creado desde el selector reciba una categoría contable | Hereda la categoría que tiene en ese momento el tipo que se edita. Si se crea antes de elegir la categoría, queda sin ella y hay que asignarla en el catálogo. Falta desplegar |
| Media | Mover los valores de fábrica del modelo de `/goods-receipts/catalog/models/{id}/spec-defaults` a `/cat/modelos/{id}/especificaciones` | Hoy viven bajo ingresos solo por historia; el catálogo y el alta los usan igual |
| Media | Mostrar en el alta qué se tomó de la última compra («Costo y garantía de la última unidad, 12/11/2026») | El usuario ve de dónde salió el dato y lo corrige si no aplica |
| Media | Revisión visual de las pantallas con capturas a 3 anchos, por rol | Lo sobrepuesto se detectó por aviso del usuario, no por una prueba |
| Baja | Ventanas de validación más cortas (hoy de 10 a 25 minutos) | Correr solo los specs que tocan lo cambiado y dejar la suite masiva para el final, como ya se acordó |

## Para retomar

Copiar en una conversación nueva:

```text
Proyecto Inventario TI (C:\sis-inventario-ti\SW-INV-TI-PHP). Lee CLAUDE.md, docs/ESTADO.md, docs/PENDIENTES.md y la memoria «fase12-concurrencia-optimista». Respeta las reglas de siempre: solo Docker, Wi-Fi sin cable para descargas o push (el push lo autoricé aunque haya cable), git como J4GS10 sin coautores, secretos sin imprimir, producción como único entorno, cada ventana de producción con mi confirmación y la suite masiva una sola vez al final.

Sigue con «Lo que queda pendiente» de docs/ESTADO.md, en orden:
1. Revisa con git log que GitHub tenga lo último; si queda algo local, dime antes de subirlo.
2. Autorrelleno en el resto de formularios: el usuario debe escribir lo mínimo. Analiza cada formulario, propone qué se deduce y de dónde, e impleméntalo.
3. Las dos mejoras de prioridad alta de «Qué conviene mejorar».
4. Pruebas de navegador del componente en un paso y del ingreso visto como técnico.
Al terminar cada punto, actualiza docs/ESTADO.md y la memoria, y dame un resumen antes de cada ventana y antes del push.
```
