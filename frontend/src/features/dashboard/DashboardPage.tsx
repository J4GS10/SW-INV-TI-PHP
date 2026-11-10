import { useEffect, useState } from 'react';
import { Box, Card, CardContent, Chip, CircularProgress, Divider, LinearProgress, List, ListItem, ListItemText, Typography, } from '@mui/material';
import BuildIcon from '@mui/icons-material/Build';
import ComputerIcon from '@mui/icons-material/Computer';
import DeleteSweepIcon from '@mui/icons-material/DeleteSweep';
import InventoryIcon from '@mui/icons-material/Inventory2';
import PaidIcon from '@mui/icons-material/Paid';
import WarningIcon from '@mui/icons-material/WarningAmber';
import { alpha, useTheme } from '@mui/material/styles';
import { useTranslation } from 'react-i18next';
import { stateLabel } from '../../utils/catalogLabels';
import client from '../../api/axios';
import { KpiCard, PageHeader } from '../../components/ui';
import { ensureReadable } from '../../theme/theme';
import type { PanelProps } from '../../types/ui';
import { formatMoneyTotals } from '../../utils/currency';
import { usePermissions } from '../../hooks/usePermissions';
import { DataQualityCard } from './DataQualityCard';
import { useLiveTick } from '../../hooks/useLiveRefresh';
interface DashboardDistributionItem {
    label: string;
    total: number;
}
interface DashboardWarrantyAlert {
    codigo: string;
    fin_garantia: string;
}
interface DashboardLicenseAlert {
    software: string;
    usadas: number;
    total: number;
}
interface DashboardStats {
    activos_totales?: number;
    disponibles_bodega?: number;
    en_reparacion?: number;
    mantenimientos_abiertos?: number;
    garantias_por_vencer_total?: number;
    garantias_vencidas?: number;
    /** Un total por moneda ({GTQ: 1500, USD: 80}); nunca se mezclan. */
    costo_inventario_por_moneda?: Record<string, number>;
    costo_mantenimiento_por_moneda?: Record<string, number>;
    en_baja?: number;
    licencias_disponibles?: number;
    licencias_usadas?: number;
    licencias_total?: number;
    por_estado?: Record<string, number>;
    por_tipo?: Array<{
        tipo: string;
        total: number;
    }>;
    por_departamento?: Array<{
        departamento: string;
        total: number;
    }>;
    garantias_por_vencer?: DashboardWarrantyAlert[];
    licencias_por_agotar?: DashboardLicenseAlert[];
}
const Panel = ({ title, children }: PanelProps) => (<Card sx={{ height: '100%' }}>
    <CardContent>
      <Typography variant="subtitle2" sx={{ mb: 1 }}>{title}</Typography>
      <Divider sx={{ mb: 1.5 }}/>
      {children}
    </CardContent>
  </Card>);
const Distribution = ({ items, max, emptyText, colorOf, }: {
    items: DashboardDistributionItem[];
    max: number;
    emptyText: string;
    colorOf?: (label: string) => string;
}) => {
    const theme = useTheme();
    const bg = theme.palette.background.default;
    return (<Box sx={{ display: 'flex', flexDirection: 'column', gap: 1.2 }}>
      {items.length === 0 && <Typography variant="body2" color="text.secondary">{emptyText}</Typography>}
      {items.map((item) => (<Box key={item.label}>
          <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 0.3, gap: 1 }}>
            <Typography variant="body2">{item.label}</Typography>
            <Typography variant="body2" fontWeight={600}>{item.total}</Typography>
          </Box>
          <LinearProgress variant="determinate" value={max ? (item.total / max) * 100 : 0} aria-label={`${item.label}: ${item.total}`} sx={{
                height: 7,
                borderRadius: 5,
                bgcolor: alpha(theme.palette.text.primary, 0.1),
                '& .MuiLinearProgress-bar': {
                    bgcolor: ensureReadable(colorOf ? colorOf(item.label) : theme.palette.primary.main, bg, 3),
                },
            }}/>
        </Box>))}
    </Box>);
};
const statusColor = (label: string) => {
    const key = (label || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    if (key === 'asignado' || key === 'assigned' || key === 'assegnato')
        return '#38bdf8';
    if (key === 'en bodega' || key === 'in storage' || key === 'in deposito')
        return '#4ade80';
    if (key.includes('reparaci') || key.includes('repair') || key.includes('riparazione'))
        return '#a78bfa';
    if (key === 'baja' || key === 'decommissioned' || key === 'dismesso')
        return '#f87171';
    return '#94a3b8';
};
export const DashboardPage = () => {
    const { t, i18n } = useTranslation();
    // Cada rol ve los indicadores de lo que le corresponde (el servidor tampoco
    // envía costos a quien no los ve ni licencias a quien no ve software).
    const { canSeeCosts, canSeeSoftware, canAdminInventory } = usePermissions();
    const [stats, setStats] = useState<DashboardStats | null>(null);
    const [loading, setLoading] = useState(true);
    const liveTick = useLiveTick();
    useEffect(() => {
        client.get('/stats/dashboard')
            .then(({ data }) => setStats(data))
            .catch(() => setStats({}))
            .finally(() => setLoading(false));
    }, [liveTick]);
    if (loading) {
        return (<Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '50vh' }}>
        <CircularProgress aria-label={t('common.loading')} sx={{ color: 'primary.main' }}/>
      </Box>);
    }
    const emptyText = t('dashboard.empty.no_data');
    const statusItems = Object.entries(stats?.por_estado || {}).map(([label, total]) => ({
        label,
        translatedLabel: stateLabel(t, label),
        total: total as number,
    }));
    const maxStatus = Math.max(1, ...statusItems.map((item) => item.total));
    const typeItems: DashboardDistributionItem[] = (stats?.por_tipo || []).map((item) => ({ label: item.tipo, total: item.total }));
    const maxType = Math.max(1, ...typeItems.map((item) => item.total));
    const departmentItems: DashboardDistributionItem[] = (stats?.por_departamento || []).map((item) => ({ label: item.departamento, total: item.total }));
    const maxDepartment = Math.max(1, ...departmentItems.map((item) => item.total));
    const language = i18n.resolvedLanguage ?? i18n.language;
    return (<Box sx={{ maxWidth: 1440, mx: 'auto' }}>
      <PageHeader
        title={t('dashboard.title')}
        subtitle={t('dashboard.subtitle')}
      />

      {canAdminInventory && <DataQualityCard />}

      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr 1fr', md: 'repeat(4, 1fr)' }, gap: 2, mb: 2 }}>
        <KpiCard title={t('dashboard.kpi.total_assets')} value={stats?.activos_totales ?? 0} icon={ComputerIcon} tone="primary"/>
        <KpiCard title={t('dashboard.kpi.available_storage')} value={stats?.disponibles_bodega ?? 0} icon={InventoryIcon} tone="success" subtitle={t('dashboard.kpi.ready_assign')}/>
        <KpiCard title={t('dashboard.kpi.repair')} value={stats?.en_reparacion ?? 0} icon={BuildIcon} tone="info" subtitle={t('dashboard.kpi.open_tickets', { count: stats?.mantenimientos_abiertos ?? 0 })}/>
        <KpiCard title={t('dashboard.kpi.warranties_expiring')} value={stats?.garantias_por_vencer_total ?? 0} icon={WarningIcon} tone="warning" subtitle={t('dashboard.kpi.next_90')}/>
      </Box>

      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr 1fr', md: 'repeat(4, 1fr)' }, gap: 2, mb: 3 }}>
        {canSeeCosts && <KpiCard title={t('dashboard.kpi.inventory_value')} value={formatMoneyTotals(stats?.costo_inventario_por_moneda, language)} icon={PaidIcon} tone="primary" subtitle={t('dashboard.kpi.not_decommissioned')}/>}
        {canSeeCosts && <KpiCard title={t('dashboard.kpi.maintenance_cost')} value={formatMoneyTotals(stats?.costo_mantenimiento_por_moneda, language)} icon={BuildIcon} tone="info" subtitle={t('dashboard.kpi.historical_total')}/>}
        <KpiCard title={t('dashboard.kpi.decommissioned')} value={stats?.en_baja ?? 0} icon={DeleteSweepIcon} tone="error"/>
        {canSeeSoftware && <KpiCard title={t('dashboard.kpi.free_licenses')} value={stats?.licencias_disponibles ?? 0} icon={InventoryIcon} tone="primary" subtitle={t('dashboard.kpi.licenses_in_use', { used: stats?.licencias_usadas ?? 0, total: stats?.licencias_total ?? 0 })}/>}
      </Box>

      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', md: '1fr 1fr 1fr' }, gap: 2.5, mb: 2.5 }}>
        <Panel title={t('dashboard.panels.by_status')}>
          <Distribution items={statusItems.map((item) => ({ label: item.translatedLabel, total: item.total }))} max={maxStatus} emptyText={emptyText} colorOf={statusColor}/>
        </Panel>
        <Panel title={t('dashboard.panels.by_type')}>
          <Distribution items={typeItems} max={maxType} emptyText={emptyText}/>
        </Panel>
        <Panel title={t('dashboard.panels.by_department')}>
          <Distribution items={departmentItems} max={maxDepartment} emptyText={emptyText}/>
        </Panel>
      </Box>

      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' }, gap: 2.5 }}>
        <Panel title={t('dashboard.panels.warranties_90')}>
          {(!stats?.garantias_por_vencer || stats.garantias_por_vencer.length === 0) ? (<Typography variant="body2" color="text.secondary">{t('dashboard.empty.no_warranties')}</Typography>) : (<List dense sx={{ maxHeight: 240, overflow: 'auto' }}>
              {stats.garantias_por_vencer.map((warranty) => (<ListItem key={warranty.codigo} secondaryAction={<Chip size="small" color="warning" label={warranty.fin_garantia}/>}>
                  <ListItemText primary={warranty.codigo}/>
                </ListItem>))}
            </List>)}
        </Panel>

        {canSeeSoftware && <Panel title={t('dashboard.panels.licenses_80')}>
          {(!stats?.licencias_por_agotar || stats.licencias_por_agotar.length === 0) ? (<Typography variant="body2" color="text.secondary">{t('dashboard.empty.no_licenses')}</Typography>) : (<List dense sx={{ maxHeight: 240, overflow: 'auto' }}>
              {stats.licencias_por_agotar.map((license, index) => (<ListItem key={`${license.software}-${index}`} secondaryAction={<Chip size="small" color="error" label={`${license.usadas}/${license.total}`}/>}>
                  <ListItemText primary={license.software}/>
                </ListItem>))}
            </List>)}
        </Panel>}
      </Box>
    </Box>);
};
