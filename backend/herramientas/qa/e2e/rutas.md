# Inventario de endpoints

Fuente: rutas del backend anterior (Python/FastAPI) al 2026-10-27. Se marcó [x] cuando la ruta
quedó migrada **y** cubierta por pruebas con el mismo contrato (ruta, códigos, cuerpo).
Estado final (fase 6, 2026-10-29): **325 / 325** migradas; todas las atiende la API PHP en producción
(verificado ruta por ruta con `bash herramientas/desplegar.sh rutas`; esa herramienta usa esta lista).

## Fuera de /api/v1

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/` |
| [x] | GET | `/health` |
| [x] | GET | `/health/full` |
| [x] | GET | `/metrics` |

## `login` (raíz) — 14 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/login/sso/providers` |
| [x] | GET | `/api/v1/login/sso/{provider}/authorize` |
| [x] | GET | `/api/v1/login/sso/{provider}/callback` |
| [x] | POST | `/api/v1/login/access-token` |
| [x] | POST | `/api/v1/login/2fa/verify` |
| [x] | POST | `/api/v1/login/refresh` |
| [x] | POST | `/api/v1/login/logout` |
| [x] | POST | `/api/v1/login/logout-all` |
| [x] | POST | `/api/v1/login/password-reset/request` |
| [x] | POST | `/api/v1/login/password-reset/confirm` |
| [x] | GET | `/api/v1/me` |
| [x] | GET | `/api/v1/me/sessions` |
| [x] | DELETE | `/api/v1/me/sessions/{session_id}` |
| [x] | POST | `/api/v1/me/password` |

## `twofactor` (raíz) — 7 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/me/2fa` |
| [x] | POST | `/api/v1/me/2fa/totp/setup` |
| [x] | POST | `/api/v1/me/2fa/totp/activate` |
| [x] | POST | `/api/v1/me/2fa/email/setup` |
| [x] | POST | `/api/v1/me/2fa/email/activate` |
| [x] | POST | `/api/v1/me/2fa/disable` |
| [x] | POST | `/api/v1/me/2fa/recovery-codes/regenerate` |

## `preferencias` (raíz) — 2 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/me/preferencias` |
| [x] | PATCH | `/api/v1/me/preferencias` |

## `governance` (/gov) — 8 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/gov/config` |
| [x] | PUT | `/api/v1/gov/config` |
| [x] | POST | `/api/v1/gov/security/purge` |
| [x] | GET | `/api/v1/gov/security/mfa` |
| [x] | POST | `/api/v1/gov/security/mfa` |
| [x] | GET | `/api/v1/gov/email-outbox` |
| [x] | POST | `/api/v1/gov/email-outbox/reintentar` |
| [x] | GET | `/api/v1/gov/auditoria` |
| [x] | GET | `/api/v1/gov/auditoria/resumen` |
| [x] | GET | `/api/v1/gov/auditoria.csv` |

## `organization` (/org) — 34 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/org/sedes` |
| [x] | GET | `/api/v1/org/departamentos/resumen` |
| [x] | GET | `/api/v1/org/departamentos/{id}/detalle` |
| [x] | GET | `/api/v1/org/departamentos` |
| [x] | GET | `/api/v1/org/departamentos/{id}` |
| [x] | POST | `/api/v1/org/departamentos` |
| [x] | PATCH | `/api/v1/org/departamentos/{id}` |
| [x] | DELETE | `/api/v1/org/departamentos/{id}` |
| [x] | GET | `/api/v1/org/cargos` |
| [x] | GET | `/api/v1/org/cargos/{id}` |
| [x] | POST | `/api/v1/org/cargos` |
| [x] | PATCH | `/api/v1/org/cargos/{id}` |
| [x] | DELETE | `/api/v1/org/cargos/{id}` |
| [x] | GET | `/api/v1/org/personas` |
| [x] | GET | `/api/v1/org/personas/disponibles` |
| [x] | GET | `/api/v1/org/personas/{id}` |
| [x] | POST | `/api/v1/org/personas` |
| [x] | PATCH | `/api/v1/org/personas/{id}` |
| [x] | DELETE | `/api/v1/org/personas/{id}` |
| [x] | GET | `/api/v1/org/usuarios` |
| [x] | GET | `/api/v1/org/usuarios/seguridad/resumen` |
| [x] | GET | `/api/v1/org/usuarios/{id}` |
| [x] | POST | `/api/v1/org/usuarios` |
| [x] | PATCH | `/api/v1/org/usuarios/{usuario_id}` |
| [x] | POST | `/api/v1/org/usuarios/{usuario_id}/password/reset` |
| [x] | POST | `/api/v1/org/usuarios/{usuario_id}/2fa/reset` |
| [x] | POST | `/api/v1/org/usuarios/{usuario_id}/unlock` |
| [x] | POST | `/api/v1/org/usuarios/{usuario_id}/sessions/revoke` |
| [x] | GET | `/api/v1/org/usuarios/seguridad/sesiones` |
| [x] | GET | `/api/v1/org/usuarios/{usuario_id}/sessions` |
| [x] | DELETE | `/api/v1/org/usuarios/{usuario_id}/sessions/{session_id}` |
| [x] | DELETE | `/api/v1/org/usuarios/{usuario_id}` |
| [x] | GET | `/api/v1/org/personas/{id}/contexto` |
| [x] | GET | `/api/v1/org/departamentos/{id}/contexto` |

## `location` (/geo) — 36 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | POST | `/api/v1/geo/paises` |
| [x] | GET | `/api/v1/geo/paises` |
| [x] | GET | `/api/v1/geo/paises/{id}` |
| [x] | PATCH | `/api/v1/geo/paises/{id}` |
| [x] | DELETE | `/api/v1/geo/paises/{id}` |
| [x] | POST | `/api/v1/geo/estados` |
| [x] | GET | `/api/v1/geo/estados` |
| [x] | GET | `/api/v1/geo/estados/{id}` |
| [x] | PATCH | `/api/v1/geo/estados/{id}` |
| [x] | DELETE | `/api/v1/geo/estados/{id}` |
| [x] | POST | `/api/v1/geo/municipios` |
| [x] | GET | `/api/v1/geo/municipios` |
| [x] | GET | `/api/v1/geo/municipios/{id}` |
| [x] | PATCH | `/api/v1/geo/municipios/{id}` |
| [x] | DELETE | `/api/v1/geo/municipios/{id}` |
| [x] | POST | `/api/v1/geo/sedes` |
| [x] | GET | `/api/v1/geo/sedes` |
| [x] | GET | `/api/v1/geo/sedes/{id}` |
| [x] | PATCH | `/api/v1/geo/sedes/{id}` |
| [x] | DELETE | `/api/v1/geo/sedes/{id}` |
| [x] | POST | `/api/v1/geo/edificios` |
| [x] | GET | `/api/v1/geo/edificios` |
| [x] | GET | `/api/v1/geo/edificios/{id}` |
| [x] | PATCH | `/api/v1/geo/edificios/{id}` |
| [x] | DELETE | `/api/v1/geo/edificios/{id}` |
| [x] | POST | `/api/v1/geo/niveles` |
| [x] | GET | `/api/v1/geo/niveles` |
| [x] | GET | `/api/v1/geo/niveles/{id}` |
| [x] | PATCH | `/api/v1/geo/niveles/{id}` |
| [x] | DELETE | `/api/v1/geo/niveles/{id}` |
| [x] | GET | `/api/v1/geo/areas/all` |
| [x] | POST | `/api/v1/geo/areas` |
| [x] | GET | `/api/v1/geo/areas` |
| [x] | GET | `/api/v1/geo/areas/{id}` |
| [x] | PATCH | `/api/v1/geo/areas/{id}` |
| [x] | DELETE | `/api/v1/geo/areas/{id}` |

## `catalogs` (/cat) — 35 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | POST | `/api/v1/cat/tipos-activo` |
| [x] | GET | `/api/v1/cat/tipos-activo` |
| [x] | GET | `/api/v1/cat/clases-activo` |
| [x] | GET | `/api/v1/cat/tipos-activo/{id}/componentes` |
| [x] | PUT | `/api/v1/cat/tipos-activo/{id}/componentes` |
| [x] | GET | `/api/v1/cat/tipos-activo/{id}` |
| [x] | PATCH | `/api/v1/cat/tipos-activo/{id}` |
| [x] | DELETE | `/api/v1/cat/tipos-activo/{id}` |
| [x] | POST | `/api/v1/cat/marcas` |
| [x] | GET | `/api/v1/cat/marcas` |
| [x] | GET | `/api/v1/cat/marcas/{id}` |
| [x] | PATCH | `/api/v1/cat/marcas/{id}` |
| [x] | DELETE | `/api/v1/cat/marcas/{id}` |
| [x] | POST | `/api/v1/cat/tipos-conexion` |
| [x] | GET | `/api/v1/cat/tipos-conexion` |
| [x] | GET | `/api/v1/cat/tipos-conexion/{id}` |
| [x] | PATCH | `/api/v1/cat/tipos-conexion/{id}` |
| [x] | DELETE | `/api/v1/cat/tipos-conexion/{id}` |
| [x] | POST | `/api/v1/cat/modelos` |
| [x] | GET | `/api/v1/cat/modelos` |
| [x] | GET | `/api/v1/cat/modelos-flat` |
| [x] | GET | `/api/v1/cat/modelos/{id}` |
| [x] | PATCH | `/api/v1/cat/modelos/{id}` |
| [x] | DELETE | `/api/v1/cat/modelos/{id}` |
| [x] | POST | `/api/v1/cat/estados-operativos` |
| [x] | GET | `/api/v1/cat/estados-operativos` |
| [x] | GET | `/api/v1/cat/estados-operativos/{id}` |
| [x] | PATCH | `/api/v1/cat/estados-operativos/{id}` |
| [x] | DELETE | `/api/v1/cat/estados-operativos/{id}` |
| [x] | POST | `/api/v1/cat/tipos-especificacion` |
| [x] | GET | `/api/v1/cat/tipos-especificacion` |
| [x] | GET | `/api/v1/cat/tipos-especificacion/{id}` |
| [x] | PATCH | `/api/v1/cat/tipos-especificacion/{id}` |
| [x] | DELETE | `/api/v1/cat/tipos-especificacion/{id}` |
| [x] | GET | `/api/v1/cat/modelos/{id}/contexto` |

## `catalogos` (/catalogos) — 14 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/catalogos` |
| [x] | GET | `/api/v1/catalogos/especificaciones-tipo` |
| [x] | GET | `/api/v1/catalogos/especificaciones-tipo/{tipo_id}` |
| [x] | PUT | `/api/v1/catalogos/especificaciones-tipo/{tipo_id}` |
| [x] | GET | `/api/v1/catalogos/kits` |
| [x] | POST | `/api/v1/catalogos/kits` |
| [x] | PUT | `/api/v1/catalogos/kits/{kit_id}` |
| [x] | DELETE | `/api/v1/catalogos/kits/{kit_id}` |
| [x] | GET | `/api/v1/catalogos/{clave}` |
| [x] | POST | `/api/v1/catalogos/{clave}` |
| [x] | PATCH | `/api/v1/catalogos/{clave}/{item_id}` |
| [x] | POST | `/api/v1/catalogos/{clave}/reordenar` |
| [x] | GET | `/api/v1/catalogos/{clave}/{origen}/fusionar/{destino}` |
| [x] | POST | `/api/v1/catalogos/{clave}/{origen}/fusionar/{destino}` |

## `core` (/core) — 22 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/core/activos/{activo_id}/especificaciones` |
| [x] | POST | `/api/v1/core/activos/{activo_id}/especificaciones` |
| [x] | PATCH | `/api/v1/core/especificaciones/{esp_id}` |
| [x] | DELETE | `/api/v1/core/especificaciones/{esp_id}` |
| [x] | POST | `/api/v1/core/activos` |
| [x] | PATCH | `/api/v1/core/activos/lote` |
| [x] | POST | `/api/v1/core/activos/lote/restaurar` |
| [x] | PATCH | `/api/v1/core/activos/{activo_id}` |
| [x] | GET | `/api/v1/core/activos` |
| [x] | GET | `/api/v1/core/activos/by-code/{codigo}` |
| [x] | POST | `/api/v1/core/activos/etiquetas.pdf` |
| [x] | GET | `/api/v1/core/activos/{activo_id}` |
| [x] | DELETE | `/api/v1/core/activos/{activo_id}` |
| [x] | POST | `/api/v1/core/activos/search` |
| [x] | POST | `/api/v1/core/activos/{activo_id}/baja` |
| [x] | GET | `/api/v1/core/activos/{activo_id}/componentes` |
| [x] | POST | `/api/v1/core/activos/{activo_id}/componentes` |
| [x] | POST | `/api/v1/core/activos/{activo_id}/componentes/{componente_id}/retirar` |
| [x] | GET | `/api/v1/core/activos/{id}/consumibles` |
| [x] | GET | `/api/v1/core/tipos-activo/{tipo_id}/reclasificar` |
| [x] | POST | `/api/v1/core/tipos-activo/{tipo_id}/reclasificar` |
| [x] | GET | `/api/v1/core/activos/{id}/contexto` |

## `traceability` (/trazabilidad) — 20 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/trazabilidad/tipos` |
| [x] | GET | `/api/v1/trazabilidad/tipos/{id}` |
| [x] | POST | `/api/v1/trazabilidad/tipos` |
| [x] | PATCH | `/api/v1/trazabilidad/tipos/{id}` |
| [x] | DELETE | `/api/v1/trazabilidad/tipos/{id}` |
| [x] | GET | `/api/v1/trazabilidad/movimientos` |
| [x] | POST | `/api/v1/trazabilidad/movimientos` |
| [x] | POST | `/api/v1/trazabilidad/devolucion` |
| [x] | POST | `/api/v1/trazabilidad/transferencia` |
| [x] | GET | `/api/v1/trazabilidad/activo/{activo_id}/historial` |
| [x] | GET | `/api/v1/trazabilidad/persona/{persona_id}/asignaciones` |
| [x] | POST | `/api/v1/trazabilidad/persona/{persona_id}/offboarding` |
| [x] | GET | `/api/v1/trazabilidad/acta/{movimiento_id}` |
| [x] | POST | `/api/v1/trazabilidad/acta/lote` |
| [x] | GET | `/api/v1/trazabilidad/instalacion/{activo_id}` |
| [x] | POST | `/api/v1/trazabilidad/instalar-area` |
| [x] | POST | `/api/v1/trazabilidad/retirar-area` |
| [x] | POST | `/api/v1/trazabilidad/movimientos/revertir` |
| [x] | POST | `/api/v1/trazabilidad/entrega-lote` |
| [x] | POST | `/api/v1/trazabilidad/devolucion-lote` |

## `software` (/soft) — 27 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | POST | `/api/v1/soft/tipos-licencia` |
| [x] | GET | `/api/v1/soft/tipos-licencia` |
| [x] | GET | `/api/v1/soft/tipos-licencia/{id}` |
| [x] | PATCH | `/api/v1/soft/tipos-licencia/{id}` |
| [x] | DELETE | `/api/v1/soft/tipos-licencia/{id}` |
| [x] | POST | `/api/v1/soft/software` |
| [x] | GET | `/api/v1/soft/software` |
| [x] | GET | `/api/v1/soft/software/{id}` |
| [x] | PATCH | `/api/v1/soft/software/{id}` |
| [x] | DELETE | `/api/v1/soft/software/{id}` |
| [x] | POST | `/api/v1/soft/licencias` |
| [x] | GET | `/api/v1/soft/licencias` |
| [x] | GET | `/api/v1/soft/licencias-disponibles` |
| [x] | GET | `/api/v1/soft/licencias/{id}` |
| [x] | GET | `/api/v1/soft/licencias/{id}/historial` |
| [x] | GET | `/api/v1/soft/licencias/{id}/claves` |
| [x] | PATCH | `/api/v1/soft/licencias/{id}` |
| [x] | DELETE | `/api/v1/soft/licencias/{id}` |
| [x] | GET | `/api/v1/soft/activos/{activo_id}/instalaciones` |
| [x] | GET | `/api/v1/soft/personas/{persona_id}/instalaciones` |
| [x] | POST | `/api/v1/soft/instalaciones` |
| [x] | POST | `/api/v1/soft/instalaciones/desinstalar` |
| [x] | GET | `/api/v1/soft/licencias/{id}/credenciales` |
| [x] | POST | `/api/v1/soft/licencias/{id}/credenciales` |
| [x] | PATCH | `/api/v1/soft/credenciales/{id}` |
| [x] | POST | `/api/v1/soft/credenciales/{id}/revelar` |
| [x] | POST | `/api/v1/soft/credenciales/{id}/rotar` |

## `maintenance` (/mantenimiento) — 14 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/mantenimiento/tipos` |
| [x] | GET | `/api/v1/mantenimiento/tipos/{id}` |
| [x] | POST | `/api/v1/mantenimiento/tipos` |
| [x] | PATCH | `/api/v1/mantenimiento/tipos/{id}` |
| [x] | DELETE | `/api/v1/mantenimiento/tipos/{id}` |
| [x] | GET | `/api/v1/mantenimiento/` |
| [x] | GET | `/api/v1/mantenimiento/{id}` |
| [x] | POST | `/api/v1/mantenimiento/` |
| [x] | PATCH | `/api/v1/mantenimiento/{mantenimiento_id}/cerrar` |
| [x] | DELETE | `/api/v1/mantenimiento/{mantenimiento_id}` |
| [x] | GET | `/api/v1/mantenimiento/{mantenimiento_id}/detalles` |
| [x] | POST | `/api/v1/mantenimiento/{mantenimiento_id}/detalles` |
| [x] | PATCH | `/api/v1/mantenimiento/detalles/{detalle_id}` |
| [x] | DELETE | `/api/v1/mantenimiento/detalles/{detalle_id}` |

## `consumable` (/consumibles) — 11 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/consumibles` |
| [x] | GET | `/api/v1/consumibles/{id}` |
| [x] | POST | `/api/v1/consumibles` |
| [x] | PATCH | `/api/v1/consumibles/{id}` |
| [x] | DELETE | `/api/v1/consumibles/{id}` |
| [x] | POST | `/api/v1/consumibles/{id}/entrada` |
| [x] | POST | `/api/v1/consumibles/{id}/salida` |
| [x] | POST | `/api/v1/consumibles/{id}/ajuste` |
| [x] | GET | `/api/v1/consumibles/{id}/compatibles` |
| [x] | PUT | `/api/v1/consumibles/{id}/compatibles` |
| [x] | GET | `/api/v1/consumibles/{id}/movimientos` |

## `attachment` (/adjuntos) — 8 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/adjuntos/activos/{activo_id}` |
| [x] | POST | `/api/v1/adjuntos/activos/{activo_id}` |
| [x] | GET | `/api/v1/adjuntos/licencias/{licencia_id}` |
| [x] | POST | `/api/v1/adjuntos/licencias/{licencia_id}` |
| [x] | GET | `/api/v1/adjuntos/{id}/download` |
| [x] | DELETE | `/api/v1/adjuntos/{id}` |

## `procurement` (/compras) — 14 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/compras/proveedores` |
| [x] | GET | `/api/v1/compras/proveedores/{id}` |
| [x] | POST | `/api/v1/compras/proveedores` |
| [x] | PATCH | `/api/v1/compras/proveedores/{id}` |
| [x] | DELETE | `/api/v1/compras/proveedores/{id}` |
| [x] | GET | `/api/v1/compras/garantias` |
| [x] | POST | `/api/v1/compras/garantias/notificar` |

## `inventario` (/inventario) — 23 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/inventario/kardex` |
| [x] | GET | `/api/v1/inventario/kardex/verificar` |
| [x] | GET | `/api/v1/inventario/kardex.csv` |
| [x] | GET | `/api/v1/inventario/custodia` |
| [x] | GET | `/api/v1/inventario/custodia.csv` |
| [x] | GET | `/api/v1/inventario/entradas-salidas` |
| [x] | GET | `/api/v1/inventario/resumen-motivos` |
| [x] | GET | `/api/v1/inventario/activos/{activo_id}/hoja-de-vida.pdf` |
| [x] | GET | `/api/v1/inventario/licencias.csv` |
| [x] | GET | `/api/v1/inventario/atencion` |
| [x] | GET | `/api/v1/reports/stock` |
| [x] | GET | `/api/v1/reports/entries` |
| [x] | GET | `/api/v1/reports/assignments` |
| [x] | GET | `/api/v1/export/mantenimientos.csv` |
| [x] | GET | `/api/v1/export/personas.csv` |
| [x] | GET | `/api/v1/export/garantias.csv` |
| [x] | GET | `/api/v1/reports/stock.csv` |
| [x] | GET | `/api/v1/reports/entries.csv` |
| [x] | GET | `/api/v1/reports/assignments.csv` |
| [x] | POST | `/api/v1/inventario/cierre-mensual/cerrar` |
| [x] | GET | `/api/v1/gov/alerts` |
| [x] | GET | `/api/v1/gov/config/logo` |
| [x] | POST | `/api/v1/gov/config/logo` |
| [x] | GET | `/api/v1/adjuntos/ingresos/{id}` |
| [x] | POST | `/api/v1/adjuntos/ingresos/{id}` |
| [x] | GET | `/api/v1/goods-receipts` |
| [x] | POST | `/api/v1/goods-receipts` |
| [x] | GET | `/api/v1/goods-receipts/form-context` |
| [x] | GET | `/api/v1/goods-receipts/checks/document` |
| [x] | GET | `/api/v1/goods-receipts/checks/serial` |
| [x] | GET | `/api/v1/goods-receipts/checks/hostname` |
| [x] | GET | `/api/v1/goods-receipts/classification-suggestion` |
| [x] | GET | `/api/v1/goods-receipts/pending-units` |
| [x] | PATCH | `/api/v1/goods-receipts/units/{asset}` |
| [x] | POST | `/api/v1/goods-receipts/lines/{line}/license-keys` |
| [x] | POST | `/api/v1/goods-receipts/catalog/models` |
| [x] | GET | `/api/v1/goods-receipts/catalog/models/{model}/spec-defaults` |
| [x] | PUT | `/api/v1/goods-receipts/catalog/models/{model}/spec-defaults` |
| [x] | GET | `/api/v1/goods-receipts/{id}` |
| [x] | PATCH | `/api/v1/goods-receipts/{id}` |
| [x] | POST | `/api/v1/goods-receipts/{id}/cancel` |
| [x] | POST | `/api/v1/goods-receipts/{id}/confirm` |
| [x] | GET | `/api/v1/goods-receipts/{id}/act.pdf` |
| [x] | GET | `/api/v1/inventario/cierre-mensual` |
| [x] | GET | `/api/v1/inventario/cierre-mensual.xlsx` |
| [x] | GET | `/api/v1/inventario/faltantes` |
| [x] | POST | `/api/v1/inventario/faltantes/{faltante_id}/seguimiento` |
| [x] | POST | `/api/v1/inventario/faltantes/{faltante_id}/localizado` |
| [x] | POST | `/api/v1/inventario/faltantes/{faltante_id}/baja` |
| [x] | GET | `/api/v1/inventario/tomas` |
| [x] | POST | `/api/v1/inventario/tomas` |
| [x] | GET | `/api/v1/inventario/tomas/{toma_id}` |
| [x] | POST | `/api/v1/inventario/tomas/{toma_id}/lecturas` |
| [x] | POST | `/api/v1/inventario/tomas/{toma_id}/cerrar` |
| [x] | POST | `/api/v1/inventario/tomas/{toma_id}/anular` |
| [x] | GET | `/api/v1/inventario/tomas/{toma_id}/reporte.csv` |

## `aprobaciones` (/aprobaciones) — 5 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/aprobaciones` |
| [x] | GET | `/api/v1/aprobaciones/pendientes` |
| [x] | POST | `/api/v1/aprobaciones/{aprobacion_id}/aprobar` |
| [x] | POST | `/api/v1/aprobaciones/{aprobacion_id}/rechazar` |
| [x] | POST | `/api/v1/aprobaciones/{aprobacion_id}/anular` |

## `stats` (/stats) — 1 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/stats/dashboard` |

## `productividad` (/productividad) — 7 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/productividad/buscar` |
| [x] | GET | `/api/v1/productividad/kit-sugerido/{persona_id}` |
| [x] | GET | `/api/v1/productividad/personas/{persona_id}/ficha` |
| [x] | GET | `/api/v1/productividad/calidad-datos` |
| [x] | GET | `/api/v1/productividad/importar/activos/estado` |
| [x] | GET | `/api/v1/productividad/importar/activos/plantilla` |
| [x] | POST | `/api/v1/productividad/importar/activos/previsualizar` |
| [x] | POST | `/api/v1/productividad/importar/activos/confirmar` |

## `export` (/export) — 6 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/export/activos.csv` |
| [x] | GET | `/api/v1/export/movimientos.csv` |
| [x] | GET | `/api/v1/export/consumibles.csv` |
| [x] | GET | `/api/v1/export/proveedores.csv` |
| [x] | GET | `/api/v1/export/auditoria.csv` |

## `directory` (/directorio) — 13 rutas

| Estado | Método | Ruta |
|---|---|---|
| [x] | GET | `/api/v1/directorio/estado` |
| [x] | POST | `/api/v1/directorio/probar` |
| [x] | POST | `/api/v1/directorio/sincronizar` |
| [x] | GET | `/api/v1/directorio/grupos` |
| [x] | GET | `/api/v1/directorio/integracion` |
| [x] | PUT | `/api/v1/directorio/integracion` |
| [x] | POST | `/api/v1/directorio/integracion/probar-correo` |
| [x] | POST | `/api/v1/directorio/integracion/probar-ad` |
| [x] | POST | `/api/v1/directorio/integracion/desbloquear` |
| [x] | GET | `/api/v1/directorio/notificaciones/reglas` |
| [x] | PUT | `/api/v1/directorio/notificaciones/reglas/{evento}` |
| [x] | DELETE | `/api/v1/directorio/notificaciones/reglas/{evento}` |
| [x] | GET | `/api/v1/directorio/notificaciones/vista-previa/{evento}` |

---

**Total: 325 rutas — migradas: 325.**
