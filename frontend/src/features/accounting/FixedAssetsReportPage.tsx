import { Fragment, useCallback, useMemo, useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import {
  Alert, Box, CircularProgress, Link, MenuItem, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField,
  ToggleButton, ToggleButtonGroup, Typography,
} from '@mui/material';
import { useTranslation } from 'react-i18next';
import { PageHeader, SectionCard } from '../../components/ui';
import { ExportMenuButton } from '../../components/ExportMenuButton';
import { downloadCsv } from '../../api/downloadCsv';
import { useSedes } from '../../hooks/useSedes';
import { formatMoney } from '../../utils/currency';
import { accountingService } from './accountingService';
import type { CurrencyTotal, SummaryResult } from './accountingService';
import { AsOfField, CurrencyTotalsSummary, PendingNotice } from './valuation/ValuationParts';
import { useValuation } from './valuation/useValuation';

type GroupBy = 'category' | 'type' | 'person' | 'department' | 'site';

interface Group {
  key: string;
  /** null = el grupo de lo que no tiene valor (sin categoría, sin responsable…): va al final. */
  name: string | null;
  detail?: string | null;
  link?: string;
  totals: CurrencyTotal[];
}

/** Los grupos de la agrupación elegida, ordenados por nombre y con «sin…» al final. */
const groupsOf = (data: SummaryResult, by: GroupBy, rate: (r: string) => string): Group[] => {
  const groups: Group[] = (() => {
    switch (by) {
      case 'category': return data.by_category.map((g) => ({
        key: String(g.category?.id ?? 0), name: g.category?.name ?? null, totals: g.totals,
        detail: g.category ? [g.category.annual_rate ? rate(g.category.annual_rate) : null, g.category.account_code].filter(Boolean).join(' · ') : null,
      }));
      case 'type': return data.by_type.map((g) => ({ key: String(g.type_id), name: g.type, detail: g.category, totals: g.totals }));
      case 'person': return (data.by_person ?? []).map((g) => ({
        key: g.person_id ?? '-', name: g.person, detail: g.department, totals: g.totals, link: g.person_id ? `/empleados/${g.person_id}` : undefined,
      }));
      case 'department': return (data.by_department ?? []).map((g) => ({ key: String(g.department_id ?? 0), name: g.department, totals: g.totals }));
      default: return data.by_site.map((g) => ({ key: String(g.site_id ?? 0), name: g.site, totals: g.totals }));
    }
  })();
  return groups.sort((a, b) => (a.name === null ? 1 : b.name === null ? -1 : a.name.localeCompare(b.name)));
};

/** Una fila por grupo y moneda: cantidad, costo, depreciación acumulada y valor en libros. */
const GroupTable = ({ label, empty, groups }: { label: string; empty: string; groups: Group[] }) => {
  const { t, i18n } = useTranslation();
  const money = (v: string, c: string) => formatMoney(v, c, i18n.language);
  return (
    <TableContainer>
      <Table size="small">
        <TableHead>
          <TableRow>
            <TableCell>{label}</TableCell>
            <TableCell>{t('accounting.currency')}</TableCell>
            <TableCell align="right">{t('accounting.count')}</TableCell>
            <TableCell align="right">{t('accounting.cost')}</TableCell>
            <TableCell align="right">{t('accounting.accumulated')}</TableCell>
            <TableCell align="right">{t('accounting.book_value')}</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {groups.map((g) => (
            <Fragment key={g.key}>
              {g.totals.map((total, i) => (
                <TableRow key={`${g.key}-${total.currency}`} hover>
                  <TableCell sx={{ borderBottom: i < g.totals.length - 1 ? 'none' : undefined }}>
                    {i === 0 && (<>
                      <Typography variant="body2" fontWeight={600} color={g.name === null ? 'text.secondary' : undefined}>
                        {g.link ? <Link component={RouterLink} to={g.link} underline="hover">{g.name}</Link> : (g.name ?? empty)}
                      </Typography>
                      {g.detail && <Typography variant="caption" color="text.secondary">{g.detail}</Typography>}
                    </>)}
                  </TableCell>
                  <TableCell>{total.currency}</TableCell>
                  <TableCell align="right">{total.count}</TableCell>
                  <TableCell align="right">{money(total.acquisition_cost, total.currency)}</TableCell>
                  <TableCell align="right">{money(total.accumulated_depreciation, total.currency)}</TableCell>
                  <TableCell align="right"><Typography variant="body2" fontWeight={600}>{money(total.book_value, total.currency)}</Typography></TableCell>
                </TableRow>
              ))}
            </Fragment>
          ))}
        </TableBody>
      </Table>
    </TableContainer>
  );
};

/**
 * Cuadro de sumas de los activos fijos a una fecha: cuánto se invirtió, cuánto se ha depreciado y cuánto
 * vale hoy, agrupado por categoría contable, tipo, persona, departamento o sede, con el registro completo
 * en CSV para contabilidad.
 */
export const FixedAssetsReportPage = () => {
  const { t } = useTranslation();
  const { sedes } = useSedes();
  const [site, setSite] = useState<number | ''>('');
  const [by, setBy] = useState<GroupBy>('category');
  const load = useCallback((asOf: string) => accountingService.summary(asOf, site), [site]);
  const { asOf, setAsOf, data, loading, error } = useValuation(load);

  const options: GroupBy[] = data && data.by_person !== undefined ? ['category', 'type', 'person', 'department', 'site'] : ['category', 'type', 'site'];
  const groups = useMemo(() => (data ? groupsOf(data, by, (r) => t('accounting.rate_per_year', { rate: r })) : []), [data, by, t]);

  return (
    <Box>
      <PageHeader title={t('accounting.report_title')} subtitle={t('accounting.report_subtitle')}
        actions={<ExportMenuButton onExport={(lang) => downloadCsv(accountingService.registerPath(asOf, site), 'activos_fijos.csv', lang)}/>}/>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ mb: 2.5 }} alignItems={{ sm: 'flex-start' }}>
        <AsOfField value={asOf} onChange={setAsOf}/>
        <TextField select size="small" sx={{ minWidth: 240 }} label={t('accounting.site')} value={site}
          onChange={(e) => setSite(e.target.value === '' ? '' : Number(e.target.value))}
          slotProps={{ inputLabel: { shrink: true }, select: { displayEmpty: true } }}>
          <MenuItem value="">{t('accounting.site_all')}</MenuItem>
          {sedes.map((s) => <MenuItem key={s.SED_Sede} value={s.SED_Sede}>{s.SED_Nombre}</MenuItem>)}
        </TextField>
      </Stack>
      {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
      {loading && !data && <Box display="flex" justifyContent="center" py={4}><CircularProgress/></Box>}
      {data && (
        <Stack spacing={2.5}>
          <PendingNotice pending={data.pending}/>
          <CurrencyTotalsSummary totals={data.totals} title={t('accounting.books_total')}/>
          <SectionCard title={t('accounting.group_title')}
            actions={(
              <ToggleButtonGroup exclusive size="small" color="primary" value={options.includes(by) ? by : 'category'}
                onChange={(_, v: GroupBy | null) => v && setBy(v)} aria-label={t('accounting.group_by')}
                sx={{ flexWrap: 'wrap', '& .MuiToggleButton-root': { textTransform: 'none', px: 1.5 } }}>
                {options.map((o) => <ToggleButton key={o} value={o}>{t(`accounting.group.${o}`)}</ToggleButton>)}
              </ToggleButtonGroup>
            )}>
            {groups.length === 0
              ? <Typography variant="body2" color="text.secondary">{t('accounting.no_values')}</Typography>
              : <GroupTable label={t(`accounting.group.${options.includes(by) ? by : 'category'}`)} empty={t(`accounting.group_empty.${by}`)} groups={groups}/>}
          </SectionCard>
        </Stack>
      )}
    </Box>
  );
};
