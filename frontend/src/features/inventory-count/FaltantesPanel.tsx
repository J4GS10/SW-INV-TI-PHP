import { useCallback, useEffect, useState } from 'react';
import { Autocomplete, Button, Chip, DialogActions, DialogContent, DialogTitle, FormControlLabel, MenuItem, Stack, Switch, TableCell, TableRow, TextField, Tooltip, Typography } from '@mui/material';
import SearchOffIcon from '@mui/icons-material/SearchOff';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { DataTableShell } from '../../components/ui';
import { inventarioService, type Faltante } from '../../api/inventarioService';
import { getApiErrorMessage } from '../../api/errors';
import { usePermissions } from '../../hooks/usePermissions';
import { formatDateTimeValue } from '../../utils/dates';
import { Dialog } from '../../components/ui/Dialog';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';

type AreaRuta = { ARE_Area: number; ruta: string };
type Accion = { tipo: 'nota' | 'localizado' | 'baja'; faltante: Faltante } | null;

const COLOR: Record<Faltante['FAL_Estado'], 'warning' | 'info' | 'success' | 'error' | 'default'> = {
  PENDIENTE: 'warning', EN_INVESTIGACION: 'info', LOCALIZADO: 'success', BAJA_SOLICITADA: 'error', DADO_DE_BAJA: 'default',
};

/**
 * Lo que no apareció en una toma: se investiga (notas con autor y fecha), se
 * marca como localizado (corrigiendo dónde estaba) o se pide su baja por
 * pérdida o robo, que aprueba otro administrador de TI. Si se lee en otra toma, se
 * localiza solo.
 */
export const FaltantesPanel = ({ areas }: { areas: AreaRuta[] }) => {
  const { t, i18n } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const { canOperate, canAdminInventory } = usePermissions();
  const [abiertos, setAbiertos] = useState(true);
  const [filas, setFilas] = useState<Faltante[]>([]);
  const [loading, setLoading] = useState(true);
  const [accion, setAccion] = useState<Accion>(null);
  const [nota, setNota] = useState('');
  const [area, setArea] = useState<AreaRuta | null>(null);
  const [motivoBaja, setMotivoBaja] = useState<'PERDIDA' | 'ROBO'>('PERDIDA');
  const [documento, setDocumento] = useState('');
  const [enviando, setEnviando] = useState(false);

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      setFilas(await inventarioService.faltantes({ abiertos: abiertos || undefined }));
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_loading')), { variant: 'error' });
    } finally {
      setLoading(false);
    }
  }, [abiertos, enqueueSnackbar, t]);

  useEffect(() => { cargar(); }, [cargar]);
  useLiveRefresh(cargar);

  const abrir = (tipo: 'nota' | 'localizado' | 'baja', faltante: Faltante) => {
    setNota('');
    setArea(null);
    setMotivoBaja('PERDIDA');
    setDocumento('');
    setAccion({ tipo, faltante });
  };

  const enviar = async () => {
    if (!accion) return;
    setEnviando(true);
    try {
      const id = accion.faltante.FAL_Faltante;
      if (accion.tipo === 'nota') await inventarioService.notaFaltante(id, nota.trim());
      else if (accion.tipo === 'localizado') await inventarioService.faltanteLocalizado(id, nota.trim(), area?.ARE_Area);
      else {
        await inventarioService.bajaFaltante(id, {
          motivo: motivoBaja, destino: 'NO_RECUPERADO', observacion: nota.trim(), documento: documento.trim() || undefined,
        });
      }
      enqueueSnackbar(t(`missing.done.${accion.tipo}`), { variant: accion.tipo === 'baja' ? 'info' : 'success' });
      setAccion(null);
      await cargar();
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    } finally {
      setEnviando(false);
    }
  };

  const abierto = (f: Faltante) => f.FAL_Estado === 'PENDIENTE' || f.FAL_Estado === 'EN_INVESTIGACION';
  return (
    <>
      <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 1.5 }}>
        <Typography variant="body2" color="text.secondary">{t('missing.help')}</Typography>
        <FormControlLabel control={<Switch checked={abiertos} onChange={(e) => setAbiertos(e.target.checked)}/>}
          label={t('missing.only_open')}/>
      </Stack>
      <DataTableShell
        loading={loading}
        rows={filas}
        emptyTitle={t(abiertos ? 'missing.empty_open' : 'missing.empty')}
        emptyIcon={SearchOffIcon}
        columns={[
          { key: 'codigo', label: t('assets.table.code') },
          { key: 'equipo', label: t('missing.asset') },
          { key: 'area', label: t('missing.last_area') },
          { key: 'fecha', label: t('missing.since') },
          { key: 'estado', label: t('inventory_count.state') },
          { key: 'seguimiento', label: t('missing.follow_up') },
          { key: 'acc', label: '', align: 'right' },
        ]}
        renderRow={(f) => {
          const ultima = f.FAL_Seguimiento[f.FAL_Seguimiento.length - 1];
          return (
            <TableRow key={f.FAL_Faltante} hover>
              <TableCell sx={{ fontWeight: 700 }}>{f.codigo}</TableCell>
              <TableCell>{`${f.tipo} · ${f.modelo}`}<Typography variant="caption" display="block" color="text.secondary">{f.serie}</Typography></TableCell>
              <TableCell>{f.area ?? '—'}</TableCell>
              <TableCell>{formatDateTimeValue(f.FAL_Fecha, i18n.language)}</TableCell>
              <TableCell><Chip size="small" color={COLOR[f.FAL_Estado]} label={t(`missing.states.${f.FAL_Estado}`)}/></TableCell>
              <TableCell sx={{ maxWidth: 260 }}>
                {ultima ? (
                  <Tooltip title={f.FAL_Seguimiento.map((s) => `${s.fecha} · ${s.usuario ?? '—'}: ${s.nota}`).join('\n')}>
                    <Typography variant="caption" noWrap display="block">{`${ultima.usuario ?? '—'}: ${ultima.nota}`}</Typography>
                  </Tooltip>
                ) : <Typography variant="caption" color="text.secondary">—</Typography>}
              </TableCell>
              <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                {canOperate && abierto(f) && <Button size="small" onClick={() => abrir('nota', f)}>{t('missing.add_note')}</Button>}
                {canOperate && abierto(f) && <Button size="small" color="success" onClick={() => abrir('localizado', f)}>{t('missing.found')}</Button>}
                {canAdminInventory && abierto(f) && <Button size="small" color="error" onClick={() => abrir('baja', f)}>{t('missing.write_off')}</Button>}
              </TableCell>
            </TableRow>
          );
        }}
      />

      <Dialog open={!!accion} onClose={() => setAccion(null)} maxWidth="sm" fullWidth>
        <DialogTitle>{accion ? t(`missing.titles.${accion.tipo}`, { code: accion.faltante.codigo }) : ''}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            {accion?.tipo === 'baja' && (
              <>
                <Typography variant="body2" color="text.secondary">{t('missing.write_off_help')}</Typography>
                <TextField select label={t('decommission.reason')} value={motivoBaja}
                  onChange={(e) => setMotivoBaja(e.target.value as 'PERDIDA' | 'ROBO')}>
                  <MenuItem value="PERDIDA">{t('catalog_items.motivos-baja.PERDIDA', 'Pérdida')}</MenuItem>
                  <MenuItem value="ROBO">{t('catalog_items.motivos-baja.ROBO', 'Robo')}</MenuItem>
                </TextField>
                <TextField label={t('decommission.document')} value={documento} onChange={(e) => setDocumento(e.target.value)}
                  helperText={t('missing.document_help')} inputProps={{ maxLength: 120 }}/>
              </>
            )}
            {accion?.tipo === 'localizado' && (
              <Autocomplete options={areas} value={area} onChange={(_, v) => setArea(v)} getOptionLabel={(a) => a.ruta}
                isOptionEqualToValue={(a, b) => a.ARE_Area === b.ARE_Area}
                renderInput={(p) => <TextField {...p} label={t('missing.found_area')} helperText={t('missing.found_area_help')}/>}/>
            )}
            <TextField autoFocus multiline minRows={2} label={t(accion?.tipo === 'nota' ? 'missing.note' : 'missing.explanation')}
              value={nota} onChange={(e) => setNota(e.target.value)} inputProps={{ maxLength: 500 }}/>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button color="inherit" onClick={() => setAccion(null)}>{t('common.cancel')}</Button>
          <Button variant="contained" color={accion?.tipo === 'baja' ? 'error' : 'primary'} onClick={enviar}
            disabled={enviando || nota.trim().length < (accion?.tipo === 'baja' ? 5 : 3)}>
            {accion ? t(`missing.submit.${accion.tipo}`) : ''}
          </Button>
        </DialogActions>
      </Dialog>
    </>
  );
};
