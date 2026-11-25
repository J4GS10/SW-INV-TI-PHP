import { useEffect, useState } from 'react';
import { Box, Button, CircularProgress, DialogActions, DialogContent, DialogTitle, FormControlLabel, MenuItem, Switch, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { withRowVersion } from '../../api/rowVersion';
import { Dialog } from '../../components/ui/Dialog';
import { accountingService } from './accountingService';
import type { AccountingCategory, AccountingCategoryInput, DepreciationMethod } from './accountingService';

interface Props {
  open: boolean;
  category?: AccountingCategory;
  onClose: (saved?: boolean) => void;
}

const EMPTY: AccountingCategoryInput = {
  code: '', name: '', country: 'GT', method: 'STRAIGHT_LINE', annual_rate: '', residual_percent: '0', starts_next_month: true,
  account_code: null, legal_basis: null, active: true,
};

const RATE = /^\d{1,3}(\.\d{1,4})?$/;
const PERCENT = /^\d{1,3}(\.\d{1,2})?$/;

/** Vida útil que resulta de la tasa (33.33 % al año: 36 meses), igual que en el servidor. */
const lifeMonths = (rate: string): number | null => {
  const r = Number(rate);
  return RATE.test(rate) && r > 0 && r <= 100 ? Math.max(1, Math.round(1200 / r)) : null;
};

/**
 * Alta y edición de una categoría contable: método, tasa anual (la máxima legal del país), valor residual
 * y desde qué mes se deprecia. Una categoría no se borra: se desactiva y conserva su historia.
 */
export const AccountingCategoryDialog = ({ open, category, onClose }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [form, setForm] = useState<AccountingCategoryInput>(EMPTY);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!open) return;
    setForm(category ? {
      code: category.code, name: category.name, country: category.country, method: category.method, annual_rate: category.annual_rate,
      residual_percent: category.residual_percent, starts_next_month: category.starts_next_month, account_code: category.account_code,
      legal_basis: category.legal_basis, active: category.active,
    } : EMPTY);
  }, [open, category]);

  const set = <K extends keyof AccountingCategoryInput>(key: K, value: AccountingCategoryInput[K]) => setForm((f) => ({ ...f, [key]: value }));
  const depreciates = form.method === 'STRAIGHT_LINE';
  const errors = {
    code: !/^[A-Z0-9][A-Z0-9_]*$/.test(form.code) || form.code.length > 30,
    name: form.name.trim() === '' || form.name.length > 120,
    country: !/^[A-Z]{2}$/.test(form.country),
    annual_rate: depreciates && lifeMonths(form.annual_rate) === null,
    residual_percent: depreciates && (!PERCENT.test(form.residual_percent) || Number(form.residual_percent) > 100),
  };
  const valid = !Object.values(errors).some(Boolean);
  // En rojo solo lo que ya se escribió mal: un formulario nuevo no arranca lleno de errores (Guardar sigue apagado).
  const shown = (key: keyof typeof errors) => errors[key] && String(form[key] ?? '').trim() !== '';
  const life = depreciates ? lifeMonths(form.annual_rate) : null;

  const save = async () => {
    setSaving(true);
    const payload: AccountingCategoryInput = {
      ...form, name: form.name.trim(), account_code: form.account_code?.trim() || null, legal_basis: form.legal_basis?.trim() || null,
      annual_rate: depreciates ? form.annual_rate : '0', residual_percent: depreciates ? form.residual_percent : '0',
    };
    try {
      if (category) await accountingService.updateCategory(category.id, withRowVersion(payload, category));
      else await accountingService.createCategory(payload);
      enqueueSnackbar(t(category ? 'common.success_update' : 'accounting.categories.created'), { variant: 'success' });
      onClose(true);
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onClose={() => onClose()} maxWidth="sm" fullWidth>
      <DialogTitle>{t(category ? 'accounting.categories.edit' : 'accounting.categories.new')}</DialogTitle>
      <DialogContent>
        <Box sx={{ display: 'grid', gap: 2, pt: 1, gridTemplateColumns: { xs: '1fr', sm: '1fr 2fr' } }}>
          <TextField size="small" label={t('accounting.categories.country')} value={form.country} required
            onChange={(e) => set('country', e.target.value.toUpperCase().slice(0, 2))} error={shown('country')}
            helperText={t('accounting.categories.country_help')}/>
          <TextField size="small" label={t('accounting.categories.code')} value={form.code} required
            onChange={(e) => set('code', e.target.value.toUpperCase().replace(/\s/g, '_'))} error={shown('code')}
            helperText={t('accounting.categories.code_help')}/>
          <TextField size="small" label={t('accounting.categories.name')} value={form.name} required error={shown('name')}
            onChange={(e) => set('name', e.target.value)} sx={{ gridColumn: '1 / -1' }}/>
          <TextField select size="small" label={t('accounting.categories.method')} value={form.method}
            onChange={(e) => set('method', e.target.value as DepreciationMethod)} sx={{ gridColumn: '1 / -1' }}>
            {(['STRAIGHT_LINE', 'NONE'] as DepreciationMethod[]).map((m) => <MenuItem key={m} value={m}>{t(`accounting.methods.${m}`)}</MenuItem>)}
          </TextField>
          {depreciates && (<>
            <TextField size="small" label={t('accounting.categories.annual_rate')} value={form.annual_rate} required error={shown('annual_rate')}
              onChange={(e) => set('annual_rate', e.target.value.replace(',', '.'))}
              helperText={life ? t('accounting.categories.life', { count: life }) : t('accounting.categories.rate_help')}
              slotProps={{ htmlInput: { inputMode: 'decimal' } }}/>
            <TextField size="small" label={t('accounting.categories.residual')} value={form.residual_percent} error={shown('residual_percent')}
              onChange={(e) => set('residual_percent', e.target.value.replace(',', '.'))} helperText={t('accounting.categories.residual_help')}
              slotProps={{ htmlInput: { inputMode: 'decimal' } }}/>
            <FormControlLabel sx={{ gridColumn: '1 / -1' }} label={t('accounting.categories.starts_next_month')}
              control={<Switch checked={form.starts_next_month} onChange={(e) => set('starts_next_month', e.target.checked)}/>}/>
          </>)}
          <TextField size="small" label={t('accounting.categories.account_code')} value={form.account_code ?? ''}
            onChange={(e) => set('account_code', e.target.value)} helperText={t('accounting.categories.account_help')}/>
          <TextField size="small" label={t('accounting.categories.legal_basis')} value={form.legal_basis ?? ''}
            onChange={(e) => set('legal_basis', e.target.value)}/>
          {category && (
            <FormControlLabel sx={{ gridColumn: '1 / -1' }} label={t('accounting.categories.active')}
              control={<Switch checked={form.active ?? true} onChange={(e) => set('active', e.target.checked)}/>}/>
          )}
          {category && category.asset_types > 0 && (
            <Typography variant="caption" color="text.secondary" sx={{ gridColumn: '1 / -1' }}>
              {t('accounting.categories.in_use', { count: category.asset_types })}
            </Typography>
          )}
        </Box>
      </DialogContent>
      <DialogActions>
        <Button onClick={() => onClose()} disabled={saving}>{t('common.cancel')}</Button>
        <Button variant="contained" onClick={save} disabled={!valid || saving} startIcon={saving ? <CircularProgress size={16} color="inherit"/> : undefined}>
          {t('common.save')}
        </Button>
      </DialogActions>
    </Dialog>
  );
};
