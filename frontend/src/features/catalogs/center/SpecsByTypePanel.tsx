import { useEffect, useMemo, useState } from 'react';
import {
  Box, Button, IconButton, MenuItem, Stack, Switch, Table, TableBody, TableCell, TableHead,
  TableRow, TextField, Tooltip, Typography,
} from '@mui/material';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import DeleteIcon from '@mui/icons-material/DeleteOutline';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { catalogoConfigService } from '../../../api/catalogoService';
import { getApiErrorMessage } from '../../../api/errors';
import { catalogService } from '../catalogService';
import { AtomicValuesField } from '../../../components/AtomicValuesField';
import { usePermissions } from '../../../hooks/usePermissions';
import { typeLabel } from '../../../utils/catalogLabels';
import type { TipoActivo, TipoEspecificacion } from '../../../types/catalogs';
import { useLiveTick } from '../../../hooks/useLiveRefresh';

interface Fila {
  TES_Tipo_Especificacion: number;
  obligatoria: boolean;
  valores: string[];
}

/**
 * Qué especificaciones pide cada tipo de activo (Laptop → RAM, Almacenamiento…),
 * si son obligatorias y, opcionalmente, los valores permitidos.
 */
export const SpecsByTypePanel = () => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const { canAdminInventory } = usePermissions();
  const [tipos, setTipos] = useState<TipoActivo[]>([]);
  const [especificaciones, setEspecificaciones] = useState<TipoEspecificacion[]>([]);
  const [tipo, setTipo] = useState<number | ''>('');
  const [filas, setFilas] = useState<Fila[]>([]);
  const [sugerencias, setSugerencias] = useState<Map<number, string[]>>(new Map());
  const [original, setOriginal] = useState('[]');
  const [guardando, setGuardando] = useState(false);

  const liveTick = useLiveTick();
  useEffect(() => {
    Promise.all([catalogService.getTiposActivo(), catalogService.getTiposEspecificacion()])
      .then(([a, b]) => { setTipos(a); setEspecificaciones(b); })
      .catch((err) => enqueueSnackbar(getApiErrorMessage(err, t('common.error_loading')), { variant: 'error' }));
  }, [enqueueSnackbar, t, liveTick]);

  useEffect(() => {
    if (tipo === '') return;
    catalogoConfigService.especificacionesPorTipo().then((todas) => {
      // Lo que otros tipos ya aceptan se ofrece aquí: «DDR4» se escribe igual en todos.
      const usados = new Map<number, Set<string>>();
      Object.values(todas).flat().forEach((f) => {
        const lista = usados.get(f.TES_Tipo_Especificacion) ?? new Set<string>();
        f.valores.forEach((v) => lista.add(v));
        usados.set(f.TES_Tipo_Especificacion, lista);
      });
      setSugerencias(new Map([...usados].map(([id, lista]) => [id, [...lista]])));
      const actuales = (todas[String(tipo)] ?? []).map(({ TES_Tipo_Especificacion, obligatoria, valores }) => ({
        TES_Tipo_Especificacion, obligatoria, valores,
      }));
      setFilas(actuales);
      setOriginal(JSON.stringify(actuales));
    }).catch(() => setFilas([]));
  }, [tipo]);

  const nombre = useMemo(() => new Map(especificaciones.map((e) => [e.TES_Tipo_Especificacion, e])), [especificaciones]);
  const libres = especificaciones.filter((e) => !filas.some((f) => f.TES_Tipo_Especificacion === e.TES_Tipo_Especificacion));
  const cambiado = JSON.stringify(filas) !== original;

  const guardar = async () => {
    if (tipo === '') return;
    setGuardando(true);
    try {
      await catalogoConfigService.fijarEspecificaciones(tipo, filas);
      setOriginal(JSON.stringify(filas));
      enqueueSnackbar(t('common.success_update'), { variant: 'success' });
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    } finally {
      setGuardando(false);
    }
  };

  const mover = (i: number) => setFilas((prev) => {
    const copia = [...prev];
    [copia[i - 1], copia[i]] = [copia[i], copia[i - 1]];
    return copia;
  });

  return (
    <Box>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t('catalog_center.specs_help')}</Typography>
      <TextField select fullWidth label={t('catalog_center.asset_type')} value={tipo}
        onChange={(e) => setTipo(Number(e.target.value))} sx={{ mb: 2, maxWidth: 360 }}>
        {tipos.map((ta) => <MenuItem key={ta.TAC_Tipo_Activo} value={ta.TAC_Tipo_Activo}>{typeLabel(t, ta.TAC_Nombre)}</MenuItem>)}
      </TextField>

      {tipo !== '' && (
        <>
          <Box sx={{ overflowX: 'auto' }}>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>{t('catalog_center.specification')}</TableCell>
                  <TableCell align="center">{t('catalog_center.required')}</TableCell>
                  <TableCell>{t('catalog_center.allowed_values')}</TableCell>
                  <TableCell />
                </TableRow>
              </TableHead>
              <TableBody>
                {filas.length === 0 && (
                  <TableRow><TableCell colSpan={4}><Typography variant="body2" color="text.secondary">
                    {t('catalog_center.specs_empty')}
                  </Typography></TableCell></TableRow>
                )}
                {filas.map((f, i) => {
                  const e = nombre.get(f.TES_Tipo_Especificacion);
                  return (
                    <TableRow key={f.TES_Tipo_Especificacion}>
                      <TableCell>
                        <Typography variant="body2" fontWeight={600}>{e?.TES_Nombre ?? '—'}</Typography>
                        {e?.TES_Unidad_Medida && <Typography variant="caption" color="text.secondary">{e.TES_Unidad_Medida}</Typography>}
                      </TableCell>
                      <TableCell align="center">
                        <Switch size="small" checked={f.obligatoria} disabled={!canAdminInventory}
                          onChange={(ev) => setFilas((p) => p.map((x, j) => (j === i ? { ...x, obligatoria: ev.target.checked } : x)))} />
                      </TableCell>
                      <TableCell sx={{ minWidth: 220 }}>
                        <AtomicValuesField value={f.valores} disabled={!canAdminInventory} suggestions={sugerencias.get(f.TES_Tipo_Especificacion)}
                          placeholder={t('catalog_center.values_placeholder')} ariaLabel={`${t('catalog_center.allowed_values')}: ${e?.TES_Nombre ?? ''}`}
                          onChange={(v) => setFilas((p) => p.map((x, j) => (j === i ? { ...x, valores: v } : x)))} />
                      </TableCell>
                      <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                        {canAdminInventory && (
                          <>
                            <Tooltip title={t('catalog_center.move_up')}>
                              <span><IconButton size="small" disabled={i === 0} onClick={() => mover(i)}><ArrowUpwardIcon fontSize="small" /></IconButton></span>
                            </Tooltip>
                            <Tooltip title={t('common.delete')}>
                              <IconButton size="small" onClick={() => setFilas((p) => p.filter((_x, j) => j !== i))}>
                                <DeleteIcon fontSize="small" />
                              </IconButton>
                            </Tooltip>
                          </>
                        )}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </Box>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>{t('catalog_center.values_help')}</Typography>
          {canAdminInventory && (
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ mt: 2 }} alignItems={{ sm: 'center' }}>
              <TextField select size="small" label={t('catalog_center.add_spec')} value="" sx={{ minWidth: 240 }}
                onChange={(e) => setFilas((p) => [...p, { TES_Tipo_Especificacion: Number(e.target.value), obligatoria: false, valores: [] }])}>
                {libres.map((e) => <MenuItem key={e.TES_Tipo_Especificacion} value={e.TES_Tipo_Especificacion}>{e.TES_Nombre}</MenuItem>)}
              </TextField>
              <Box sx={{ flex: 1 }} />
              <Button variant="contained" onClick={() => void guardar()} disabled={!cambiado || guardando}>
                {t('common.save')}
              </Button>
            </Stack>
          )}
        </>
      )}
    </Box>
  );
};
