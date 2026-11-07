import type { ChipColor } from '../types/ui';
import type { UserRole } from '../types/auth';

/** Orden de presentación de los roles (de mayor a menor privilegio). */
export const ALL_ROLES: readonly UserRole[] = ['SUPER_ADMIN', 'ADMIN_SEGURIDAD', 'ADMIN_TI', 'TECNICO', 'AUDITOR', 'CONSULTA',
  'COMPRAS'];

/** Roles que administran identidades y accesos. */
export const IAM_ROLES: UserRole[] = ['SUPER_ADMIN', 'ADMIN_SEGURIDAD'];

// Espejo de los grupos de app/core/roles.py: el backend responde 403 a lo que
// el rol no ve; aquí solo se evita mostrar menús y acciones que fallarían.

/** Roles que ven el inventario (el administrador de seguridad no lo ve). Espejo de INVENTORY_READ_ROLES. */
export const INVENTORY_ROLES: UserRole[] = ['ADMIN_TI', 'TECNICO', 'AUDITOR', 'CONSULTA'];

/** Cualquier rol de la aplicación salvo el de identidades. Espejo de APP_ROLES. */
export const APP_ROLES: UserRole[] = [...INVENTORY_ROLES, 'COMPRAS'];

/** Activos, consumibles y tablero. Espejo de STOCK_READ_ROLES. */
export const STOCK_ROLES: UserRole[] = INVENTORY_ROLES;

/** Software y licencias (páginas): administradores y auditor. */
export const SOFTWARE_PAGE_ROLES: UserRole[] = ['ADMIN_TI', 'AUDITOR'];

/** Compras: crean proveedores e ingresos a bodega. Espejo de PURCHASE_WRITE_ROLES. */
export const PURCHASE_WRITE_ROLES: UserRole[] = ['ADMIN_TI', 'COMPRAS'];

/** Compras en lectura. Espejo de PURCHASE_READ_ROLES. */
export const PURCHASE_READ_ROLES: UserRole[] = [...PURCHASE_WRITE_ROLES, 'AUDITOR'];

/** Ingresos a bodega: Compras registra; TI confirma y completa; los demás consultan. */
export const GOODS_RECEIPT_ROLES: UserRole[] = ['ADMIN_TI', 'TECNICO', 'AUDITOR', 'CONSULTA', 'COMPRAS'];

/** Garantías: datos de equipos concretos, fuera del alcance de Compras. */
export const WARRANTY_ROLES: UserRole[] = ['ADMIN_TI', 'AUDITOR'];

/** Exportes de valores (activos, consumibles, licencias). Espejo de FINANCE_EXPORT_ROLES. */
export const FINANCE_EXPORT_ROLES: UserRole[] = ['ADMIN_TI', 'AUDITOR', 'COMPRAS'];

/** No ven costos ni montos (el servidor tampoco se los envía). Espejo de HIDE_COST_ROLES. */
export const HIDE_COST_ROLES: UserRole[] = ['TECNICO', 'CONSULTA'];

/** Operación diaria (altas, asignaciones, mantenimiento). Espejo de `require_operativo`. */
export const OPERATOR_ROLES: UserRole[] = ['ADMIN_TI', 'TECNICO'];

/** Administración del inventario (catálogos, compras, personas, bajas). Espejo de `require_admin`. */
export const INVENTORY_ADMIN_ROLES: UserRole[] = ['ADMIN_TI'];

/** Directorio y notificaciones: solo el administrador del sistema. */
export const DIRECTORY_ROLES: UserRole[] = ['SUPER_ADMIN'];

/** Aprueban bajas y sobrantes de otra persona. Espejo de `require_aprobador`. */
export const APPROVER_ROLES: UserRole[] = ['ADMIN_TI'];

/** Verifican la integridad del kardex (sello encadenado). */
export const KARDEX_VERIFIER_ROLES: UserRole[] = ['ADMIN_TI', 'AUDITOR'];

/** Personas y su información (fichas, asignaciones, instalaciones). Espejo de PEOPLE_READ_ROLES. */
export const INVENTORY_READER_ROLES: UserRole[] = ['ADMIN_TI', 'TECNICO', 'AUDITOR'];

/** Lectura de páginas administrativas del inventario (compras, personas): admins y auditor. */
export const ADMIN_READER_ROLES: UserRole[] = ['ADMIN_TI', 'AUDITOR'];

/** Bitácora de auditoría (el auditor la ve filtrada por su alcance). */
export const AUDIT_READER_ROLES: UserRole[] = ['SUPER_ADMIN', 'ADMIN_SEGURIDAD', 'AUDITOR'];

/** Roles con alcance global implícito; al resto se le asignan sedes. */
export const ALWAYS_GLOBAL_ROLES: readonly UserRole[] = ['SUPER_ADMIN', 'ADMIN_SEGURIDAD'];

/** Solo el administrador del sistema modifica cuentas (el backend también lo aplica). */
export function canManageRole(requester: UserRole | undefined): boolean {
  return requester === 'SUPER_ADMIN';
}

/** Roles que `requester` puede asignar a una cuenta. */
export function grantableRoles(requester: UserRole | undefined): UserRole[] {
  return canManageRole(requester) ? [...ALL_ROLES] : [];
}

/** Permisos de escritura del rol (la UI oculta lo que el backend rechazaría). */
export function permissionsFor(role: UserRole | undefined) {
  return {
    canOperate: !!role && OPERATOR_ROLES.includes(role),
    canAdminInventory: !!role && INVENTORY_ADMIN_ROLES.includes(role),
    canExport: !!role && ['ADMIN_TI', 'AUDITOR'].includes(role),
    canExportFinance: !!role && FINANCE_EXPORT_ROLES.includes(role),
    canExportPurchases: !!role && (FINANCE_EXPORT_ROLES.includes(role) || role === 'COMPRAS'),
    canSeeInventory: !!role && INVENTORY_ROLES.includes(role),
    canSeePeople: !!role && INVENTORY_READER_ROLES.includes(role),
    canSeeCosts: !!role && !HIDE_COST_ROLES.includes(role),
    canSeeSoftware: !!role && SOFTWARE_PAGE_ROLES.includes(role),
    canPurchase: !!role && PURCHASE_WRITE_ROLES.includes(role),
    canApprove: !!role && APPROVER_ROLES.includes(role),
    canVerifyKardex: !!role && KARDEX_VERIFIER_ROLES.includes(role),
    /** Aprobaciones y demás lecturas de control (espejo de LECTURA_CONTROL del backend). */
    canReadControl: !!role && ADMIN_READER_ROLES.includes(role),
    readOnly: role === 'AUDITOR' || role === 'CONSULTA',
  };
}

export function roleChipColor(role: UserRole): ChipColor {
  if (role === 'SUPER_ADMIN') return 'warning';
  if (role === 'ADMIN_SEGURIDAD') return 'secondary';
  if (role === 'ADMIN_TI') return 'primary';
  if (role === 'AUDITOR') return 'info';
  if (role === 'COMPRAS') return 'secondary';
  return 'default';
}
