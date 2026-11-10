# Inventario TI

Sistema de inventario de equipos de TI: ingreso a bodega, custodia, mantenimiento, licencias, tomas físicas y cierre mensual, con trazabilidad por kardex. La aplicación está en español, inglés e italiano.

| Carpeta | Contenido | Tecnología |
|---|---|---|
| [`backend/`](backend) | API, base de datos, despliegue y pruebas | PHP 8.5, Symfony 8.1, Oracle Database 21c |
| [`frontend/`](frontend) | Aplicación web | React 19, MUI 7, TypeScript |
| [`docs/`](docs) | [Puesta en marcha](docs/PUESTA_EN_MARCHA.md), [pruebas por rol](docs/PRUEBAS_POR_ROL.md), [arquitectura](docs/ARQUITECTURA.md), [manual de operación](docs/MANUAL_OPERACION.md), [glosario](docs/GLOSARIO.md) y [pendientes](docs/PENDIENTES.md) | |

Solo hace falta Docker: PHP, Node y Oracle corren en contenedores.

## Desplegar

Los secretos van en `backend/deploy/.env` y `backend/deploy/.env.php`, que no se versionan. En un servidor nuevo los crea `bash herramientas/generar_env.sh` a partir de `backend/deploy/.env.example`, con claves aleatorias; los pasos completos están en la [puesta en marcha](docs/PUESTA_EN_MARCHA.md).

```bash
cd backend
bash herramientas/desplegar.sh              # respaldo verificado, imágenes, migraciones y stack
bash herramientas/desplegar.sh estado       # contenedores y prueba de humo
bash herramientas/desplegar.sh esquema      # verifica migraciones y VPD sin cambiar nada
bash herramientas/desplegar.sh respaldar    # respaldo Data Pump verificado
bash herramientas/desplegar.sh recargar     # recarga el Caddyfile sin cortar conexiones
```

Caddy envía `/api/*`, `/health` y `/.well-known/mercure` (avisos en vivo) a la API, bloquea `/metrics` y `/health/full`, y sirve lo demás desde el frontend.

## Probar

```bash
cd backend
CONFIRMAR=SI bash herramientas/pruebas_produccion.sh   # suite completa sobre la base de PRODUCCIÓN: respaldo, prueba y vuelta exacta al respaldo
CONFIRMAR=SI SONDEO=SI bash herramientas/pruebas_produccion.sh   # además repite cada petición con un dato mal escrito; los 500 y los aceptados quedan en api/var/sondeo-entradas.jsonl
bash herramientas/pruebas.sh                      # suite completa contra Oracle real, con dobles de correo, S3, AD y Redis
bash herramientas/pruebas.sh --unit               # sin base de datos
bash herramientas/pruebas.sh --filter LoginTest   # una parte
bash herramientas/resistencia.sh                  # concurrencia, carga y durabilidad
bash herramientas/migraciones.sh vacia            # migraciones sobre una base vacía

cd ../frontend
bash test-support/run.sh                          # tsc, lint y pruebas
```

**Un solo entorno: producción.** El sistema se prueba en el mismo despliegue que se usa, con las cuentas de prueba por rol ([pruebas por rol](docs/PRUEBAS_POR_ROL.md)). No existe entorno de QA ni otra copia del sistema, y no se levanta ninguno. Las pruebas de arriba verifican el código y al terminar no dejan ningún contenedor encendido.

## Base de datos

- **Esquema:** migraciones de Doctrine en `backend/api/migrations`, con SQL explícito. Un cambio de esquema es una migración nueva más las consultas afectadas, en el mismo cambio.
- **Seguridad en la base:** `backend/api/src/Esquema` instala VPD, disparadores de inmutabilidad y kardex con sello encadenado. La API se conecta con un usuario que solo tiene DML.
- **Orden al desplegar:** el servicio `migrador`, conectado como dueño del esquema, corre antes que la API y aborta el despliegue si el esquema no queda completo.

## Red con inspección TLS

Si la red inspecciona TLS, exporte la CA de la organización a un `.pem` y pásela al build. Se usa solo para instalar dependencias y no queda en la imagen. Nunca se desactiva la verificación TLS.

```bash
CA_CORPORATIVA=/ruta/ca.pem bash herramientas/desplegar.sh
```
