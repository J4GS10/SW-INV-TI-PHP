import { useCallback, useEffect, useMemo, useState } from 'react';
import { Box, Button, Chip, IconButton, TableCell, TableRow, Tooltip, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import EditIcon from '@mui/icons-material/Edit';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { DataTableShell, PageHeader } from '../../components/ui';
import { useAuth } from '../../context/auth-context';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';
import { ACCOUNTING_CONFIG_ROLES } from '../../utils/roles';
import { AccountingCategoryDialog } from './AccountingCategoryDialog';
import { accountingService } from './accountingService';
import type { AccountingCategory } from './accountingService';

/**
 * Categorías contables de activo fijo. Vienen las tasas de Guatemala (Decreto 10-2012, art. 28) y cada
 * país agrega las suyas. Cada tipo de activo apunta a una; de ahí sale la depreciación de sus equipos.
 */
export const AccountingCategoriesPage = () => {
  const { t, i18n } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const { user } = useAuth();
  const canConfigure = !!user?.role && ACCOUNTING_CONFIG_ROLES.includes(user.role);
  const [rows, setRows] = useState<AccountingCategory[]>([]);
  const [loading, setLoading] = useState(true);
  const [editing, setEditing] = useState<AccountingCategory | 'new' | null>(null);

  const load = useCallback(async () => {
    try {
      setRows(await accountingService.categories());
    } catch {
      enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
    } finally {
      setLoading(false);
    }
  }, [enqueueSnackbar, t]);
  useEffect(() => { void load(); }, [load]);
  useLiveRefresh(load, { resources: ['accounting'], enabled: editing === null });
  // Por país y nombre con las reglas del idioma (la base ordena «Árboles» después de la «Z»); lo desactivado al final.
  const sorted = useMemo(() => [...rows].sort((a, b) => Number(b.active) - Number(a.active)
    || a.country.localeCompare(b.country) || a.name.localeCompare(b.name, i18n.language, { sensitivity: 'base' })), [rows, i18n.language]);

  return (
    <Box>
      <PageHeader title={t('accounting.categories.title')} subtitle={t('accounting.categories.subtitle')}
        actions={canConfigure ? <Button variant="contained" startIcon={<AddIcon/>} onClick={() => setEditing('new')}>{t('accounting.categories.new')}</Button> : undefined}/>
      <DataTableShell
        columns={[
          { key: 'country', label: t('accounting.categories.country'), width: 64 },
          { key: 'name', label: t('accounting.categories.name') },
          { key: 'method', label: t('accounting.categories.method') },
          { key: 'rate', label: t('accounting.categories.annual_rate'), align: 'right' as const },
          { key: 'life', label: t('accounting.categories.life_label'), align: 'right' as const },
          { key: 'account', label: t('accounting.categories.account_code') },
          { key: 'types', label: t('accounting.categories.types'), align: 'right' as const },
          { key: 'state', label: t('accounting.status_label') },
          ...(canConfigure ? [{ key: 'actions', label: '', align: 'right' as const }] : []),
        ]}
        rows={sorted}
        loading={loading}
        emptyTitle={t('common.no_records')}
        renderRow={(c) => (
          <TableRow key={c.id} hover>
            <TableCell>{c.country}</TableCell>
            <TableCell>
              {/* La base legal está en el formulario: aquí se repetiría igual en cada fila del mismo país. */}
              <Tooltip title={c.legal_basis ?? ''} placement="bottom-start" disableHoverListener={!c.legal_basis}>
                <Box>
                  <Typography variant="body2" fontWeight={600}>{c.name}</Typography>
                  <Typography variant="caption" color="text.secondary" sx={{ fontFamily: 'monospace' }}>{c.code}</Typography>
                </Box>
              </Tooltip>
            </TableCell>
            <TableCell sx={{ whiteSpace: 'nowrap' }}>{t(`accounting.methods.${c.method}`)}</TableCell>
            <TableCell align="right">
              {c.method === 'NONE' ? '—' : `${c.annual_rate} %`}
              {c.method !== 'NONE' && Number(c.residual_percent) > 0 && (
                <Typography variant="caption" color="text.secondary" component="div">{t('accounting.categories.residual_short', { value: c.residual_percent })}</Typography>
              )}
            </TableCell>
            <TableCell align="right">{c.useful_life_months ? t('accounting.months', { count: c.useful_life_months }) : '—'}</TableCell>
            <TableCell>{c.account_code ?? '—'}</TableCell>
            <TableCell align="right">{c.asset_types}</TableCell>
            <TableCell><Chip size="small" color={c.active ? 'success' : 'default'} variant="outlined" label={t(c.active ? 'accounting.categories.active' : 'accounting.categories.inactive')}/></TableCell>
            {canConfigure && (
              <TableCell align="right">
                <Tooltip title={t('common.edit')}>
                  <IconButton size="small" aria-label={t('common.edit')} onClick={() => setEditing(c)}><EditIcon fontSize="small"/></IconButton>
                </Tooltip>
              </TableCell>
            )}
          </TableRow>
        )}
      />
      <AccountingCategoryDialog open={editing !== null} category={editing === 'new' || editing === null ? undefined : editing}
        onClose={(saved) => { setEditing(null); if (saved) void load(); }}/>
    </Box>
  );
};
