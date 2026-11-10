# Puesta en marcha en el servidor formal

Pasos para instalar Inventario TI en un servidor nuevo y dejarlo listo para el uso real. Para entender cada pieza está la [arquitectura](ARQUITECTURA.md); para el día a día, el [manual de operación](MANUAL_OPERACION.md).

> Hasta hoy el sistema solo ha corrido en Windows con Docker Desktop. En Linux, los pasos marcados con **(Linux)** no se han probado: si algo falla, revise primero los permisos de las carpetas.

## 1. El servidor

| Recurso | Mínimo | Por qué |
|---|---|---|
| Sistema | Ubuntu Server 24.04 LTS (recomendado) o Windows con Docker Desktop | Docker Engine en Linux es gratuito. Docker Desktop exige licencia paga en empresas de 250 o más empleados o de más de 10 millones de dólares de ingresos anuales |
| CPU y memoria | 4 núcleos y 8 GB | Oracle usa hasta 3 GB y la API hasta 1 GB |
| Disco | 100 GB, más otro disco o una carpeta de red para la copia de los respaldos | Datos, adjuntos y 14 días de respaldos |
| Red | IP fija y un nombre en el DNS interno, por ejemplo `inventario.empresa.local` | El certificado se emite para ese nombre |
| Puertos | 443 (y 80, que redirige a 443) abiertos desde la red interna | Caddy es la única entrada |

Pida a redes el registro DNS (tipo A) del nombre a la IP fija del servidor antes de empezar.

## 2. Instalar Docker y Git

**(Linux)** Instale Docker Engine con su repositorio oficial (<https://docs.docker.com/engine/install/ubuntu/>) y luego:

```bash
sudo apt install -y git
sudo systemctl enable --now docker      # arranca solo al encender el servidor
sudo usermod -aG docker $USER           # usar docker sin sudo (cierre la sesión y vuelva a entrar)
```

**Windows:** instale Docker Desktop y Git for Windows. En Docker Desktop active *Start Docker Desktop when you sign in*; sin eso, después de un reinicio el sistema queda caído. Los comandos de esta guía se ejecutan en Git Bash, con `export MSYS_NO_PATHCONV=1`.

## 3. Obtener el código

El repositorio es privado: cree en GitHub un *fine-grained token* de solo lectura para `SW-INV-TI-PHP` y úselo como contraseña al clonar.

```bash
sudo mkdir -p /srv/inventario && sudo chown $USER /srv/inventario     # (Linux)
git clone https://github.com/J4GS10/SW-INV-TI-PHP.git /srv/inventario/app
cd /srv/inventario/app/backend
```

## 4. Configuración

```bash
bash herramientas/generar_env.sh
```

Crea `deploy/.env` y `deploy/.env.php` con todas las claves generadas al azar, sin mostrarlas. Nunca sobrescribe archivos existentes. Después edite en `deploy/.env` solo estas líneas:

| Variable | Ejemplo | Qué es |
|---|---|---|
| `DOMAIN` | `inventario.empresa.local` | Nombre por el que se entra. Se pueden poner varios separados por coma, incluida la IP |
| `BACKEND_CORS_ORIGINS` | `["https://inventario.empresa.local"]` | Los mismos nombres, con `https://` |
| `FRONTEND_BASE_URL` | `https://inventario.empresa.local` | La dirección que va en los enlaces de los correos |
| `TLS_DEFAULT_SNI` | `inventario.empresa.local` | El certificado que recibe quien entra por IP |
| `TLS_EMISOR` | `internal` | CA privada de Caddy. Solo con un dominio público se pone un correo para Let's Encrypt |
| `COMPANY_NAME` | Nombre de la empresa | Aparece en actas y reportes. Se puede cambiar después en *Configuración* |
| `SUPER_ADMIN_EMAIL` | `soporte.ti@empresa.com` | Correo de la cuenta raíz |
| `BACKUP_DIR` | `/srv/inventario/respaldos` | Carpeta de respaldos, fuera de la carpeta del código |
| `BACKUP_REPLICA_DIR` | `/mnt/nas/inventario` | Copia de los respaldos en otro disco o en la red |

Deje `SUPER_ADMIN_PASSWORD` vacía: el sistema genera una temporal y obliga a cambiarla al entrar.

**(Linux)** Cree las carpetas de respaldo con el dueño que usan los contenedores (Oracle corre con el usuario 54321):

```bash
sudo install -d -o 54321 -g 54321 /srv/inventario/respaldos/oracle
sudo install -d /srv/inventario/respaldos/minio /mnt/nas/inventario
```

**Guarde una copia de `deploy/.env` y `deploy/.env.php` en un gestor de contraseñas, fuera del servidor.** Sin `SECRET_KEY`, `HMAC_KEYS`, `FIELD_ENCRYPTION_KEY` y `MINIO_KMS_SECRET_KEY`, ningún respaldo se puede usar.

## 5. Primer despliegue

```bash
bash herramientas/desplegar.sh
```

La primera vez descarga y construye las imágenes (de 10 a 20 minutos, con Internet). Como no hay base, no respalda: el migrador crea el esquema, la seguridad, el catálogo mínimo y la cuenta raíz. Termina con una prueba de humo; todas las rutas deben responder como en [Verificar](#9-verificar).

Contraseña temporal de `sa` (se muestra una sola vez):

```bash
docker logs inventario-migrador-1 2>&1 | grep "contraseña temporal"
```

## 6. Certificado con la CA privada

Caddy creó su propia CA, «Caddy Local Authority», con una raíz válida por 10 años. Emite y renueva solo el certificado del servidor. Para que los navegadores no avisen, cada equipo debe confiar en esa raíz:

1. Exporte la raíz pública: `docker exec inventario-caddy-1 cat /data/caddy/pki/authorities/local/root.crt > caddy-root.crt`.
2. Distribúyala por GPO: *Administración de directivas de grupo → Configuración del equipo → Directivas → Configuración de Windows → Configuración de seguridad → Directivas de clave pública → Entidades de certificación raíz de confianza → Importar*.
3. Firefox usa su propio almacén: active la directiva `Certificates → ImportEnterpriseRoots`. En teléfonos se instala a mano como certificado de CA.

La CA vive en el volumen `inventario_caddy_data`. Si se borra, Caddy crea una raíz nueva y hay que distribuirla otra vez. Guarde una copia de la carpeta `/data/caddy/pki/authorities/local` en el gestor de contraseñas: incluye la clave privada.

## 7. Configuración inicial, en este orden

| Paso | Quién | Dónde | Qué |
|---|---|---|---|
| 1 | `sa` | Inicio de sesión | Cambia la contraseña temporal y configura el MFA con una aplicación autenticadora (Microsoft o Google Authenticator). Guarde los códigos de recuperación |
| 2 | `sa` | *Configuración* | Nombre de la empresa, logo e idioma |
| 3 | `sa` | *Usuarios y accesos* | Crea al menos **dos** ADMIN_TI y las cuentas de Compras, técnicos y auditor, con sus sedes. Cada uno recibe una contraseña temporal; los administradores configuran su MFA al entrar |
| 4 | ADMIN_TI | *Ubicaciones* | País, departamento o estado, municipio, sede, edificio, nivel y área |
| 5 | ADMIN_TI | *Departamentos*, *Empleados* | Áreas de la empresa y personas, a mano o desde Active Directory (paso 8) |
| 6 | ADMIN_TI | *Tipos de activo*, *Marcas*, *Modelos* | Lo que se va a inventariar. El prefijo del tipo es el inicio del código de la etiqueta y no conviene cambiarlo después |
| 7 | Compras | *Proveedores* | Proveedores habituales |
| 8 | ADMIN_TI | *Importar activos* | Carga inicial desde Excel, si la hay. Solo funciona hasta el primer cierre mensual |

Desde ahí, todo equipo nuevo entra por *Ingresos a bodega*.

## 8. Integraciones (cuando corresponda)

| Integración | Dónde | Notas |
|---|---|---|
| Correo | *Configuración* (`sa`) o `SMTP_*` en `deploy/.env` | Sin correo no llegan avisos ni la recuperación de contraseña. Pruebe el envío desde la pantalla |
| Active Directory | *Directorio y notificaciones* (`sa`) | LDAPS (636) o StartTLS. Use una cuenta de servicio de solo lectura; se guarda cifrada |

Después de cambiar `deploy/.env`, aplique con `bash herramientas/desplegar.sh`.

## 9. Verificar

```bash
bash herramientas/desplegar.sh estado
```

| Ruta | Debe responder |
|---|---|
| `/`, `/health`, `/api/v1/login/sso/providers`, `/api/v1/gov/config` | 200 |
| `/api/v1/me`, `/api/v1/core/activos`, `/api/v1/directorio/estado` | 401 |
| `/api/v1/no-existe` | 404 |
| `/health/full`, `/metrics` | 403 |
| `worker_php` | healthy |

Después:
- **Respaldo:** `bash herramientas/desplegar.sh respaldar` debe terminar en «Respaldo verificado», y el archivo debe aparecer también en `BACKUP_REPLICA_DIR`.
- **Restauración:** reemplaza la base completa. Pruébela una vez en el servidor formal recién instalado, antes de cargar datos reales (hay un solo entorno: no se levanta otra copia para probarla).
- **Reinicio:** reinicie el servidor y compruebe que todo vuelve solo.

## 10. Mantenimiento

| Tarea | Cómo |
|---|---|
| Actualizar a una versión nueva | `git pull` y `bash herramientas/desplegar.sh`: respalda, migra y verifica |
| Volver a la versión anterior | `git checkout <commit anterior>` y `bash herramientas/desplegar.sh`. Si esa actualización tuvo migración, restaure además el respaldo del despliegue ([arquitectura](ARQUITECTURA.md#respaldos)) |
| Ver errores | `docker logs inventario-api_php-1 --tail 100` |
| Mover a otro servidor | Copie `deploy/.env`, `deploy/.env.php`, el último respaldo y los adjuntos; con otras claves los datos cifrados no se leen |

## 11. Seguridad e infraestructura en Proxmox

### Diseño

| Etapa | VM | Contiene |
|---|---|---|
| 1 (hoy) | `inv-app` | Todo el stack, como está probado |
| 2 | `inv-app` | Caddy, frontend, API, worker y Redis: no guarda datos y se reconstruye desde Git |
| 2 | `inv-datos` | Oracle, MinIO y los respaldos, sin acceso desde la red de usuarios |
| Siempre | Proxmox Backup Server | Copia diaria de las VM, en otro equipo físico, con sincronización fuera del sitio |

En una sola VM, la seguridad no depende de cuántas máquinas haya, sino de las capas:
- Solo Caddy publica puertos (443 y 80).
- La base, Redis y MinIO están en una red de Docker sin salida a Internet.
- La API entra a Oracle con un usuario que solo lee y escribe filas, y VPD filtra por sede.
- MFA, TLS, secretos fuera de Git y respaldos verificados.

Lo que una sola VM no protege: quien tome el control de la VM tiene todo. Por eso importa el endurecimiento de abajo. La etapa 2 reduce ese alcance, porque la base deja de compartir máquina con lo que recibe tráfico.

Repartir cada servicio en su propia VM no da alta disponibilidad: Oracle XE no tiene clúster ni Data Guard, así que la base es un punto único de falla en cualquier diseño. La protección real es detectar rápido y recuperar rápido.

### Endurecimiento de la VM

- **Red:** VLAN de servidores. En el firewall de Proxmox, la VM acepta solo 443 y 80 desde la red de usuarios y SSH desde la red de TI. En la etapa 2, `inv-datos` acepta 1521 y 9000 solo desde `inv-app`.
- **Acceso:** SSH solo con llave, sin contraseña y sin `root`. Solo los administradores en el grupo `docker`, porque equivale a ser `root`. Nunca exponga la API de Docker (puerto 2375).
- **Parches:** `unattended-upgrades` para el sistema. Las imágenes se actualizan con `desplegar.sh`.
- **Secretos:** `deploy/.env` y `.env.php` con permisos 600 (los crea así `generar_env.sh`) y una copia en el gestor de contraseñas.
- **Proxmox:** `qemu-guest-agent` en la VM (respaldos consistentes), discos aparte para los datos y acceso al panel de Proxmox solo desde la red de TI y con 2FA.

### Nombres, no IP

- Los usuarios entran por un nombre del DNS interno (`DOMAIN`). Si la IP cambia, solo se actualiza el registro DNS.
- Dentro del stack, los servicios se encuentran por su nombre en Docker (`oracle`, `redis`, `minio`, `api_php`). En la etapa 2 se apuntan al nombre DNS de `inv-datos` con `ORACLE_HOST`, `REDIS_HOST` y `S3_ENDPOINT_URL` en `deploy/.env`, sin tocar el código.
- Dé a cada VM una IP fija o una reserva de DHCP, más su registro DNS.

### Detección de caídas y recuperación

| Falla | Qué pasa solo |
|---|---|
| Se cae un contenedor | Docker lo reinicia (`restart: unless-stopped`) |
| Muere la instancia de Oracle | `vigilante.sh` hace salir al contenedor y Docker lo levanta |
| Oracle se reinicia | La API y el worker descartan la conexión rota y abren otra. Mientras tanto, la API responde 503 `DATABASE_UNAVAILABLE` con `Retry-After` y `/health` da 503 |
| La API se reinicia | Caddy retiene cada petición hasta 10 s y la reintenta, en vez de responder 502 |
| Cae Redis o MinIO | El sistema sigue: límites locales sin Redis; sin MinIO solo fallan los adjuntos |
| Respaldos, réplica, disco o correo | El worker genera una alerta cada 5 minutos |

Docker no avisa a nadie si cae la VM, la red o el host completo. Para eso hace falta un **monitor externo**, en otra VM o en otro equipo. Se recomienda **Uptime Kuma**, que es gratuito:
- `https://<DOMAIN>/health` cada minuto: 200 es sano y 503 significa que la base no responde.
- Vencimiento del certificado.
- Ping a cada VM.
- Avisos por correo, Teams o Telegram.
