import { useEffect, useState } from 'react';
import { Button, CircularProgress, DialogActions, DialogContent, DialogTitle, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import client from '../../../api/axios';
import { getApiErrorMessage } from '../../../api/errors';
import { Dialog } from '../../../components/ui/Dialog';

interface SpecDefault {
  type_id: number;
  name: string;
  unit: string | null;
  data_type: 'TEXT' | 'NUMBER' | 'LIST' | 'BOOLEAN';
  min: number | null;
  max: number | null;
  options: string[];
  value: string | null;
}

interface Props {
  model: { id: number; name: string } | null;
  onClose: () => void;
}

const route = (id: number) => `/goods-receipts/catalog/models/${id}/spec-defaults`;

const errorOf = (spec: SpecDefault, value: string): 'not_a_number' | 'out_of_range' | null => {
  if (spec.data_type !== 'NUMBER' || value.trim() === '') return null;
  if (!/^\d+(\.\d+)?$/.test(value.trim())) return 'not_a_number';
  const number = Number(value);
  return (spec.min !== null && number < spec.min) || (spec.max !== null && number > spec.max) ? 'out_of_range' : null;
};

/** Factory values of a model: whoever completes a unit of this model finds them already filled in. */
export const ModelSpecDefaultsDialog = ({ model, onClose }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [specs, setSpecs] = useState<SpecDefault[] | null>(null);
  const [values, setValues] = useState<Record<number, string>>({});
  const [saving, setSaving] = useState(false);
  const modelId = model?.id;

  useEffect(() => {
    if (!modelId) return;
    setSpecs(null);
    client.get<SpecDefault[]>(route(modelId)).then((r) => {
      setSpecs(r.data);
      setValues(Object.fromEntries(r.data.map((s) => [s.type_id, s.value ?? ''])));
    }).catch((err) => {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_loading')), { variant: 'error' });
      setSpecs([]);
    });
  }, [modelId, enqueueSnackbar, t]);

  const invalid = (specs ?? []).some((s) => errorOf(s, values[s.type_id] ?? '') !== null);

  const save = async () => {
    if (!modelId) return;
    setSaving(true);
    try {
      await client.put(route(modelId), { values });
      enqueueSnackbar(t('catalogs.models.defaults_saved'), { variant: 'success' });
      onClose();
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    } finally {
      setSaving(false);
    }
  };

  return (<Dialog open={!!model} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>{t('catalogs.models.defaults_title', { model: model?.name ?? '' })}</DialogTitle>
      <DialogContent>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t('catalogs.models.defaults_help')}</Typography>
        {specs === null ? <CircularProgress aria-label={t('common.loading')}/> : specs.length === 0
          ? <Typography>{t('catalogs.models.defaults_empty')}</Typography>
          : (<Stack spacing={2}>
              {specs.map((spec) => {
                const value = values[spec.type_id] ?? '';
                const error = errorOf(spec, value);
                const list = spec.options.length > 0;
                return (<TextField key={spec.type_id} select={list} size="small" label={spec.unit ? `${spec.name} (${spec.unit})` : spec.name} value={value} error={!!error}
                    onChange={(e) => setValues((prev) => ({ ...prev, [spec.type_id]: e.target.value }))} inputProps={spec.data_type === 'NUMBER' ? { inputMode: 'decimal' } : undefined}
                    helperText={error ? t(`catalogs.models.defaults_${error}`, { min: spec.min, max: spec.max }) : undefined}>
                    {list && <MenuItem value="">—</MenuItem>}
                    {list && spec.options.map((o) => <MenuItem key={o} value={o}>{o}</MenuItem>)}
                  </TextField>);
              })}
            </Stack>)}
      </DialogContent>
      <DialogActions>
        <Button color="inherit" onClick={onClose}>{t('common.cancel')}</Button>
        <Button variant="contained" onClick={() => { void save(); }} disabled={saving || invalid || !specs?.length}>{saving ? <CircularProgress size={20}/> : t('common.save')}</Button>
      </DialogActions>
    </Dialog>);
};
