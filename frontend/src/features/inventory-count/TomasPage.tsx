import { useCallback, useEffect, useState } from 'react';
import { Box, Button, Chip, DialogActions, DialogContent, DialogTitle, MenuItem, Stack, Tab, TableCell, TableRow, Tabs, TextField } from '@mui/material';
import { useSearchParams } from 'react-router-dom';
import { FaltantesPanel } from './FaltantesPanel';
import AddIcon from '@mui/icons-material/Add';
import FactCheckIcon from '@mui/icons-material/FactCheck';
import { useTranslation } from 'react-i18next';
import { rowLink } from '../../components/ui/rowLink';
import { useSnackbar } from 'notistack';
import { DataTableShell, PageHeader } from '../../components/ui';
import { inventarioService } from '../../api/inventarioService';
import { getApiErrorMessage } from '../../api/errors';
import { movementService } from '../operations/movementService';
import { useSedes } from '../../hooks/useSedes';
import { usePermissions } from '../../hooks/usePermissions';
import type { Toma } from '../../types/kardex';
import { formatDateTimeValue } from '../../utils/dates';
import { TomaDialog } from './TomaDialog';
import { Dialog } from '../../components/ui/Dialog';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';

type AreaRuta = { ARE_Area: number; ruta: string; SED_Sede?: number | null };

/**
 * Toma de inventario físico: se recorre una sede o un área leyendo los QR y el
 * sistema concilia lo encontrado con lo registrado (faltantes, equipos en otra
 * ubicación o de otra sede, dados de baja y no registrados).
 */
export const TomasPage = () => {
  const { t, i18n } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const { canOperate } = usePermissions();
  const { sedes, unica } = useSedes();
  const [tomas, setTomas] = useState<Toma[]>([]);
  const [areas, setAreas] = useState<AreaRuta[]>([]);
  const [loading, setLoading] = useState(true);
  const [abierta, setAbierta] = useState<number | null>(null);
  const [nueva, setNueva] = useState(false);
  const [sede, setSede] = useState<number | ''>('');
  const [area, setArea] = useState<number | ''>('');
  const [observacion, setObservacion] = useState('');
  const [guardando, setGuardando] = useState(false);
  // «Faltantes»: lo que no apareció en las tomas, para investigar y resolver.
  const [params, setParams] = useSearchParams();
  const vista = params.get('vista') === 'faltantes' ? 'faltantes' : 'tomas';

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      const [ts, as] = await Promise.all([inventarioService.tomas(), movementService.getAreas()]);
      setTomas(ts);
      setAreas(as as AreaRuta[]);
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_loading')), { variant: 'error' });
    } finally {
      setLoading(false);
    }
  }, [enqueueSnackbar, t]);

  useEffect(() => { cargar(); }, [cargar]);
  useLiveRefresh(cargar);
  useEffect(() => { if (unica && sede === '') setSede(unica.SED_Sede); }, [sede, unica]);

  const nombreSede = (id: number) => sedes.find((s) => s.SED_Sede === id)?.SED_Nombre ?? `#${id}`;
  const nombreArea = (id?: number | null) => (id ? areas.find((a) => a.ARE_Area === id)?.ruta ?? `#${id}` : t('inventory_count.whole_site'));

  const crear = async () => {
    if (sede === '') return;
    setGuardando(true);
    try {
      const toma = await inventarioService.crearToma({ SED_Sede: sede, ARE_Area: area === '' ? null : area, TOM_Observacion: observacion || undefined });
      setNueva(false);
      setObservacion('');
      await cargar();
      setAbierta(toma.TOM_Toma);
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    } finally {
      setGuardando(false);
    }
  };

  return (
    <Box>
      <PageHeader
        title={t('inventory_count.title')}
        subtitle={t('inventory_count.subtitle')}
        badge={tomas.length}
        actions={canOperate && <Button variant="contained" startIcon={<AddIcon />} onClick={() => setNueva(true)}>{t('inventory_count.new')}</Button>}
      />
      <Tabs value={vista} onChange={(_, v) => setParams(v === 'faltantes' ? { vista: v } : {})} sx={{ mb: 2 }}>
        <Tab value="tomas" label={t('inventory_count.tab_counts')}/>
        <Tab value="faltantes" label={t('missing.tab')}/>
      </Tabs>
      {vista === 'faltantes' ? <FaltantesPanel areas={areas}/> : (
      <DataTableShell
        loading={loading}
        rows={tomas}
        emptyTitle={t('inventory_count.empty')}
        emptyDescription={t('inventory_count.empty_hint')}
        emptyIcon={FactCheckIcon}
        emptyAction={canOperate ? { label: t('inventory_count.new'), onClick: () => setNueva(true) } : undefined}
        columns={[
          { key: 'id', label: '#' },
          { key: 'sede', label: t('inventory_count.site') },
          { key: 'area', label: t('inventory_count.area') },
          { key: 'inicio', label: t('inventory_count.started') },
          { key: 'estado', label: t('inventory_count.state') },
          { key: 'acc', label: '', align: 'right' },
        ]}
        renderRow={(tm) => (
          <TableRow key={tm.TOM_Toma} hover sx={{ cursor: 'pointer' }} {...rowLink(() => setAbierta(tm.TOM_Toma))}>
            <TableCell>{tm.TOM_Toma}</TableCell>
            <TableCell>{nombreSede(tm.SED_Sede)}</TableCell>
            <TableCell>{nombreArea(tm.ARE_Area)}</TableCell>
            <TableCell>{formatDateTimeValue(tm.TOM_Fecha_Inicio, i18n.language)}</TableCell>
            <TableCell>
              <Chip size="small" label={t(`inventory_count.states.${tm.TOM_Estado}`)}
                color={tm.TOM_Estado === 'ABIERTA' ? 'primary' : tm.TOM_Estado === 'CERRADA' ? 'success' : tm.TOM_Estado === 'CERRANDO' ? 'warning' : 'default'} />
            </TableCell>
            <TableCell align="right">
              <Button size="small">{tm.TOM_Estado === 'ABIERTA' ? t('inventory_count.continue') : t('inventory_count.view')}</Button>
            </TableCell>
          </TableRow>
        )}
      />)}

      <Dialog open={nueva} onClose={() => setNueva(false)} maxWidth="sm" fullWidth>
        <DialogTitle>{t('inventory_count.new')}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField select required label={t('inventory_count.site')} value={sede}
              onChange={(e) => { setSede(Number(e.target.value)); setArea(''); }}>
              {sedes.map((s) => <MenuItem key={s.SED_Sede} value={s.SED_Sede}>{s.SED_Nombre}</MenuItem>)}
            </TextField>
            <TextField select label={t('inventory_count.area')} value={area} helperText={t('inventory_count.area_help')}
              onChange={(e) => setArea(e.target.value === '' ? '' : Number(e.target.value))}>
              <MenuItem value="">{t('inventory_count.whole_site')}</MenuItem>
              {areas.filter((a) => a.SED_Sede === sede).map((a) => <MenuItem key={a.ARE_Area} value={a.ARE_Area}>{a.ruta}</MenuItem>)}
            </TextField>
            <TextField label={t('inventory_count.note')} value={observacion} onChange={(e) => setObservacion(e.target.value)}
              multiline minRows={2} inputProps={{ maxLength: 500 }} />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setNueva(false)}>{t('common.cancel')}</Button>
          <Button variant="contained" onClick={crear} disabled={sede === '' || guardando}>{t('inventory_count.start')}</Button>
        </DialogActions>
      </Dialog>

      <TomaDialog tomaId={abierta} areas={areas} onClose={(changed) => { setAbierta(null); if (changed) cargar(); }} />
    </Box>
  );
};
