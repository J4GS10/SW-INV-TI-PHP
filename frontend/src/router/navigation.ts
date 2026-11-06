import DashboardIcon from '@mui/icons-material/Dashboard';
import ComputerIcon from '@mui/icons-material/Computer';
import ManageAccountsIcon from '@mui/icons-material/ManageAccounts';
import SettingsIcon from '@mui/icons-material/Settings';
import CategoryIcon from '@mui/icons-material/Category';
import PlaceIcon from '@mui/icons-material/Place';
import BadgeIcon from '@mui/icons-material/Badge';
import BusinessIcon from '@mui/icons-material/Business';
import SwapHorizIcon from '@mui/icons-material/SwapHoriz';
import ViewListIcon from '@mui/icons-material/ViewList';
import AppsIcon from '@mui/icons-material/Apps';
import BuildIcon from '@mui/icons-material/Build';
import Inventory2Icon from '@mui/icons-material/Inventory2';
import StorefrontIcon from '@mui/icons-material/Storefront';
import ReceiptLongIcon from '@mui/icons-material/ReceiptLong';
import VerifiedUserIcon from '@mui/icons-material/VerifiedUser';
import PolicyIcon from '@mui/icons-material/Policy';
import QrCodeScannerIcon from '@mui/icons-material/QrCodeScanner';
import EditNoteIcon from '@mui/icons-material/EditNote';
import ContactMailIcon from '@mui/icons-material/ContactMail';
import TimelineIcon from '@mui/icons-material/Timeline';
import FactCheckIcon from '@mui/icons-material/FactCheck';
import ApprovalIcon from '@mui/icons-material/Approval';
import PersonAddIcon from '@mui/icons-material/PersonAdd';
import UploadFileIcon from '@mui/icons-material/UploadFile';
import LibraryBooksIcon from '@mui/icons-material/LibraryBooks';
import CalendarMonthIcon from '@mui/icons-material/CalendarMonth';
import PlaylistAddCheckIcon from '@mui/icons-material/PlaylistAddCheck';
import type { SvgIconComponent } from '@mui/icons-material';
import { UserRole } from '../types/auth';
import {
  ADMIN_READER_ROLES, AUDIT_READER_ROLES, IAM_ROLES, INVENTORY_ADMIN_ROLES, INVENTORY_ROLES, PURCHASE_READ_ROLES,
  SOFTWARE_PAGE_ROLES, STOCK_ROLES, FINANCE_EXPORT_ROLES, WARRANTY_ROLES, DIRECTORY_ROLES, GOODS_RECEIPT_ROLES,
} from '../utils/roles';

export interface NavItem {
  title: string;
  path: string;
  icon: SvgIconComponent;
  allowedRoles: UserRole[];
  /** Grupo por tarea: el día a día, el control, compras, personas y la configuración. */
  group: 'DAILY' | 'CONTROL' | 'PROCUREMENT' | 'ORGANIZATION' | 'SETTINGS';
  /** Stays reachable and searchable, without its own menu entry: it is one tab of a bigger task. */
  hiddenInMenu?: boolean;
}

export const NAVIGATION_ITEMS: NavItem[] = [
  // ---------- Día a día ----------
  { title: 'menu.my_tasks', path: '/inicio', icon: PlaylistAddCheckIcon, allowedRoles: [...STOCK_ROLES, 'COMPRAS'], group: 'DAILY' },
  { title: 'menu.pending_units', path: '/ingresos/por-completar', icon: EditNoteIcon, allowedRoles: ['ADMIN_TI', 'TECNICO'], group: 'DAILY' },
  { title: 'menu.assets', path: '/activos', icon: ComputerIcon, allowedRoles: STOCK_ROLES, group: 'DAILY' },
  { title: 'menu.scanner', path: '/scanner', icon: QrCodeScannerIcon, allowedRoles: INVENTORY_ROLES, group: 'DAILY' },
  { title: 'menu.movements', path: '/operaciones/movimientos', icon: SwapHorizIcon, allowedRoles: INVENTORY_ROLES, group: 'DAILY' },
  { title: 'menu.onboarding', path: '/operaciones/alta-empleado', icon: PersonAddIcon, allowedRoles: INVENTORY_ADMIN_ROLES, group: 'DAILY' },
  { title: 'menu.maintenance', path: '/mantenimiento', icon: BuildIcon, allowedRoles: INVENTORY_ROLES, group: 'DAILY' },
  { title: 'menu.consumables', path: '/consumibles', icon: Inventory2Icon, allowedRoles: STOCK_ROLES, group: 'DAILY' },

  // ---------- Control ----------
  { title: 'menu.dashboard', path: '/panel', icon: DashboardIcon, allowedRoles: STOCK_ROLES, group: 'CONTROL' },
  { title: 'menu.traceability', path: '/trazabilidad', icon: TimelineIcon, allowedRoles: INVENTORY_ROLES, group: 'CONTROL' },
  { title: 'menu.inventory_count', path: '/inventario/tomas', icon: FactCheckIcon, allowedRoles: INVENTORY_ROLES, group: 'CONTROL' },
  { title: 'menu.approvals', path: '/aprobaciones', icon: ApprovalIcon, allowedRoles: ADMIN_READER_ROLES, group: 'CONTROL' },
  { title: 'menu.month_end', path: '/contabilidad/cierre', icon: CalendarMonthIcon, allowedRoles: FINANCE_EXPORT_ROLES, group: 'CONTROL' },
  { title: 'menu.audit', path: '/auditoria', icon: PolicyIcon, allowedRoles: AUDIT_READER_ROLES, group: 'CONTROL' },

  // ---------- Compras y licencias ----------
  { title: 'menu.goods_receipts', path: '/ingresos', icon: ReceiptLongIcon, allowedRoles: GOODS_RECEIPT_ROLES, group: 'PROCUREMENT' },
  { title: 'menu.stock_report', path: '/compras/stock', icon: Inventory2Icon, allowedRoles: FINANCE_EXPORT_ROLES, group: 'PROCUREMENT' },
  { title: 'menu.providers', path: '/compras/proveedores', icon: StorefrontIcon, allowedRoles: PURCHASE_READ_ROLES, group: 'PROCUREMENT' },
  { title: 'menu.warranties', path: '/compras/garantias', icon: VerifiedUserIcon, allowedRoles: WARRANTY_ROLES, group: 'PROCUREMENT' },
  { title: 'menu.software', path: '/software', icon: AppsIcon, allowedRoles: SOFTWARE_PAGE_ROLES, group: 'PROCUREMENT' },

  // ---------- Personas y accesos (sin inventario para ADMIN_SEGURIDAD) ----------
  { title: 'menu.persons', path: '/empleados', icon: BadgeIcon, allowedRoles: ADMIN_READER_ROLES, group: 'ORGANIZATION' },
  { title: 'menu.departments', path: '/departamentos', icon: BusinessIcon, allowedRoles: ADMIN_READER_ROLES, group: 'ORGANIZATION' },
  { title: 'menu.users', path: '/usuarios', icon: ManageAccountsIcon, allowedRoles: IAM_ROLES, group: 'ORGANIZATION' },

  // ---------- Configuración (catálogos e importación) ----------
  { title: 'menu.import', path: '/config/importar', icon: UploadFileIcon, allowedRoles: INVENTORY_ADMIN_ROLES, group: 'SETTINGS' },
  { title: 'menu.catalog_center', path: '/config/catalogos', icon: LibraryBooksIcon, allowedRoles: INVENTORY_ADMIN_ROLES, group: 'SETTINGS' },
  { hiddenInMenu: true, title: 'menu.brands', path: '/config/marcas', icon: CategoryIcon, allowedRoles: INVENTORY_ADMIN_ROLES, group: 'SETTINGS' },
  { hiddenInMenu: true, title: 'menu.models', path: '/config/modelos', icon: ViewListIcon, allowedRoles: INVENTORY_ADMIN_ROLES, group: 'SETTINGS' },
  { hiddenInMenu: true, title: 'menu.types', path: '/config/tipos', icon: CategoryIcon, allowedRoles: INVENTORY_ADMIN_ROLES, group: 'SETTINGS' },
  { hiddenInMenu: true, title: 'menu.locations', path: '/config/ubicaciones', icon: PlaceIcon, allowedRoles: INVENTORY_ADMIN_ROLES, group: 'SETTINGS' },
  { title: 'menu.directory', path: '/config/directorio', icon: ContactMailIcon, allowedRoles: DIRECTORY_ROLES, group: 'SETTINGS' },
  { title: 'menu.config', path: '/config', icon: SettingsIcon, allowedRoles: ['SUPER_ADMIN'], group: 'SETTINGS' },
];

/** Página de navegación que corresponde a una ruta (también a sus fichas: /activos/LAP-00001). */
export function navItemFor(pathname: string): NavItem | undefined {
  const exacta = NAVIGATION_ITEMS.find((n) => n.path === pathname);
  if (exacta) return exacta;
  return [...NAVIGATION_ITEMS]
    .filter((n) => n.path !== '/' && pathname.startsWith(`${n.path}/`))
    .sort((a, b) => b.path.length - a.path.length)[0];
}
