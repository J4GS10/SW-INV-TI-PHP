import { useEffect, useState } from 'react';
import { Box, Stack, Tab, TableCell, TableRow, Tabs } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { DataTableShell, PageHeader } from '../../components/ui';
import { SearchInput } from '../../components/SearchInput';
import { ExportMenuButton } from '../../components/ExportMenuButton';
import { downloadCsv } from '../../api/downloadCsv';
import { reportsService } from './reportsService';
import type { AssetStockRow, AssignmentRow, ConsumableStockRow, EntryRow } from './reportsService';
import { DateField } from '../../components/ui/DateField';
import { useLiveTick } from '../../hooks/useLiveRefresh';

type TabKey = 'stock' | 'entries' | 'assignments';

const fecha = (valor: string | null) => (valor ? new Date(valor).toLocaleDateString() : '—');
const monto = (valor: number | null, moneda: string | null) => (valor === null ? '—' : `${valor.toFixed(2)} ${moneda ?? ''}`.trim());

/** Stock por producto, entradas y asignaciones: solo lectura, para Compras y Contabilidad. */
export const StockReportPage = () => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [tab, setTab] = useState<TabKey>('stock');
  const [desde, setDesde] = useState('');
  const [hasta, setHasta] = useState('');
  const [loading, setLoading] = useState(true);
  const [assets, setAssets] = useState<AssetStockRow[]>([]);
  const [consumables, setConsumables] = useState<ConsumableStockRow[]>([]);
  const [entries, setEntries] = useState<EntryRow[]>([]);
  const [assignments, setAssignments] = useState<AssignmentRow[]>([]);
  const [busqueda, setBusqueda] = useState('');
  const coincide = (...campos: (string | null | undefined)[]) => {
    const texto = busqueda.trim().toLowerCase();
    return !texto || campos.some((c) => (c ?? '').toLowerCase().includes(texto));
  };

  const liveTick = useLiveTick();
  useEffect(() => {
    let active = true;
    const periodo = { desde: desde || undefined, hasta: hasta || undefined };
    const load = async () => {
      if (tab === 'stock') {
        const stock = await reportsService.stock();
        if (active) { setAssets(stock.assets); setConsumables(stock.consumables); }
      }
      else if (tab === 'entries') {
        const rows = await reportsService.entries(periodo);
        if (active) setEntries(rows);
      }
      else {
        const rows = await reportsService.assignments(periodo);
        if (active) setAssignments(rows);
      }
    };
    load()
      .catch(() => enqueueSnackbar(t('common.error_loading'), { variant: 'error' }))
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [tab, desde, hasta, enqueueSnackbar, t, liveTick]);

  return (<Box>
      <PageHeader title={t('stock_report.title')} subtitle={t('stock_report.subtitle')} actions={<ExportMenuButton onExport={(lang) => downloadCsv(`/reports/${tab === 'stock' ? 'stock' : tab === 'entries' ? 'entries' : 'assignments'}.csv?${new URLSearchParams(Object.entries(tab === 'stock' ? {} : { desde, hasta }).filter(([, v]) => v !== '')).toString()}`, `${tab}.csv`, lang)}/>}/>
      <Tabs value={tab} onChange={(_, value: TabKey) => { setLoading(true); setTab(value); }} sx={{ mb: 2 }} variant="scrollable">
        <Tab value="stock" label={t('stock_report.tab_stock')}/>
        <Tab value="entries" label={t('stock_report.tab_entries')}/>
        <Tab value="assignments" label={t('stock_report.tab_assignments')}/>
      </Tabs>

      <Box sx={{ mb: 2, maxWidth: 420 }}><SearchInput value={busqueda} onChange={setBusqueda}/></Box>
      {tab !== 'stock' && (<Stack direction="row" spacing={1.5} sx={{ mb: 2 }}>
          <DateField size="small" label={t('stock_report.from')} value={desde} onChange={(e) => { setLoading(true); setDesde(e.target.value); }} slotProps={{ inputLabel: { shrink: true } }}/>
          <DateField size="small" label={t('stock_report.to')} value={hasta} onChange={(e) => { setLoading(true); setHasta(e.target.value); }} slotProps={{ inputLabel: { shrink: true } }}/>
        </Stack>)}

      {tab === 'stock' && (<Stack spacing={3}>
          <DataTableShell
            columns={[
              { key: 'type', label: t('stock_report.type') },
              { key: 'product', label: t('stock_report.product') },
              { key: 'total', label: t('stock_report.total') },
              { key: 'available', label: t('stock_report.available') },
              { key: 'assigned', label: t('stock_report.assigned') },
              { key: 'last', label: t('stock_report.last_entry') },
            ]}
            rows={assets.filter((r) => coincide(r.type, r.brand, r.model))}
            loading={loading}
            emptyTitle={t('common.no_records')} emptyDescription={t('stock_report.empty_help')}
            renderRow={(r, i) => (<TableRow key={`${r.model_id}-${i}`}>
                <TableCell>{r.type ?? '—'}</TableCell>
                <TableCell>{`${r.brand ?? ''} ${r.model ?? ''}`.trim() || '—'}</TableCell>
                <TableCell>{r.total}</TableCell>
                <TableCell>{r.available}</TableCell>
                <TableCell>{r.assigned}</TableCell>
                <TableCell>{r.last_entry_at ?? '—'}</TableCell>
              </TableRow>)}
          />
          <DataTableShell
            columns={[
              { key: 'name', label: t('stock_report.consumable') },
              { key: 'stock', label: t('stock_report.stock') },
              { key: 'minimum', label: t('stock_report.minimum') },
              { key: 'cost', label: t('stock_report.unit_cost') },
              { key: 'last', label: t('stock_report.last_entry') },
            ]}
            rows={consumables.filter((r) => coincide(r.name))}
            loading={loading}
            emptyTitle={t('common.no_records')} emptyDescription={t('stock_report.empty_help')}
            renderRow={(r) => (<TableRow key={r.id}>
                <TableCell>{r.name}</TableCell>
                <TableCell>{r.stock}</TableCell>
                <TableCell>{r.minimum}</TableCell>
                <TableCell>{monto(r.unit_cost, r.currency)}</TableCell>
                <TableCell>{fecha(r.last_entry_at)}</TableCell>
              </TableRow>)}
          />
        </Stack>)}

      {tab === 'entries' && (<DataTableShell
          columns={[
            { key: 'date', label: t('stock_report.date') },
            { key: 'item', label: t('stock_report.product') },
            { key: 'quantity', label: t('stock_report.quantity') },
            { key: 'cost', label: t('stock_report.unit_cost') },
            { key: 'document', label: t('stock_report.document') },
          ]}
          rows={entries.filter((r) => coincide(r.item, r.document))}
          loading={loading}
          emptyTitle={t('common.no_records')} emptyDescription={t('stock_report.empty_help')}
          renderRow={(r, i) => (<TableRow key={`${r.date}-${i}`}>
              <TableCell>{fecha(r.date)}</TableCell>
              <TableCell>{r.item ?? '—'}</TableCell>
              <TableCell>{r.quantity ?? 1}</TableCell>
              <TableCell>{monto(r.unit_cost, r.currency)}</TableCell>
              <TableCell>{r.document ?? '—'}</TableCell>
            </TableRow>)}
        />)}

      {tab === 'assignments' && (<DataTableShell
          columns={[
            { key: 'assigned', label: t('stock_report.assigned_at') },
            { key: 'code', label: t('stock_report.asset') },
            { key: 'product', label: t('stock_report.product') },
            { key: 'person', label: t('stock_report.person') },
            { key: 'area', label: t('stock_report.area') },
            { key: 'returned', label: t('stock_report.returned_at') },
          ]}
          rows={assignments.filter((r) => coincide(r.asset_code, r.product, r.person, r.area))}
          loading={loading}
          emptyTitle={t('common.no_records')} emptyDescription={t('stock_report.empty_help')}
          renderRow={(r, i) => (<TableRow key={`${r.asset_code}-${i}`}>
              <TableCell>{fecha(r.assigned_at)}</TableCell>
              <TableCell>{r.asset_code}</TableCell>
              <TableCell>{r.product || '—'}</TableCell>
              <TableCell>{r.person || '—'}</TableCell>
              <TableCell>{r.area ?? '—'}</TableCell>
              <TableCell>{fecha(r.returned_at)}</TableCell>
            </TableRow>)}
        />)}
    </Box>);
};
