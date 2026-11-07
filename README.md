# Inventario TI

Sistema de inventario de equipos de TI: ingreso a bodega, custodia, mantenimiento, licencias, tomas físicas y cierre mensual, con trazabilidad por kardex. La aplicación está en español, inglés e italiano.

| Carpeta | Contenido | Tecnología |
|---|---|---|
| [`backend/`](backend) | API, base de datos, despliegue y pruebas | PHP 8.5, Symfony 8.1, Oracle Database 21c |
| [`frontend/`](frontend) | Aplicación web | React 19, MUI 7, TypeScript |
| [`docs/`](docs) | [Arquitectura](docs/ARQUITECTURA.md), [manual de operación](docs/MANUAL_OPERACION.md), [glosario](docs/GLOSARIO.md) y [pendientes](docs/PENDIENTES.md) | |

Solo hace falta Docker: PHP, Node y Oracle corren en contenedores.

## Desplegar

Los secretos van en `backend/deploy/.env` y `backend/deploy/.env.php`, que no se versionan. El segundo parte de `backend/deploy/.env.php.example`; las variables del primero están en `backend/deploy/docker-compose.yml` y `backend/api/.env`.

```bash
cd backend
bash herramientas/desplegar.sh              # respaldo verificado, imágenes, migraciones y stack
bash herramientas/desplegar.sh estado       # contenedores y prueba de humo
bash herramientas/desplegar.sh esquema      # verifica migraciones y VPD sin cambiar nada
bash herramientas/desplegar.sh respaldar    # respaldo Data Pump verificado
bash herramientas/desplegar.sh recargar     # recarga el Caddyfile sin cortar conexiones
```

Caddy envía `/api/*` y `/health` a la API, bloquea `/metrics` y `/health/full`, y sirve lo demás desde el frontend.

## Probar

```bash
cd backend
bash herramientas/pruebas.sh                      # suite completa contra Oracle real, con dobles de correo, S3, AD y Redis
bash herramientas/pruebas.sh --unit               # sin base de datos
bash herramientas/pruebas.sh --filter LoginTest   # una parte
bash herramientas/resistencia.sh                  # concurrencia, carga y durabilidad
bash herramientas/migraciones.sh vacia            # migraciones sobre una base vacía

cd ../frontend
bash test-support/run.sh                          # tsc, lint y pruebas
```

Las pruebas de punta a punta (Playwright) corren contra un entorno temporal, nunca contra producción, porque restablecen cuentas y restauran datos:

```bash
cd backend
bash herramientas/qa/entorno.sh subir             # entorno de QA con una copia del último respaldo
bash herramientas/qa/e2e.sh --project=chromium    # sin argumentos: todos los navegadores y móvil
bash herramientas/qa/entorno.sh borrar            # elimina el entorno; no toca producción
```

## Base de datos

- **Esquema:** migraciones de Doctrine en `backend/api/migrations`, con SQL explícito. Un cambio de esquema es una migración nueva más las consultas afectadas, en el mismo cambio.
- **Seguridad en la base:** `backend/api/src/Esquema` instala VPD, disparadores de inmutabilidad y kardex con sello encadenado. La API se conecta con un usuario que solo tiene DML.
- **Orden al desplegar:** el servicio `migrador`, conectado como dueño del esquema, corre antes que la API y aborta el despliegue si el esquema no queda completo.

## Red con inspección TLS

Si la red inspecciona TLS, exporte la CA de la organización a un `.pem` y pásela al build. Se usa solo para instalar dependencias y no queda en la imagen. Nunca se desactiva la verificación TLS.

```bash
CA_CORPORATIVA=/ruta/ca.pem bash herramientas/desplegar.sh
```
