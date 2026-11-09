# Pruebas por rol

Qué puede trabajar cada rol, con qué cuentas probarlo y cómo validar cada flujo. Las reglas de cada rol están en la [arquitectura](ARQUITECTURA.md#roles).

## Cuentas de prueba

Dirección: `https://localhost` en el PC del sistema, o `https://<IP o nombre>` desde otro equipo de la red. Todas las cuentas de prueba usan la misma clave, que define el responsable.

| Cuenta | Rol | Sedes | Al entrar por primera vez |
|---|---|---|---|
| `sa` | SUPER_ADMIN | Todas | Cambia la clave temporal y configura el MFA |
| `sistema2` | SUPER_ADMIN | Todas | Configura el MFA |
| `seguridad1` | ADMIN_SEGURIDAD | Todas | Configura el MFA |
| `adminti1`, `adminti2` | ADMIN_TI | Todas | Configura el MFA |
| `tecnico1` | TECNICO | Sede Prueba Norte | Entra directo |
| `tecnico2` | TECNICO | Sede Prueba Sur | Entra directo |
| `compras1` | COMPRAS | Todas | Entra directo |
| `auditor1` | AUDITOR | Todas | Entra directo |
| `consulta1` | CONSULTA | Sede Prueba Norte | Entra directo |

- **MFA:** use una aplicación autenticadora (Microsoft o Google Authenticator); una sola aplicación guarda varias cuentas. Guarde los códigos de recuperación de cada una.
- **Datos que crean estas cuentas:** el país Guatemala, las sedes *Sede Prueba Norte* y *Sede Prueba Sur*, el departamento *Pruebas* y el cargo *Colaborador de prueba*.
- **Sin SMTP no sale ningún correo.** Los avisos se ven en *Mis tareas* y en la campana.
- **Pruebe en ventanas privadas**, una por cuenta, para tener varias sesiones a la vez.

## Matriz de roles

**Sí** = lo hace · **Ve** = solo consulta · **—** = no le aparece.

| Función | SUPER_ADMIN | ADMIN_SEGURIDAD | ADMIN_TI | TECNICO | COMPRAS | AUDITOR | CONSULTA |
|---|---|---|---|---|---|---|---|
| Cuentas: crear, editar, desactivar, restablecer clave | Sí | Ve | — | — | — | — | — |
| Cerrar las sesiones de una cuenta | Sí | Sí, salvo SUPER_ADMIN y ADMIN_SEGURIDAD | — | — | — | — | — |
| Configuración: empresa, logo, colores, MFA, correo | Sí | — | — | — | — | — | — |
| Directorio (Active Directory) | Sí | — | — | — | — | — | — |
| Auditoría del sistema | Ve | Ve | — | — | — | Ve | — |
| Mis tareas | — | — | Sí | Sí | Sí | Sí | Sí |
| Activos: lista, ficha, buscador, escáner | — | — | Sí | Sí | — | Ve | Ve |
| Nuevo activo sin factura | — | — | Sí | — | — | — | — |
| Importar activos de Excel (hasta el primer cierre) | — | — | Sí | — | — | — | — |
| Ingreso a bodega: registrar, editar o cancelar antes de confirmar | — | — | Sí | — | Sí | — | — |
| Ingreso a bodega: confirmar y clasificar | — | — | Sí | Sí | — | — | — |
| Ingreso a bodega: aprobar una diferencia | — | — | Sí, si la registró otro | — | — | — | — |
| Ingreso a bodega: consultar | — | — | Sí | Sí | Sí | Ve | Ve |
| Por completar: serie, especificaciones, etiqueta | — | — | Sí | Sí | — | — | — |
| Entregar, devolver, transferir, revertir; actas | — | — | Sí | Sí | — | Ve | Ve |
| Mantenimiento: abrir y cerrar | — | — | Sí | Sí | — | Ve | Ve |
| Consumibles: entrada y salida | — | — | Sí | Sí | — | Ve | Ve |
| Consumibles: crear, ajustar stock | — | — | Sí | — | — | — | — |
| Toma física: iniciar, leer, cerrar | — | — | Sí | Sí | — | Ve | Ve |
| Toma física: anular | — | — | Sí | — | — | — | — |
| Baja (incluye pérdida o robo): solicitar | — | — | Sí | — | — | — | — |
| Aprobaciones: aprobar o rechazar | — | — | Sí, si lo pidió otro | — | — | Ve | — |
| Cierre mensual: cerrar | — | — | Sí | — | — | — | — |
| Cierre mensual: consultar | — | — | Sí | — | Sí | Ve | — |
| Stock y asignaciones, con valores | — | — | Sí | — | Sí | Ve | — |
| Proveedores | — | — | Sí | — | Sí | Ve | — |
| Garantías | — | — | Sí | — | — | Ve | — |
| Software y licencias | — | — | Sí | — | — | Ve | — |
| Empleados y departamentos | — | — | Sí | — | — | Ve | — |
| Alta de empleado (persona y kit) | — | — | Sí | — | — | — | — |
| Catálogos, marcas, modelos, tipos, ubicaciones | — | — | Sí | — | — | — | — |
| Trazabilidad (kardex) | — | — | Sí | Ve | — | Ve | Ve |
| Verificar la integridad del kardex | — | — | Sí | — | — | Sí | — |
| Exportes de control (kardex, custodia, movimientos, tomas) | — | — | Sí | — | — | Sí | — |
| Panel de control | — | — | Sí | Ve | — | Ve | Ve |
| Ve costos y montos | — | — | Sí | — | Sí | Sí | — |
| Ve datos de las personas | — | — | Sí | Sí | — | Sí | — |
| Sedes | Todas | Todas | Las asignadas o todas | Las asignadas | Las asignadas o todas | Las asignadas o todas | Las asignadas |

## Recorrido completo, en orden

Cada paso prepara el siguiente. Después del recorrido, revise en *Auditoría* (`auditor1`) que todo quedó registrado.

| # | Cuenta | Qué hacer | Qué debe pasar |
|---|---|---|---|
| 1 | `adminti1` | *Centro de catálogos*: tipo «Laptop» con prefijo `LAP`, una marca y un modelo. *Ubicaciones*: un edificio, un nivel y un área en Sede Prueba Norte. *Empleados*: una persona de esa sede | Un nombre o prefijo repetido muestra un aviso claro |
| 2 | `compras1` | *Proveedores*: uno nuevo. *Ingresos a bodega → Nuevo ingreso*: factura, 3 laptops y 10 cables, con costo y la factura adjunta | El ingreso queda *Registrado*; `adminti1` y `tecnico1` lo ven en *Mis tareas* |
| 3 | `tecnico1` | Confirma el ingreso: cuenta **2** laptops y clasifica los cables como consumible | Queda *Pendiente de aprobación* y todavía no entra nada al inventario |
| 4 | `adminti1` | *Aprobaciones*: aprueba la diferencia | Las 2 laptops entran *por completar* y los cables suman stock |
| 5 | `compras1` | Descarga el acta de recibido | El PDF trae proveedor, factura, cantidades y espacio para firmas |
| 6 | `tecnico1` | *Por completar*: serie y especificaciones de cada laptop; imprime las etiquetas | Códigos `LAP-…`; una serie repetida se rechaza |
| 7 | `tecnico1` | Entrega una laptop a la persona del paso 1 y descarga el acta | La ficha dice *Asignado*; el kardex y el acta registran la entrega |
| 8 | `tecnico1` | Abre un mantenimiento a esa laptop y luego lo cierra | Pasa a *En Reparación* y vuelve a *Asignado* |
| 9 | `tecnico1` | *Toma de inventario* de Sede Prueba Norte: lee la etiqueta de una sola laptop y cierra la toma | La otra queda como faltante |
| 10 | `adminti1` | Solicita la baja por pérdida de la laptop faltante | La solicitud aparece en *Aprobaciones*, pero `adminti1` no puede aprobarla |
| 11 | `adminti2` | La aprueba | La laptop queda en *Baja* y el kardex lo registra |
| 12 | `auditor1` | *Trazabilidad → Verificar kardex* | La cadena está íntegra |
| 13 | `adminti1` | *Cierre mensual* del mes anterior | Sus cifras quedan congeladas; `compras1` lo ve, pero no lo puede cerrar |

> **Antes del paso 13:** un mes cerrado no se puede reabrir, y después del primer cierre *Importar activos* deja de estar disponible. Si piensa cargar el inventario real desde Excel en esta instalación, salte ese paso hasta después de la carga.

## Qué validar con cada cuenta

Regla general: lo que un rol no puede trabajar **no aparece**. Para comprobarlo, escriba a mano una dirección ajena (`/activos`, `/usuarios`, `/aprobaciones`): el sistema no la muestra.

- **`sa` y `sistema2`:**
  - en *Usuarios y accesos*, desactivar `consulta1` lo saca de inmediato, y reactivarlo le permite entrar de nuevo;
  - al restablecer la clave de `tecnico2`, él debe cambiarla al entrar;
  - el MFA no se puede apagar;
  - no hay menú de activos ni de empleados.
- **`seguridad1`:**
  - ve las cuentas y sus sesiones, pero no hay botones para editar;
  - al cerrar las sesiones de `tecnico1`, este queda fuera en su siguiente clic;
  - no puede cerrar las de `sa`.
- **`adminti1` y `adminti2`:** el recorrido completo. Además, la importación de Excel desaparece después del primer cierre, y una clave de licencia solo se ve al pedirla y queda en la auditoría.
- **`tecnico1` y `tecnico2`:**
  - ningún costo aparece en pantallas, fichas ni exportes;
  - `tecnico2` (Sede Sur) no ve las laptops de Sede Norte: ni en la lista, ni en el buscador, ni abriendo la dirección de su ficha;
  - el escáner funciona desde un teléfono.
- **`compras1`:**
  - solo ve *Mis tareas*, *Ingresos a bodega*, *Proveedores*, *Stock y asignaciones* y *Cierre mensual*;
  - registra un ingreso con pocos clics;
  - no puede cambiar ni cancelar un ingreso ya confirmado;
  - los exportes abren bien en Excel.
- **`auditor1`:**
  - ve todo lo del recorrido, con costos, y no encuentra ningún botón para crear, editar, aprobar o borrar;
  - en *Auditoría*, cada paso aparece con quién, cuándo y desde qué IP.
- **`consulta1`:** ve los activos de Sede Norte, sin costos y sin datos de las personas; no hay botones de acción.

En todas las cuentas:
- **Accesos:** varias claves erradas bloquean la cuenta por un tiempo, con un aviso claro.
- **Idiomas:** cambiar entre español, inglés e italiano no deja textos sin traducir.
- **Teléfono:** menús, tablas y formularios se usan bien a lo ancho del teléfono.
- **Errores:** todo error muestra un mensaje entendible.

## Cómo reportar un fallo

Anote **cuenta, pantalla, pasos, qué esperaba y qué pasó**, y el `request_id` si aparece en el mensaje. Con eso se encuentra en `docker logs inventario-api_php-1`.
