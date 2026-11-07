# Inventario TI

- `backend/`: PHP 8.5 + Symfony 8.1 (FrankenPHP) sobre Oracle Database 21c. No se agrega código, dependencias ni SQL de otro motor.
- `frontend/`: React 19 + MUI 7 + i18next (es, en, it).
- Solo Docker: no hay PHP, Python ni Node en el equipo. En Git Bash, `MSYS_NO_PATHCONV=1` y `pwd -W` para rutas de Docker.
- Código nuevo en inglés y por capas (dominio, casos de uso, repositorios, controladores delgados). Documentación y commits en español.
- API: el frontend traduce los códigos `{"detail": "<CÓDIGO>"}`, así que no se cambian. SQL explícito con DBAL e identificadores entre comillas.
- Toda acción autenticada usa `#[Autenticado]`. Seguridad en dos capas: validaciones de la aplicación y VPD de Oracle.
- Vocabulario: `docs/GLOSARIO.md`. Lo que un rol no puede trabajar no se le muestra.
- Arquitectura: `docs/ARQUITECTURA.md`. Pruebas y despliegue: `README.md`. `e2e.sh` y `persistencia.sh` no corren contra producción.
