import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { BrowserRouter, Routes, Route, Navigate, Outlet, useLocation } from 'react-router-dom';
import { ThemeProvider, CssBaseline, Box, Toolbar, CircularProgress, IconButton, AppBar, Typography, useMediaQuery, useTheme } from '@mui/material';
import { SnackbarProvider } from 'notistack';
import { LocalizationProvider } from '@mui/x-date-pickers/LocalizationProvider';
import { AdapterDayjs } from '@mui/x-date-pickers/AdapterDayjs';
import { enUS as pickersEn, esES as pickersEs, itIT as pickersIt } from '@mui/x-date-pickers/locales';
import 'dayjs/locale/es';
import 'dayjs/locale/it';
import MenuIcon from '@mui/icons-material/Menu';
import SearchIcon from '@mui/icons-material/Search';
import i18next from 'i18next';
import { useTranslation } from 'react-i18next';
import { createAppTheme, fondoSegunTema } from './theme/theme';
import { createTheme } from '@mui/material/styles';
import { enUS, esES, itIT } from '@mui/material/locale';
import type { TemaUsuario } from './theme/theme';
import { ThemeModeContext, guardarTemaLocal, leerTemaLocal } from './context/theme-mode-context';
import { PreferenciasProvider } from './context/PreferenciasContext';
import { AuthProvider } from './context/AuthContext';
import { useAuth } from './context/auth-context';
import { ConfigProvider } from './context/ConfigContext';
import { useConfig } from './context/config-context';
import { ConfirmProvider } from './components/ConfirmDialog';
import { ErrorBoundary } from './components/ErrorBoundary';
import { Sidebar } from './components/Sidebar';
import { ProtectedRoute } from './router/ProtectedRoute';
import { WorkspaceProvider } from './context/WorkspaceContext';
import { SystemAlertsBanner } from './components/SystemAlertsBanner';
import { MfaDueBanner } from './components/MfaDueBanner';
import { TaskHomePage } from './features/dashboard/TaskHomePage';
import { useWorkspace } from './context/workspace-context';
import {
    ADMIN_READER_ROLES, AUDIT_READER_ROLES, IAM_ROLES, INVENTORY_ADMIN_ROLES, INVENTORY_ROLES, PURCHASE_READ_ROLES,
    SOFTWARE_PAGE_ROLES, STOCK_ROLES, FINANCE_EXPORT_ROLES, WARRANTY_ROLES, DIRECTORY_ROLES, GOODS_RECEIPT_ROLES, OPERATOR_ROLES, PURCHASE_WRITE_ROLES,
} from './utils/roles';
// Páginas: cada una en su fragmento. Con la sesión abierta se descargan todas en segundo plano,
// así abrir cualquier pantalla no espera la red.
const pageLoaders: Array<() => Promise<unknown>> = [];
const page: typeof lazy = (load) => {
    pageLoaders.push(load);
    return lazy(load);
};
const prefetchPages = () => {
    const run = () => pageLoaders.forEach((load) => { load().catch(() => undefined); });
    if (typeof window.requestIdleCallback === 'function') window.requestIdleCallback(run, { timeout: 3000 });
    else setTimeout(run, 1500);
};
const LoginPage = page(() => import('./features/auth/LoginPage').then((m) => ({ default: m.LoginPage })));
const ResetPasswordPage = page(() => import('./features/auth/ResetPasswordPage').then((m) => ({ default: m.ResetPasswordPage })));
const SsoCallbackPage = page(() => import('./features/auth/SsoCallbackPage').then((m) => ({ default: m.SsoCallbackPage })));
const DashboardPage = page(() => import('./features/dashboard/DashboardPage').then((m) => ({ default: m.DashboardPage })));
const AssetsPage = page(() => import('./features/assets/AssetsPage').then((m) => ({ default: m.AssetsPage })));
const ScannerPage = page(() => import('./features/scanner/ScannerPage').then((m) => ({ default: m.ScannerPage })));
const UsersPage = page(() => import('./features/users/UsersPage').then((m) => ({ default: m.UsersPage })));
const MovementsPage = page(() => import('./features/operations/MovementsPage').then((m) => ({ default: m.MovementsPage })));
const SoftwarePage = page(() => import('./features/software/SoftwarePage').then((m) => ({ default: m.SoftwarePage })));
const ConsumablesPage = page(() => import('./features/consumables/ConsumablesPage').then((m) => ({ default: m.ConsumablesPage })));
const ProvidersPage = page(() => import('./features/procurement/ProvidersPage').then((m) => ({ default: m.ProvidersPage })));
const GoodsReceiptsPage = page(() => import('./features/goods-receipts/GoodsReceiptsPage').then((m) => ({ default: m.GoodsReceiptsPage })));
const GoodsReceiptFormPage = page(() => import('./features/goods-receipts/GoodsReceiptFormPage').then((m) => ({ default: m.GoodsReceiptFormPage })));
const GoodsReceiptDetailPage = page(() => import('./features/goods-receipts/GoodsReceiptDetailPage').then((m) => ({ default: m.GoodsReceiptDetailPage })));
const PendingUnitsPage = page(() => import('./features/goods-receipts/PendingUnitsPage').then((m) => ({ default: m.PendingUnitsPage })));
const StockReportPage = page(() => import('./features/reports/StockReportPage').then((m) => ({ default: m.StockReportPage })));
const WarrantiesPage = page(() => import('./features/procurement/WarrantiesPage').then((m) => ({ default: m.WarrantiesPage })));
const MaintenancePage = page(() => import('./features/maintenance/MaintenancePage').then((m) => ({ default: m.MaintenancePage })));
const ConfigPage = page(() => import('./features/config/ConfigPage').then((m) => ({ default: m.ConfigPage })));
const PersonsPage = page(() => import('./features/persons/PersonsPage').then((m) => ({ default: m.PersonsPage })));
const DepartmentsPage = page(() => import('./features/departments/DepartmentsPage').then((m) => ({ default: m.DepartmentsPage })));
const DirectoryPage = page(() => import('./features/directory/DirectoryPage').then((m) => ({ default: m.DirectoryPage })));
const SecurityGate = page(() => import('./features/auth/security/SecurityGate').then((m) => ({ default: m.SecurityGate })));
const TrazabilidadPage = page(() => import('./features/traceability/TrazabilidadPage').then((m) => ({ default: m.TrazabilidadPage })));
const TomasPage = page(() => import('./features/inventory-count/TomasPage').then((m) => ({ default: m.TomasPage })));
const CierreMensualPage = page(() => import('./features/accounting/CierreMensualPage').then((m) => ({ default: m.CierreMensualPage })));
const AprobacionesPage = page(() => import('./features/approvals/AprobacionesPage').then((m) => ({ default: m.AprobacionesPage })));
const AuditPage = page(() => import('./features/audit/AuditPage').then((m) => ({ default: m.AuditPage })));
const OnboardingPage = page(() => import('./features/onboarding/OnboardingPage').then((m) => ({ default: m.OnboardingPage })));
const CatalogCenterPage = page(() => import('./features/catalogs/center/CatalogCenterPage').then((m) => ({ default: m.CatalogCenterPage })));
const PersonFilePage = page(() => import('./features/persons/PersonFilePage').then((m) => ({ default: m.PersonFilePage })));
const ImportAssetsPage = page(() => import('./features/import/ImportAssetsPage').then((m) => ({ default: m.ImportAssetsPage })));
const PageFallback = () => (<Box role="status" aria-live="polite" sx={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        minHeight: 320,
        opacity: 0,
        animation: 'fallbackFadeIn 160ms ease-out 120ms forwards',
        '@keyframes fallbackFadeIn': {
            from: { opacity: 0 },
            to: { opacity: 1 },
        },
        '@media (prefers-reduced-motion: reduce)': {
            animation: 'none',
            opacity: 1,
        },
    }}>
    <CircularProgress aria-label={i18next.t('common.loading')} />
  </Box>);
const RouteTransition = () => {
    const location = useLocation();
    // Al cambiar de pantalla, el foco pasa al contenido: quien usa el teclado no vuelve a recorrer el menú.
    const primera = useRef(true);
    useEffect(() => {
        if (primera.current) { primera.current = false; return; }
        document.getElementById('contenido')?.focus({ preventScroll: true });
    }, [location.pathname]);
    return (<Box key={location.pathname} sx={{
            minWidth: 0,
            animation: 'routeFadeIn 180ms ease-out',
            '@keyframes routeFadeIn': {
                from: { opacity: 0, transform: 'translateY(6px)' },
                to: { opacity: 1, transform: 'translateY(0)' },
            },
            '@media (prefers-reduced-motion: reduce)': {
                animation: 'none',
            },
        }}>
      <Outlet />
    </Box>);
};
const DashboardLayout = () => {
    const { t } = useTranslation();
    const { config } = useConfig();
    const { isAuthenticated, isLoading, restriction } = useAuth();
    useEffect(() => { if (isAuthenticated) prefetchPages(); }, [isAuthenticated]);
    const theme = useTheme();
    const isDesktop = useMediaQuery(theme.breakpoints.up('md'));
    const [mobileOpen, setMobileOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(() => {
        try {
            return window.localStorage.getItem('app-sidebar-collapsed') === 'true';
        }
        catch {
            return false;
        }
    });
    useEffect(() => {
        try {
            window.localStorage.setItem('app-sidebar-collapsed', collapsed ? 'true' : 'false');
        }
        catch { /* sin storage */ }
    }, [collapsed]);
    if (isLoading)
        return <PageFallback />;
    if (!isAuthenticated)
        return <Navigate to="/login" replace/>;
    // Paso de seguridad pendiente: pantalla completa en lugar del sistema.
    if (restriction)
        return <SecurityGate restriction={restriction}/>;
    return (<WorkspaceProvider>
      <LayoutShell isDesktop={isDesktop} collapsed={collapsed} onToggleCollapse={() => setCollapsed((c) => !c)}
        mobileOpen={mobileOpen} setMobileOpen={setMobileOpen} companyName={config.SYS_Nombre_Empresa} showNavLabel={t('common.show_navigation')}/>
    </WorkspaceProvider>);
};
interface LayoutShellProps {
    isDesktop: boolean;
    collapsed: boolean;
    onToggleCollapse: () => void;
    mobileOpen: boolean;
    setMobileOpen: (v: boolean) => void;
    companyName: string;
    showNavLabel: string;
}
const LayoutShell = ({ isDesktop, collapsed, onToggleCollapse, mobileOpen, setMobileOpen, companyName, showNavLabel }: LayoutShellProps) => {
    const { t } = useTranslation();
    const { abrirPaleta } = useWorkspace();
    return (<Box sx={{ display: 'flex', minHeight: '100vh', bgcolor: 'background.default' }}>
      {isDesktop
            ? <Sidebar variant="permanent" collapsed={collapsed} onToggleCollapse={onToggleCollapse}/>
            : <Sidebar variant="temporary" open={mobileOpen} onClose={() => setMobileOpen(false)}/>}

      {/* Barra superior solo en móvil/tablet: en escritorio la navegación es la barra lateral. */}
      {!isDesktop && (<AppBar position="fixed" color="inherit" elevation={0} sx={{
                bgcolor: 'background.paper', borderBottom: '1px solid', borderColor: 'divider',
            }}>
          <Toolbar variant="dense" sx={{ gap: 1, minHeight: 56 }}>
            <IconButton edge="start" onClick={() => setMobileOpen(true)} aria-label={showNavLabel}>
              <MenuIcon />
            </IconButton>
            <Typography variant="subtitle1" sx={{ fontWeight: 800, color: 'primary.main', flex: 1 }} noWrap>
              {companyName}
            </Typography>
            <IconButton onClick={abrirPaleta} aria-label={t('workspace.search')}>
              <SearchIcon />
            </IconButton>
          </Toolbar>
        </AppBar>)}

      <Box component="a" href="#contenido" sx={{
            position: 'absolute', left: 8, top: -48, zIndex: (th) => th.zIndex.tooltip, px: 2, py: 1, borderRadius: 1,
            bgcolor: 'background.paper', color: 'primary.main', '&:focus': { top: 8 },
        }}>{t('common.skip_to_content')}</Box>
      <Box component="main" id="contenido" tabIndex={-1} sx={{
            outline: 'none',
            flexGrow: 1,
            minWidth: 0,
            minHeight: '100vh',
            px: { xs: 2, sm: 3, lg: 4 },
            pt: { xs: 'calc(56px + 16px)', md: 3 },
            pb: 4,
        }}>
        <Box sx={{ maxWidth: 1600, mx: 'auto' }}>
          <MfaDueBanner />
          <SystemAlertsBanner />
          <RouteTransition />
        </Box>
      </Box>
    </Box>);
};
// El tablero es de inventario: el administrador de seguridad entra a Usuarios y accesos.
const HomeRoute = () => {
    const { user } = useAuth();
    // Administran el sistema: no ven datos de negocio.
    if (user?.role === 'ADMIN_SEGURIDAD' || user?.role === 'SUPER_ADMIN')
        return <Navigate to="/usuarios" replace/>;
    return <Navigate to="/inicio" replace/>;
};
// Wrapper que consume el ConfigContext y monta el ThemeProvider con los colores
const ThemedApp = () => {
    const { config, isLoaded } = useConfig();
    // Tema del usuario: claro, oscuro o el del sistema operativo.
    const [tema, setTemaEstado] = useState<TemaUsuario>(leerTemaLocal);
    const sistemaOscuro = useMediaQuery('(prefers-color-scheme: dark)');
    const oscuro = tema === 'oscuro' || (tema === 'sistema' && sistemaOscuro);
    const setTema = useCallback((t: TemaUsuario) => { setTemaEstado(t); guardarTemaLocal(t); }, []);
    const modo = useMemo(() => ({ tema, oscuro, setTema }), [tema, oscuro, setTema]);
    const { i18n: idiomaActual } = useTranslation();
    const idioma = idiomaActual.resolvedLanguage ?? 'es';
    const theme = useMemo(() => createTheme(createAppTheme(
        config.SYS_Color_Primario, config.SYS_Color_Secundario,
        fondoSegunTema(config.SYS_Color_Fondo, config.SYS_Color_Primario, oscuro),
    ), idioma === 'en' ? enUS : idioma === 'it' ? itIT : esES), [config.SYS_Color_Primario, config.SYS_Color_Secundario, config.SYS_Color_Fondo, oscuro, idioma]);
    if (!isLoaded) {
        // Bloqueo breve mientras el config llega: evita "flash" con colores default.
        return (<Box sx={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', bgcolor: oscuro ? '#14171f' : '#f5f3ef' }}>
        <CircularProgress aria-label={i18next.t('common.loading')} />
      </Box>);
    }
    return (<ThemeModeContext.Provider value={modo}>
    <ThemeProvider theme={theme}>
      <CssBaseline />
      <LocalizationProvider dateAdapter={AdapterDayjs} adapterLocale={idioma === 'en' ? 'en' : idioma === 'it' ? 'it' : 'es'}
        localeText={(idioma === 'en' ? pickersEn : idioma === 'it' ? pickersIt : pickersEs).components.MuiLocalizationProvider.defaultProps.localeText}>
      <SnackbarProvider maxSnack={3} anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}>
        <ConfirmProvider>
        <AuthProvider>
          <PreferenciasProvider>
          <BrowserRouter>
            <Suspense fallback={<PageFallback />}>
              <Routes>
                <Route path="/login" element={<LoginPage />}/>
                <Route path="/reset-password" element={<ResetPasswordPage />}/>
                <Route path="/sso/callback" element={<SsoCallbackPage />}/>

                <Route element={<DashboardLayout />}>
                  <Route path="/" element={<HomeRoute />}/>
                  <Route path="/inicio" element={<TaskHomePage />}/>

                  {/* Activos y consumibles: solo los roles del inventario. */}
                  <Route element={<ProtectedRoute allowedRoles={STOCK_ROLES}/>}>
                    <Route path="/panel" element={<DashboardPage />}/>
                    <Route path="/activos" element={<AssetsPage />}/>
                    <Route path="/activos/:codigo" element={<AssetsPage />}/>
                    <Route path="/consumibles" element={<ConsumablesPage />}/>
                  </Route>

                  {/* Operación del inventario: operadores, auditor y Consulta (las acciones se ocultan por rol). */}
                  <Route element={<ProtectedRoute allowedRoles={INVENTORY_ROLES}/>}>
                    <Route path="/scanner" element={<ScannerPage />}/>
                    <Route path="/operaciones/guia" element={<Navigate to="/operaciones/movimientos" replace/>}/>
                    <Route path="/operaciones/flujo" element={<Navigate to="/operaciones/movimientos" replace/>}/>
                    <Route path="/operaciones/movimientos" element={<MovementsPage />}/>
                    <Route path="/mantenimiento" element={<MaintenancePage />}/>
                    <Route path="/trazabilidad" element={<TrazabilidadPage />}/>
                    <Route path="/inventario/tomas" element={<TomasPage />}/>
                  </Route>

                  <Route element={<ProtectedRoute allowedRoles={ADMIN_READER_ROLES}/>}>
                    <Route path="/departamentos" element={<DepartmentsPage />}/>
                    <Route path="/empleados" element={<PersonsPage />}/>
                    <Route path="/empleados/:personaId" element={<PersonFilePage />}/>
                    <Route path="/aprobaciones" element={<AprobacionesPage />}/>
                  </Route>

                  <Route element={<ProtectedRoute allowedRoles={SOFTWARE_PAGE_ROLES}/>}>
                    <Route path="/software" element={<SoftwarePage />}/>
                  </Route>

                  {/* Cierre mensual: valores para Contabilidad (y TI y el auditor). */}
                  <Route element={<ProtectedRoute allowedRoles={GOODS_RECEIPT_ROLES}/>}>
                    <Route path="/ingresos" element={<GoodsReceiptsPage />}/>
                    <Route path="/ingresos/:id" element={<GoodsReceiptDetailPage />}/>
                  </Route>
                  <Route element={<ProtectedRoute allowedRoles={PURCHASE_WRITE_ROLES}/>}>
                    <Route path="/ingresos/nuevo" element={<GoodsReceiptFormPage />}/>
                  </Route>
                  <Route element={<ProtectedRoute allowedRoles={OPERATOR_ROLES}/>}>
                    <Route path="/ingresos/por-completar" element={<PendingUnitsPage />}/>
                  </Route>
                  <Route element={<ProtectedRoute allowedRoles={WARRANTY_ROLES}/>}>
                    <Route path="/compras/garantias" element={<WarrantiesPage />}/>
                  </Route>
                  <Route element={<ProtectedRoute allowedRoles={DIRECTORY_ROLES}/>}>
                    <Route path="/config/directorio" element={<DirectoryPage />}/>
                  </Route>
                  <Route element={<ProtectedRoute allowedRoles={FINANCE_EXPORT_ROLES}/>}>
                    <Route path="/contabilidad/cierre" element={<CierreMensualPage />}/>
                    <Route path="/compras/stock" element={<StockReportPage />}/>
                  </Route>

                  {/* Compras: TI, Compras y Contabilidad (lectura) y el auditor. */}
                  <Route element={<ProtectedRoute allowedRoles={PURCHASE_READ_ROLES}/>}>
                    <Route path="/compras/proveedores" element={<ProvidersPage />}/>
                  </Route>

                  <Route element={<ProtectedRoute allowedRoles={INVENTORY_ADMIN_ROLES}/>}>
                    <Route path="/operaciones/alta-empleado" element={<OnboardingPage />}/>
                    <Route path="/config/catalogos" element={<CatalogCenterPage />}/>
                    <Route path="/config/importar" element={<ImportAssetsPage />}/>
                    <Route path="/config/marcas" element={<Navigate to="/config/catalogos?c=marcas" replace />}/>
                    <Route path="/config/ubicaciones" element={<Navigate to="/config/catalogos?c=ubicaciones" replace />}/>
                    <Route path="/config/tipos" element={<Navigate to="/config/catalogos?c=tipos-activo" replace />}/>
                    <Route path="/config/modelos" element={<Navigate to="/config/catalogos?c=modelos" replace />}/>
                  </Route>

                  <Route element={<ProtectedRoute allowedRoles={IAM_ROLES}/>}>
                    <Route path="/usuarios" element={<UsersPage />}/>
                  </Route>

                  <Route element={<ProtectedRoute allowedRoles={AUDIT_READER_ROLES}/>}>
                    <Route path="/auditoria" element={<AuditPage />}/>
                  </Route>

                  <Route element={<ProtectedRoute allowedRoles={['SUPER_ADMIN']}/>}>
                    <Route path="/config" element={<ConfigPage />}/>
                  </Route>
                </Route>

                <Route path="*" element={<Navigate to="/" replace/>}/>
              </Routes>
            </Suspense>
          </BrowserRouter>
          </PreferenciasProvider>
        </AuthProvider>
        </ConfirmProvider>
      </SnackbarProvider>
    </LocalizationProvider>
    </ThemeProvider>
    </ThemeModeContext.Provider>);
};
function App() {
    return (<ErrorBoundary>
      <ConfigProvider>
        <ThemedApp />
      </ConfigProvider>
    </ErrorBoundary>);
}
export default App;
