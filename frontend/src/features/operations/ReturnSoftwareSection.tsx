import {
  Alert, Box, Button, Checkbox, CircularProgress, FormControlLabel, Paper, Radio, RadioGroup, Stack, Typography,
} from '@mui/material';
import AppsIcon from '@mui/icons-material/Apps';
import { useTranslation } from 'react-i18next';
import type { SoftwareInstalado } from './useInstalledSoftware';

const nombre = (i: SoftwareInstalado): string => {
  const s = i.licencia?.software;
  return s ? [s.SOF_Nombre, s.SOF_Version].filter(Boolean).join(' ') : `#${i.LIC_Licencia}`;
};

interface Base {
  items: SoftwareInstalado[];
  loading: boolean;
  error: boolean;
  onRetry: () => void;
}

/** Marco común: título, qué significa liberar y el estado de la carga. */
const Marco = ({ items, loading, error, onRetry, vacio, children }: Base & { vacio: string; children: React.ReactNode }) => {
  const { t } = useTranslation();
  if (loading) {
    return <Stack direction="row" spacing={1} alignItems="center"><CircularProgress size={16} /><Typography variant="body2">{t('returns.software.loading')}</Typography></Stack>;
  }
  if (error) {
    return <Alert severity="error" action={<Button color="inherit" size="small" onClick={onRetry}>{t('common.retry')}</Button>}>{t('returns.software.load_error')}</Alert>;
  }
  // También se dice cuando no hay nada: así se ve que el software se revisó.
  if (items.length === 0) {
    return (
      <Stack direction="row" spacing={1} alignItems="center" role="status">
        <AppsIcon fontSize="small" color="action" />
        <Typography variant="body2" color="text.secondary">{vacio}</Typography>
      </Stack>
    );
  }
  return (
    <Paper variant="outlined" sx={{ p: 2 }} component="section" aria-label={t('returns.software.title')}>
      <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 0.5 }}>
        <AppsIcon fontSize="small" color="action" />
        <Typography variant="subtitle2">{t('returns.software.title')}</Typography>
      </Stack>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>{t('returns.software.help')}</Typography>
      {children}
    </Paper>
  );
};

/** Devolución de un equipo: cada licencia instalada se marca para liberarla o se deja con el equipo. */
export const ReturnSoftwareChoice = ({ selected, onChange, ...base }: Base & { selected: number[]; onChange: (ids: number[]) => void }) => {
  const { t, i18n } = useTranslation();
  const { items } = base;
  const alternar = (id: number) => onChange(selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id]);
  return (
    <Marco {...base} vacio={t('returns.software.empty')}>
      <Stack spacing={0.5}>
        {items.map((i) => (
          <FormControlLabel key={i.INS_Instalacion} sx={{ alignItems: 'flex-start', m: 0 }}
            control={<Checkbox size="small" sx={{ pt: 0.25 }} checked={selected.includes(i.INS_Instalacion)} onChange={() => alternar(i.INS_Instalacion)} />}
            label={(
              <Box>
                <Typography variant="body2" fontWeight={600}>{nombre(i)}</Typography>
                <Typography variant="caption" color="text.secondary">
                  {[i.licencia?.tipo_licencia?.TLI_Nombre, t('returns.software.since', { date: new Date(`${i.INS_Fecha_Instalacion}T00:00:00`).toLocaleDateString(i18n.language) })]
                    .filter(Boolean).join(' · ')}
                </Typography>
              </Box>
            )} />
        ))}
      </Stack>
      <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap sx={{ mt: 1 }}>
        <Typography variant="body2" sx={{ flex: 1, minWidth: 200 }} role="status">
          {t('returns.software.summary', { count: selected.length, total: items.length })}
        </Typography>
        <Button size="small" onClick={() => onChange(items.map((i) => i.INS_Instalacion))} disabled={selected.length === items.length}>{t('returns.software.all')}</Button>
        <Button size="small" onClick={() => onChange([])} disabled={selected.length === 0}>{t('returns.software.none')}</Button>
      </Stack>
    </Marco>
  );
};

/** Devolución en lote: lo instalado en los equipos se muestra y se decide una vez para todos. */
export const ReturnSoftwareBatchChoice = ({ value, onChange, ...base }: Base & { value: 'LIBERAR' | 'CONSERVAR' | null; onChange: (v: 'LIBERAR' | 'CONSERVAR') => void }) => {
  const { t } = useTranslation();
  const porEquipo = new Map<string, string[]>();
  base.items.forEach((i) => porEquipo.set(i.codigo, [...(porEquipo.get(i.codigo) ?? []), nombre(i)]));
  return (
    <Marco {...base} vacio={t('returns.software.empty_batch')}>
      <Stack spacing={0.5} sx={{ mb: 1.5 }}>
        {[...porEquipo].map(([codigo, nombres]) => (
          <Typography key={codigo} variant="body2"><strong>{codigo}</strong>: {nombres.join(', ')}</Typography>
        ))}
      </Stack>
      <Typography variant="body2" gutterBottom>{t('returns.software.batch_question', { count: base.items.length })}</Typography>
      <RadioGroup value={value ?? ''} onChange={(e) => onChange(e.target.value as 'LIBERAR' | 'CONSERVAR')}>
        <FormControlLabel value="LIBERAR" control={<Radio size="small" />} label={t('returns.software.batch_release')} />
        <FormControlLabel value="CONSERVAR" control={<Radio size="small" />} label={t('returns.software.batch_keep')} />
      </RadioGroup>
    </Marco>
  );
};
