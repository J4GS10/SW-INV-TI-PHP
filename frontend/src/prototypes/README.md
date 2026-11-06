# Prototipos reutilizables

Los componentes de esta carpeta son la base visual para nuevas features:

- `PrototypeWorkflowCard`: estado, descripción y acciones en una composición
  consistente.
- `PrototypeActionBar`: botones declarativos; la pantalla decide qué acciones
  existen según permisos y estado, y el backend sigue siendo la autoridad.

No deben hacer llamadas HTTP ni contener reglas de negocio. Las reglas se
consumen desde utilidades tipadas y se vuelven a validar en el backend.
