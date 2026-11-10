# Inventario TI

- `backend/`: PHP 8.5 + Symfony 8.1 (FrankenPHP) sobre Oracle Database 21c. No se agrega código, dependencias ni SQL de otro motor.
- `frontend/`: React 19 + MUI 7 + i18next (es, en, it).
- Solo Docker: no hay PHP, Python ni Node en el equipo. En Git Bash, `MSYS_NO_PATHCONV=1` y `pwd -W` para rutas de Docker.
- Código nuevo en inglés y por capas (dominio, casos de uso, repositorios, controladores delgados). Documentación y commits en español.
- API: el frontend traduce los códigos `{"detail": "<CÓDIGO>"}`, así que no se cambian. SQL explícito con DBAL e identificadores entre comillas.
- Toda acción autenticada usa `#[Autenticado]`. Seguridad en dos capas: validaciones de la aplicación y VPD de Oracle.
- Vocabulario: `docs/GLOSARIO.md`. Lo que un rol no puede trabajar no se le muestra.
- Arquitectura: `docs/ARQUITECTURA.md`. Pruebas y despliegue: `README.md`.
- **Un solo entorno: producción** (proyecto compose `inventario`). Todo se prueba ahí, con las cuentas de prueba por rol (`docs/PRUEBAS_POR_ROL.md`). Nunca se levanta QA ni otra copia del sistema. Una prueba limpia lo que crea y no toca datos reales; si solo puede correr borrando datos, se pregunta antes.
- Cambios en vivo: toda escritura se anuncia por Mercure y las pantallas abiertas recargan solas (`docs/ARQUITECTURA.md`, «Actualización en vivo»). Una pantalla nueva que carga datos usa `useLiveRefresh` o `useLiveTick`.
