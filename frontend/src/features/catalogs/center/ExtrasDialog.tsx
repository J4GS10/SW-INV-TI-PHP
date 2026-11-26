import { useEffect, useState } from 'react';
import {
  Box, Button, DialogActions, DialogContent, DialogTitle, Paper, Stack, Switch, TextField, Typography,
} from '@mui/material';
import { useTranslation } from 'react-i18next';
import type { CatalogoDef, CatalogoItem } from '../../../api/catalogoService';
import { Dialog } from '../../../components/ui/Dialog';
import { camposPedidos, type CampoPedido } from './camposPedidos';

interface Props {
  def: CatalogoDef;
  item: CatalogoItem | null;
  disabled?: boolean;
  onClose: () => void;
  onSave: (extras: Record<string, unknown>) => Promise<void>;
}

/**
 * Qué datos pide un registro de este tipo (p. ej. un método de activación «Cuenta» pide usuario y contraseña),
 * en una lista legible: cada dato con su explicación y, si se pide, el nombre que verá quien registra.
 * Abajo, cómo quedará el formulario.
 */
export const ExtrasDialog = ({ def, item, disabled = false, onClose, onSave }: Props) => {
  const { t } = useTranslation();
  const campos = camposPedidos(def);
  const [valores, setValores] = useState<Record<string, unknown>>({});
  const [guardando, setGuardando] = useState(false);
  useEffect(() => { setValores(item ? { ...item.extras } : {}); }, [item]);
  if (!item) return null;

  const nombreBase = (c: CampoPedido) => t(`catalog_center.requested.${c.clave}.name`, { defaultValue: c.clave });
  const nombreDe = (c: CampoPedido) => (c.etiqueta ? String(valores[c.etiqueta] ?? '').trim() : '') || nombreBase(c);
  const pedidos = campos.filter((c) => valores[c.pide] === true);
  const cambiado = campos.some((c) => valores[c.pide] !== item.extras[c.pide]
    || (c.etiqueta !== null && String(valores[c.etiqueta] ?? '') !== String(item.extras[c.etiqueta] ?? '')));

  const guardar = async () => {
    setGuardando(true);
    try {
      // Solo lo que cambió; una etiqueta vacía vuelve al nombre por defecto.
      const cambios: Record<string, unknown> = {};
      for (const c of campos) {
        if (valores[c.pide] !== item.extras[c.pide]) cambios[c.pide] = valores[c.pide] === true;
        if (c.etiqueta && String(valores[c.etiqueta] ?? '') !== String(item.extras[c.etiqueta] ?? '')) {
          cambios[c.etiqueta] = String(valores[c.etiqueta] ?? '').trim() || null;
        }
      }
      await onSave(cambios);
      onClose();
    } finally {
      setGuardando(false);
    }
  };

  return (
    <Dialog open onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>{t('catalog_center.requested.title', { name: item.nombre })}</DialogTitle>
      <DialogContent dividers>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t('catalog_center.requested.help')}</Typography>
        <Stack spacing={1}>
          {campos.map((c) => {
            const activo = valores[c.pide] === true;
            return (
              <Paper key={c.clave} variant="outlined" sx={{ p: 1.5, borderColor: activo ? 'primary.main' : 'divider' }}>
                <Stack direction="row" spacing={1.5} alignItems="flex-start">
                  <Switch checked={activo} disabled={disabled} onChange={(e) => setValores((v) => ({ ...v, [c.pide]: e.target.checked }))}
                    slotProps={{ input: { 'aria-label': nombreBase(c) } }} />
                  <Box sx={{ flex: 1, minWidth: 0 }}>
                    <Typography variant="body2" fontWeight={600}>{nombreBase(c)}</Typography>
                    <Typography variant="caption" color="text.secondary">{t(`catalog_center.requested.${c.clave}.help`, { defaultValue: '' })}</Typography>
                    {activo && c.etiqueta && (
                      <TextField size="small" fullWidth sx={{ mt: 1 }} disabled={disabled} value={String(valores[c.etiqueta] ?? '')}
                        label={t('catalog_center.requested.label')} placeholder={nombreBase(c)}
                        helperText={t('catalog_center.requested.label_help', { name: nombreBase(c) })} slotProps={{ htmlInput: { maxLength: 60 } }}
                        onChange={(e) => setValores((v) => ({ ...v, [c.etiqueta as string]: e.target.value }))} />
                    )}
                  </Box>
                </Stack>
              </Paper>
            );
          })}
        </Stack>
        <Box sx={{ mt: 2, p: 1.5, bgcolor: 'action.hover', borderRadius: 1 }}>
          <Typography variant="overline" color="text.secondary">{t('catalog_center.requested.preview')}</Typography>
          {pedidos.length === 0 ? (
            <Typography variant="body2" color="text.secondary">{t('catalog_center.requested.preview_none')}</Typography>
          ) : (
            <Stack spacing={1} sx={{ mt: 0.5 }}>
              {pedidos.map((c) => <TextField key={c.clave} size="small" fullWidth disabled label={nombreDe(c)} />)}
            </Stack>
          )}
        </Box>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} color="inherit">{t('common.cancel')}</Button>
        {!disabled && <Button variant="contained" disabled={!cambiado || guardando} onClick={() => { void guardar(); }}>{t('common.save')}</Button>}
      </DialogActions>
    </Dialog>
  );
};
