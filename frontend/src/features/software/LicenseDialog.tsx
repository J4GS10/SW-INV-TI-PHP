import { useEffect, useState } from 'react';
import { Box, Button, CircularProgress, DialogActions, DialogContent, DialogTitle, FormControlLabel, Stack, Switch, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import type { CatalogoItem } from '../../api/catalogoService';
import { softwareService } from './softwareService';
import type { CredencialIn, Licencia, LicenciaCreate, Software } from '../../types/software';
import { MoneyField, SectionCard } from '../../components/ui';
import { CatalogField } from '../../components/CatalogField';
import { useCatalogo } from '../../hooks/useCatalogo';
import { DEFAULT_CURRENCY } from '../../utils/currency';
import { CredentialsEditor } from './CredentialsEditor';
import { Dialog } from '../../components/ui/Dialog';
import { DateField } from '../../components/ui/DateField';

interface Props {
  open: boolean;
  softwarePreseleccionado?: Software | null;
  licenciaToEdit?: Licencia | null;
  onClose: (refresh?: boolean) => void;
}

/**
 * Alta y edición de una licencia con activación variable: modelo comercial,
 * método de activación (decide qué pide cada credencial), forma de conteo,
 * cupos, vigencia y, al crearla, sus credenciales (cuentas, seriales, keys…).
 */
export const LicenseDialog = ({ open, softwarePreseleccionado, licenciaToEdit, onClose }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const isEdit = !!licenciaToEdit;
  const { items: metodos } = useCatalogo('metodos-activacion');
  const { items: formas } = useCatalogo('formas-conteo');
  const [modelo, setModelo] = useState<number | null>(null);
  const [metodo, setMetodo] = useState<CatalogoItem | null>(null);
  const [forma, setForma] = useState<number | null>(null);
  const [total, setTotal] = useState('1');
  const [inicio, setInicio] = useState('');
  const [vence, setVence] = useState('');
  const [renovacion, setRenovacion] = useState(false);
  const [costo, setCosto] = useState('');
  const [moneda, setMoneda] = useState<string>(DEFAULT_CURRENCY);
  const [notas, setNotas] = useState('');
  const [credenciales, setCredenciales] = useState<CredencialIn[]>([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (!open) return;
    const l = licenciaToEdit;
    setModelo(l?.MCO_Modelo_Comercial ?? null);
    setForma(l?.FCO_Forma_Conteo ?? null);
    setTotal(String(l?.LIC_Cantidad_Total ?? 1));
    setInicio(l?.LIC_Fecha_Inicio ?? '');
    setVence(l?.LIC_Fecha_Vencimiento ?? '');
    setRenovacion(!!l?.LIC_Renovacion_Automatica);
    setCosto(l?.LIC_Costo != null ? String(l.LIC_Costo) : '');
    setMoneda(l?.LIC_Moneda ?? DEFAULT_CURRENCY);
    setNotas(l?.LIC_Notas ?? '');
    setCredenciales([]);
  }, [open, licenciaToEdit]);

  // El método se resuelve cuando el catálogo termina de cargar.
  useEffect(() => {
    if (open) setMetodo(metodos.find((m) => m.id === licenciaToEdit?.MAC_Metodo_Activacion) ?? null);
  }, [open, licenciaToEdit, metodos]);

  const porSitio = formas.find((f) => f.id === forma)?.codigo === 'SITIO';
  const metodoBloqueado = isEdit && (licenciaToEdit?.LIC_Claves_Total ?? 0) > 0;
  const valido = !!modelo && !!metodo && !!forma && Number(total) >= 1
    && (porSitio || credenciales.length <= Number(total));

  const guardar = async () => {
    setLoading(true);
    try {
      const payload: Omit<LicenciaCreate, 'SOF_Software'> = {
        MCO_Modelo_Comercial: modelo, MAC_Metodo_Activacion: metodo?.id ?? null, FCO_Forma_Conteo: forma,
        LIC_Cantidad_Total: Number(total), LIC_Fecha_Inicio: inicio || null, LIC_Renovacion_Automatica: renovacion,
        LIC_Notas: notas.trim() || null,
      };
      if (vence) payload.LIC_Fecha_Vencimiento = vence;
      if (costo !== '') {
        payload.LIC_Costo = costo;
        payload.LIC_Moneda = moneda;
      }
      if (isEdit && licenciaToEdit) {
        await softwareService.updateLicencia(licenciaToEdit.LIC_Licencia, payload);
        enqueueSnackbar(t('common.success_update'), { variant: 'success' });
      } else {
        if (!softwarePreseleccionado) return;
        await softwareService.createLicencia({
          ...payload, SOF_Software: softwarePreseleccionado.SOF_Software, LIC_Credenciales: credenciales,
        });
        enqueueSnackbar(t('software.license_added'), { variant: 'success' });
      }
      onClose(true);
    } catch (err: unknown) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error', autoHideDuration: 8000 });
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog open={open} onClose={() => onClose()} maxWidth="md" fullWidth>
      <DialogTitle>
        {isEdit ? t('software.edit_license') : t('software.add_license_for', { name: softwarePreseleccionado?.SOF_Nombre ?? '' })}
      </DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ mt: 1 }}>
          <SectionCard title={t('software.sections.licensing')}>
            <Stack spacing={2}>
              <CatalogField clave="modelos-comerciales" required label={t('software.commercial_model')}
                value={modelo} onChange={(v) => setModelo(v as number | null)} />
              <CatalogField clave="metodos-activacion" required label={t('software.activation_method')}
                value={metodo?.id ?? null} onChange={(_v, item) => { setMetodo(item); setCredenciales([]); }}
                disabled={metodoBloqueado} helperText={metodoBloqueado ? t('software.method_locked') : t('software.method_help')} />
              <CatalogField clave="formas-conteo" required allowCreate={false} label={t('software.counting_method')}
                value={forma} onChange={(v) => setForma(v as number | null)}
                helperText={t(`software.counting_help.${formas.find((f) => f.id === forma)?.codigo ?? 'NONE'}`)} />
            </Stack>
          </SectionCard>

          <SectionCard title={t('software.sections.validity')}>
            <Stack spacing={2}>
              <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: 'repeat(3, 1fr)' } }}>
                <TextField type="number" label={porSitio ? t('software.seats_reference') : t('software.seats')} required
                  value={total} onChange={(e) => setTotal(e.target.value)} inputProps={{ min: 1 }} />
                <DateField label={t('software.start_date')} value={inicio}
                  onChange={(e) => setInicio(e.target.value)} InputLabelProps={{ shrink: true }} />
                <DateField label={t('software.expiry')} value={vence}
                  onChange={(e) => setVence(e.target.value)} InputLabelProps={{ shrink: true }} />
              </Box>
              <FormControlLabel control={<Switch checked={renovacion} onChange={(e) => setRenovacion(e.target.checked)} />}
                label={t('software.auto_renewal')} />
              <MoneyField fullWidth label={t('software.license_cost')} helperText={t('software.license_cost_help')}
                value={costo} onChange={setCosto} currency={moneda} onCurrencyChange={setMoneda} />
              <TextField label={t('software.notes')} value={notas} onChange={(e) => setNotas(e.target.value)}
                multiline minRows={2} inputProps={{ maxLength: 1000 }} />
            </Stack>
          </SectionCard>

          {!isEdit && (
            <SectionCard title={t('software.sections.credentials')}>
              <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>{t('software.cred.intro')}</Typography>
              <CredentialsEditor metodo={metodo} value={credenciales} onChange={setCredenciales}
                max={porSitio ? undefined : Number(total) || 0} />
            </SectionCard>
          )}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={() => onClose()} color="inherit">{t('common.cancel')}</Button>
        <Button variant="contained" onClick={() => void guardar()} disabled={loading || !valido}>
          {loading ? <CircularProgress size={24} /> : t('common.save')}
        </Button>
      </DialogActions>
    </Dialog>
  );
};
