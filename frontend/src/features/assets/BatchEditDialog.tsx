import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Autocomplete, Box, Button, Checkbox, CircularProgress, DialogActions, DialogContent, DialogTitle, FormControlLabel, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import client from '../../api/axios';
import { getApiErrorMessage } from '../../api/errors';
import { MoneyField } from '../../components/ui';
import { DEFAULT_CURRENCY } from '../../utils/currency';
import { movementService } from '../operations/movementService';
import { procurementService } from '../procurement/procurementService';
import { assetService } from './assetService';
import type { Activo } from '../../types/inventory';
import type { Proveedor } from '../../types/procurement';
import type { TipoEspecificacion } from '../../types/catalogs';
import { Dialog } from '../../components/ui/Dialog';
import { DateField } from '../../components/ui/DateField';

type AreaRuta = { ARE_Area: number; ruta: string };
type Campo = 'area' | 'costo' | 'garantia' | 'compra' | 'especificacion';
interface Resultado { total: number; anteriores: Array<{ ACT_Activo: string; codigo: string; cambios: Record<string, unknown> }> }

interface Props {
  open: boolean;
  activos: Activo[];
  onClose: (changed: boolean) => void;
}

/**
 * Edición en lote: ubicación, costo y moneda, fin de garantía, proveedor y
 * factura, y una especificación, para todos los activos elegidos. Solo cambia
 * lo que se marca. Al terminar ofrece «Deshacer» durante 8 segundos.
 */
export const BatchEditDialog = ({ open, activos, onClose }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar, closeSnackbar } = useSnackbar();
  const [marcados, setMarcados] = useState<Set<Campo>>(new Set());
  const [areas, setAreas] = useState<AreaRuta[]>([]);
  const [proveedores, setProveedores] = useState<Proveedor[]>([]);
  const [tiposEsp, setTiposEsp] = useState<TipoEspecificacion[]>([]);
  const [area, setArea] = useState<AreaRuta | null>(null);
  const [costo, setCosto] = useState('');
  const [moneda, setMoneda] = useState<string>(DEFAULT_CURRENCY);
  const [garantia, setGarantia] = useState('');
  const [proveedor, setProveedor] = useState<number | ''>('');
  const [factura, setFactura] = useState('');
  const [tes, setTes] = useState<number | ''>('');
  const [valor, setValor] = useState('');
  const [enviando, setEnviando] = useState(false);

  useEffect(() => {
    if (!open) return;
    setMarcados(new Set());
    setArea(null); setCosto(''); setMoneda(DEFAULT_CURRENCY); setGarantia(''); setProveedor(''); setFactura('');
    setTes(''); setValor('');
    movementService.getAreas().then((a) => setAreas(a as AreaRuta[])).catch(() => setAreas([]));
    procurementService.listProveedores(true).then(setProveedores).catch(() => setProveedores([]));
    assetService.getTiposEspecificacion().then(setTiposEsp).catch(() => setTiposEsp([]));
  }, [open]);

  const marcar = (c: Campo, v: boolean) => setMarcados((prev) => {
    const n = new Set(prev);
    if (v) n.add(c); else n.delete(c);
    return n;
  });

  const cambios = useMemo(() => {
    const c: Record<string, unknown> = {};
    if (marcados.has('area')) c.ARE_Area_Ubicacion = area?.ARE_Area ?? null;
    if (marcados.has('costo')) { c.ACT_Costo = costo === '' ? null : costo; c.ACT_Moneda = moneda; }
    if (marcados.has('garantia')) c.ACT_Fin_Garantia = garantia || null;
    if (marcados.has('compra')) { c.PRV_Proveedor = proveedor === '' ? null : proveedor; c.ACT_Factura = factura.trim() || null; }
    if (marcados.has('especificacion') && tes !== '') c.especificaciones = [{ TES_Tipo_Especificacion: tes, valor: valor.trim() || null }];
    return c;
  }, [marcados, area, costo, moneda, garantia, proveedor, factura, tes, valor]);

  const deshacer = async (anteriores: Resultado['anteriores']) => {
    try {
      await client.post('/core/activos/lote/restaurar', {
        items: anteriores.filter((a) => Object.keys(a.cambios).length > 0)
          .map((a) => ({ ACT_Activo: a.ACT_Activo, cambios: a.cambios })),
      });
      enqueueSnackbar(t('batch_edit.undone'), { variant: 'info' });
      onClose(true);
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    }
  };

  const guardar = async () => {
    setEnviando(true);
    try {
      const r = (await client.patch<Resultado>('/core/activos/lote', {
        activos: activos.map((a) => a.ACT_Activo), cambios,
      })).data;
      const reversibles = r.anteriores.some((a) => Object.keys(a.cambios).length > 0);
      enqueueSnackbar(t('batch_edit.done', { count: r.total }), {
        variant: 'success', autoHideDuration: 8000,
        action: reversibles ? (key) => (
          <Button color="inherit" size="small" onClick={() => { closeSnackbar(key); void deshacer(r.anteriores); }}>
            {t('common.undo')}
          </Button>
        ) : undefined,
      });
      onClose(true);
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error', autoHideDuration: 8000 });
    } finally {
      setEnviando(false);
    }
  };

  const fila = (c: Campo, etiqueta: string, contenido: ReactNode) => (
    <Box>
      <FormControlLabel control={<Checkbox checked={marcados.has(c)} onChange={(e) => marcar(c, e.target.checked)} />}
        label={<Typography variant="subtitle2">{etiqueta}</Typography>} />
      {marcados.has(c) && <Box sx={{ pl: { xs: 0, sm: 4 }, pb: 1 }}>{contenido}</Box>}
    </Box>
  );

  return (
    <Dialog open={open} onClose={() => onClose(false)} maxWidth="sm" fullWidth>
      <DialogTitle>{t('batch_edit.title', { count: activos.length })}</DialogTitle>
      <DialogContent>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>{t('batch_edit.help')}</Typography>
        <Stack spacing={0.5}>
          {fila('area', t('batch_edit.location'), (
            <Autocomplete options={areas} value={area} onChange={(_e, v) => setArea(v)} getOptionLabel={(a) => a.ruta}
              isOptionEqualToValue={(a, b) => a.ARE_Area === b.ARE_Area}
              renderInput={(p) => <TextField {...p} size="small" label={t('batch_edit.area')} helperText={t('batch_edit.location_help')} />} />
          ))}
          {fila('costo', t('batch_edit.cost'), (
            <MoneyField fullWidth size="small" label={t('batch_edit.cost')} value={costo} onChange={setCosto}
              currency={moneda} onCurrencyChange={setMoneda} />
          ))}
          {fila('garantia', t('batch_edit.warranty'), (
            <DateField size="small" fullWidth label={t('batch_edit.warranty')} value={garantia}
              onChange={(e) => setGarantia(e.target.value)} InputLabelProps={{ shrink: true }} />
          ))}
          {fila('compra', t('batch_edit.purchase'), (
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5}>
              <TextField select size="small" fullWidth label={t('batch_edit.supplier')} value={proveedor}
                onChange={(e) => setProveedor(e.target.value === '' ? '' : Number(e.target.value))}>
                <MenuItem value="">—</MenuItem>
                {proveedores.map((p) => <MenuItem key={p.PRV_Proveedor} value={p.PRV_Proveedor}>{p.PRV_Nombre}</MenuItem>)}
              </TextField>
              <TextField size="small" fullWidth label={t('batch_edit.invoice')} value={factura}
                onChange={(e) => setFactura(e.target.value)} inputProps={{ maxLength: 60 }} />
            </Stack>
          ))}
          {fila('especificacion', t('batch_edit.specification'), (
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5}>
              <TextField select size="small" fullWidth label={t('batch_edit.specification')} value={tes}
                onChange={(e) => setTes(e.target.value === '' ? '' : Number(e.target.value))}>
                {tiposEsp.map((e) => <MenuItem key={e.TES_Tipo_Especificacion} value={e.TES_Tipo_Especificacion}>{e.TES_Nombre}</MenuItem>)}
              </TextField>
              <TextField size="small" fullWidth label={t('batch_edit.value')} value={valor}
                onChange={(e) => setValor(e.target.value)} helperText={t('batch_edit.value_help')} />
            </Stack>
          ))}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={() => onClose(false)}>{t('common.cancel')}</Button>
        <Button variant="contained" disabled={enviando || Object.keys(cambios).length === 0} onClick={() => void guardar()}>
          {enviando ? <CircularProgress size={22} /> : t('batch_edit.apply', { count: activos.length })}
        </Button>
      </DialogActions>
    </Dialog>
  );
};
