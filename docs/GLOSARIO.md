# Glosario de la aplicación

Un solo nombre para cada cosa, en español, inglés e italiano. Se aplica a textos nuevos y al corregir los existentes.

| Término | Inglés | Italiano | Significa | No usar |
|---|---|---|---|---|
| Activo | Asset | Bene | Cualquier cosa registrada una por una en el inventario. Va en títulos, columnas, campos y filtros | «Componente» como tipo general |
| equipo | device | dispositivo | Solo en frases naturales («Entregue un equipo»). No como nombre de campo o columna | |
| Componente | Component | Componente | Activo instalado dentro de otro activo, como un disco o una memoria | |
| Consumible | Consumable | Consumabile | Se controla por cantidad, no por unidad | |
| Custodio | Custodian | Custode | Persona que responde por un activo | «Lo tiene», «Custodio actual» |
| Sin custodio | No custodian | Nessun custode | El activo no está a cargo de nadie | «En almacén» |
| En bodega | In storage | In deposito | Estado: guardado y libre para entregar | «Disponible», «En almacén» |
| Baja | Decommission | Dismissione | Salida definitiva del inventario, con aprobación | «Baja» como prioridad |
| Urgente, Normal, Informativa | Urgent, Normal, For information | Urgente, Normale, Informativa | Prioridad de un pendiente | «Alta», «Media», «Baja» |
| Ingreso | Goods receipt | Entrata in magazzino | Lo que Compras registra como recibido | |
| Por completar | To complete | Da completare | Activo ingresado al que le falta la serie o sus datos | |
| Ficha | Detail | Scheda | Pantalla con toda la información de un activo | |

## Fechas y prioridad visual

- **Mostrar:** `05 nov 2026`, con `formatDate`. En inglés e italiano, el formato de su idioma.
- **Capturar:** `DateField`, que usa el idioma de la aplicación y no el del navegador.
- **Colores de estado:** verde, vigente o correcto; gris, terminado o neutro; ámbar, requiere atención; rojo, problema o salida.
- **Botones:** una sola acción principal por página, la tarea de esa pantalla. Las demás, con borde o en el menú de la fila.

## Pendiente

Lo que escribe el servidor al registrar un movimiento, como «Alta manual en el inventario», queda en español en el kardex y así se ve también en inglés e italiano. El kardex no se modifica; habría que traducirlo al mostrarlo.
