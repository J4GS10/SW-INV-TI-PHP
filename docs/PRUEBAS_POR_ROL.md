# Pruebas por rol

Cómo entrar, qué hace cada rol y qué validar antes del uso real. Los roles están descritos en la [arquitectura](ARQUITECTURA.md#roles).

## Dónde probar

| Entorno | Dirección | Cuentas | Para qué |
|---|---|---|---|
| Pruebas (QA) | `https://localhost:18443`, solo en el equipo que lo corre | Una por rol (abajo), todas con la misma clave y sin MFA | Probar todo con datos inventados |
| Producción | `https://<DOMAIN>`; hoy `https://localhost` en este PC | Solo `sa`. Su clave temporal es `SUPER_ADMIN_PASSWORD` en `backend/deploy/.env` | Configuración real; **nunca** datos de prueba, porque el historial no se borra |

**Levantar QA** (desde `backend/`):

1. Cree `api/var/qa/usuarios_manuales.json`, que no se versiona, con la clave que usted elija:

   ```json
   {
     "password": "<clave de prueba>",
     "usuarios": {
       "sistema_qa":   ["SUPER_ADMIN", true, []],
       "seguridad_qa": ["ADMIN_SEGURIDAD", true, []],
       "ti1_qa":       ["ADMIN_TI", true, []],
       "ti2_qa":       ["ADMIN_TI", true, []],
       "tecnico_qa":   ["TECNICO", false, ["A"]],
       "tecnico2_qa":  ["TECNICO", false, ["B"]],
       "compras_qa":   ["COMPRAS", true, []],
       "auditor_qa":   ["AUDITOR", true, []],
       "consulta_qa":  ["CONSULTA", false, ["A"]]
     }
   }
   ```

   Cada cuenta lleva el rol, si ve todas las sedes y sus sedes. `A` es «QA Sede Norte» y `B` es «QA Sede Sur».
2. `bash herramientas/qa/entorno.sh subir`. La primera vez construye las imágenes y copia el último respaldo de producción.
3. Al terminar: `bash herramientas/qa/entorno.sh bajar` conserva los datos, y `borrar` los elimina. Nunca toca producción.

## Recorrido completo

Hágalo una vez en orden: cada paso prepara el siguiente y prueba que los roles se pasan el trabajo.

| # | Cuenta | Qué hace | Qué validar |
|---|---|---|---|
| 1 | `ti1_qa` | En *Centro de catálogos*: tipos de activo con prefijo, una marca y un modelo. En *Ubicaciones*: edificio, nivel y área de QA Sede Norte. Además, un departamento y un empleado | Sin escribir dos veces lo mismo; un nombre repetido da un aviso claro |
| 2 | `compras_qa` | Un proveedor y un ingreso a bodega: factura, dos líneas (3 laptops y 10 cables), costos y la factura adjunta | TI recibe la tarea en *Mis tareas* |
| 3 | `tecnico_qa` | Confirma el ingreso contando **2** laptops en vez de 3, y clasifica los cables como consumible | Queda *Pendiente de aprobación*: nada entra todavía |
| 4 | `ti1_qa` | Aprueba la diferencia | La aprueba porque la diferencia la registró otra persona |
| 5 | `compras_qa` | Descarga el acta de recibido | El PDF trae proveedor, factura, cantidades y firmas |
| 6 | `tecnico_qa` | En *Por completar*: serie y especificaciones de cada laptop, e imprime la etiqueta | El código empieza con el prefijo del tipo; no se puede repetir una serie |
| 7 | `tecnico_qa` | Entrega una laptop al empleado; descarga el acta | La ficha muestra *Asignado*, y el kardex y el acta registran la entrega |
| 8 | `tecnico_qa` | Abre un mantenimiento a esa laptop y lo cierra | Pasa a *En Reparación* y vuelve a *Asignado* |
| 9 | `ti1_qa` | Inicia una toma física de QA Sede Norte; `tecnico_qa` lee las etiquetas con el escáner, sin una laptop | Lo que no se leyó queda como faltante al cerrar |
| 10 | `ti1_qa` | Solicita la baja de la otra laptop; `ti2_qa` la aprueba | `ti1_qa` no puede aprobar su propia solicitud |
| 11 | `auditor_qa` | *Trazabilidad → Verificar kardex* | La cadena queda íntegra y todo lo anterior aparece |
| 12 | `ti1_qa` | *Cierre mensual* de un mes terminado | Después no cambian sus cifras; `compras_qa` lo ve, pero no lo cierra |

## Qué validar en cada rol

En todos: lo que el rol no puede trabajar **no aparece**. Si escribe a mano la dirección de una pantalla ajena (por ejemplo `/activos` o `/usuarios`), el sistema no se la muestra.

### SUPER_ADMIN (`sistema_qa`, en producción `sa`): administra el sistema

- **Menú:** *Usuarios y accesos*, *Auditoría*, *Directorio y notificaciones* y *Configuración*.
- **Hace:** crear, editar y desactivar cuentas; asignar rol, sedes o alcance global; restablecer claves; nombre, logo, idioma y correo de la empresa; Active Directory.
- **No debe:** ver activos, costos, empleados ni ingresos. Tampoco apagar el MFA en producción.
- **Validar:**
  - una cuenta nueva recibe una clave temporal y debe cambiarla al entrar;
  - un ADMIN_TI nuevo debe configurar su MFA antes de usar el sistema;
  - desactivar una cuenta la saca de inmediato.

### ADMIN_SEGURIDAD (`seguridad_qa`): vigila los accesos

- **Menú:** *Usuarios y accesos* (consulta) y *Auditoría*.
- **Hace:** revisar cuentas, sesiones y la auditoría; cerrar las sesiones de una cuenta ante un incidente.
- **No debe:** crear ni editar cuentas, ni ver datos de negocio. Tampoco cerrar las sesiones de SUPER_ADMIN ni de otro ADMIN_SEGURIDAD.
- **Validar:** no hay botones para editar; al cerrar las sesiones de `tecnico_qa`, este queda fuera en su siguiente acción.

### ADMIN_TI (`ti1_qa`, `ti2_qa`): administra el inventario

- **Menú:** todo el inventario: activos, movimientos, mantenimiento, consumibles, tomas, aprobaciones, cierre mensual, garantías, software, empleados, departamentos, catálogos, ubicaciones e importación.
- **Hace:** catálogos, *Nuevo activo* (solo equipos sin factura), *Importar activos* (hasta el primer cierre), confirmar ingresos, aprobar lo que pidió otra persona, bajas, tomas, cerrar el mes, licencias y alta de empleado.
- **No debe:** aprobar lo que pidió él mismo, ni administrar cuentas.
- **Validar:**
  - en *Aprobaciones*, lo propio no se puede aprobar;
  - la importación desaparece después del primer cierre;
  - las claves de licencia se ven solo al pedirlas.

### TECNICO (`tecnico_qa`, `tecnico2_qa`): opera el inventario

- **Menú:** *Mis tareas*, *Por completar*, *Activos*, *Escáner QR*, *Movimientos*, *Mantenimiento*, *Consumibles*, *Panel de control*, *Trazabilidad*, *Toma de inventario* e *Ingresos a bodega*.
- **Hace:** confirmar ingresos, completar series y etiquetas, entregar, devolver y transferir, mantenimiento y lecturas de toma.
- **No debe:** ver costos o montos en ninguna pantalla, ficha ni exporte; ver *Aprobaciones*, catálogos ni *Nuevo activo*; ver activos de otras sedes.
- **Validar:**
  - `tecnico2_qa` (Sede Sur) no ve las laptops de Sede Norte, ni en la lista, ni en el buscador, ni abriendo su dirección;
  - el escáner funciona desde un teléfono.

### COMPRAS (`compras_qa`): compras y contabilidad

- **Menú:** *Mis tareas*, *Ingresos a bodega*, *Proveedores*, *Stock y asignaciones* y *Cierre mensual*.
- **Hace:** registrar ingresos con su factura, mantener proveedores, cancelar un ingreso que TI aún no confirmó, descargar el acta, consultar valores y exportarlos.
- **No debe:** operar el inventario (activos, movimientos, tomas) ni cerrar el mes.
- **Validar:**
  - un ingreso se registra con pocos clics y sin escribir dos veces lo mismo;
  - no se puede cancelar ni cambiar un ingreso ya confirmado;
  - los exportes abren bien en Excel.

### AUDITOR (`auditor_qa`): revisa sin modificar

- **Menú:** activos, panel, trazabilidad, tomas, aprobaciones, cierre, auditoría, ingresos, stock, proveedores, garantías, software, empleados y departamentos, con costos.
- **Hace:** consultar, verificar el kardex y exportar.
- **No debe:** encontrar ningún botón para crear, editar, aprobar o borrar.
- **Validar:** todo lo del recorrido completo aparece en *Auditoría* con quién, cuándo y desde qué IP.

### CONSULTA (`consulta_qa`): solo mira

- **Menú:** activos, escáner, movimientos, mantenimiento, consumibles, panel, trazabilidad, tomas e ingresos, en lectura.
- **No debe:** ver costos, datos de personas ni otras sedes, ni modificar nada.
- **Validar:** en la ficha de un activo asignado no aparecen los datos del custodio; no hay botones de acción.

## Comunes a todos los roles

- **Sesión:** clave errada varias veces, bloqueo temporal y aviso claro. Cambio de clave. Cerrar sesión. Tras 2 horas sin actividad, el sistema pide entrar de nuevo.
- **Idiomas:** cambiar entre español, inglés e italiano, sin textos sin traducir.
- **Teléfono:** menú, tablas y formularios usables a lo ancho del teléfono.
- **Errores:** todo error muestra un mensaje entendible. Si no, anote el código o `request_id` que aparece.

## Cómo reportar un fallo

Anote **cuenta, pantalla, pasos, qué esperaba y qué pasó**, y el `request_id` si aparece. Con eso se encuentra en `docker logs inventario-api_php-1`.
