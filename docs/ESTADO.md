# Estado del trabajo

Corte del 2026-11-25. Lo que sigue abierto a largo plazo está en [pendientes](PENDIENTES.md); aquí va lo de la última tanda de trabajo y cómo retomarla.

## Dónde está el sistema

- Producción es el único entorno (proyecto compose `inventario`). Reinstalado de cero el 2026-11-25 con `sa`, las cuentas de prueba y los datos de muestra ([pruebas por rol](PRUEBAS_POR_ROL.md)).
- GitHub `main` tiene todo lo de esta tanda (subido el 2026-11-25).
- Docker quedó limpio el 2026-11-25: sin contenedores detenidos ni imágenes de reversión (`:anterior`). Se conservaron los volúmenes de datos y las imágenes de Oracle, MinIO y pruebas.

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

## Lo que queda pendiente

1. **Ingreso a bodega:** hecho, validado y subido. Queda una prueba de navegador que entre como técnico y compruebe que ve la lista de archivos sin poder abrirlos.
2. **Autorrelleno en el resto de formularios.** Faltan:
   - Personas: correo a partir del nombre y el dominio de la empresa; departamento y jefe según el cargo.
   - Consumibles: unidad y mínimo según la categoría; sede de bodega.
   - Proveedores: datos a partir del NIT, si hay fuente.
   - Mantenimiento: fechas y técnico.
   - Formulario de ingreso: moneda y sede según el último ingreso del proveedor.
   - Tomas, préstamos y bajas.
3. **Pruebas de navegador de lo nuevo:** el componente en un paso desde la pantalla y el detalle del ingreso visto como técnico. Hoy el backend tiene su prueba (`ActivosTest`), pero el navegador no.
4. **Toma física en Firefox:** el 409 de una lectura no volvió a salir en las dos últimas corridas, y `qr.spec` muestra el cuerpo de la respuesta si reaparece. Hay que observarlo.

## Qué conviene mejorar

| Prioridad | Mejora | Por qué |
|---|---|---|
| Alta | Registrar el componente en una sola operación del servidor (modelo nuevo + activo + valores de fábrica) | Hoy el formulario hace hasta tres llamadas. Si falla la segunda, queda un modelo creado sin uso |
| Alta | Que el tipo de componente creado desde el selector reciba una categoría contable (la del equipo o una «Componentes») | Sin categoría no deprecia, y el valor de la estación queda incompleto |
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
