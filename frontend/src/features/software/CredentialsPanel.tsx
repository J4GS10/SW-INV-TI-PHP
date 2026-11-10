import { useCallback, useEffect, useMemo, useState } from 'react';
import { Alert, Box, Button, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, IconButton, InputAdornment, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Tooltip, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import AutorenewIcon from '@mui/icons-material/Autorenew';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import KeyIcon from '@mui/icons-material/Key';
import PauseCircleIcon from '@mui/icons-material/PauseCircleOutline';
import PlayCircleIcon from '@mui/icons-material/PlayCircleOutline';
import VisibilityIcon from '@mui/icons-material/Visibility';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { useCatalogo } from '../../hooks/useCatalogo';
import { usePermissions } from '../../hooks/usePermissions';
import { formatDate } from '../../utils/dates';
import type { Credencial, CredencialIn, Licencia } from '../../types/software';
import { softwareService } from './softwareService';
import { CredentialsEditor } from './CredentialsEditor';
import { camposDelMetodo, etiquetaCampo, generarSecreto } from './credenciales';
import { Dialog } from '../../components/ui/Dialog';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';

interface Props {
  licencia: Licencia;
  onChanged: () => void;
}

const COLOR: Record<Credencial['estado'], 'success' | 'warning' | 'default' | 'error'> = {
  DISPONIBLE: 'success', ASIGNADA: 'warning', SUSPENDIDA: 'default', BAJA: 'error', RETIRADA: 'default',
};

/**
 * Credenciales o cupos de una licencia: quién tiene cada una, su vencimiento
 * y las acciones «Mostrar» (se oculta a los 30 s y queda registrado), «Copiar»,
 * «Rotar» y suspender o reactivar.
 */
export const CredentialsPanel = ({ licencia, onChanged }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const { canAdminInventory } = usePermissions();
  const { items: metodos } = useCatalogo('metodos-activacion');
  const metodo = metodos.find((m) => m.id === licencia.MAC_Metodo_Activacion) ?? null;
  const campos = useMemo(() => camposDelMetodo(metodo), [metodo]);
  const conSecreto = campos.includes('secreto') || campos.includes('clave') || !metodo;
  const [filas, setFilas] = useState<Credencial[]>([]);
  const [loading, setLoading] = useState(true);
  const [visibles, setVisibles] = useState<Record<number, { valor: string; hasta: number }>>({});
  const [ahora, setAhora] = useState(() => Date.now());
  const [agregando, setAgregando] = useState(false);
  const [nuevas, setNuevas] = useState<CredencialIn[]>([]);
  const [rotando, setRotando] = useState<Credencial | null>(null);
  const [nuevoSecreto, setNuevoSecreto] = useState('');

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      setFilas(await softwareService.getCredenciales(licencia.LIC_Licencia));
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('software.keys_load_error')), { variant: 'error' });
    } finally {
      setLoading(false);
    }
  }, [licencia.LIC_Licencia, enqueueSnackbar, t]);

  useEffect(() => { void cargar(); }, [cargar]);
  useLiveRefresh(cargar);

  // Los secretos mostrados se ocultan solos a los 30 s.
  const hayVisibles = Object.keys(visibles).length > 0;
  useEffect(() => {
    if (!hayVisibles) return undefined;
    const id = window.setInterval(() => {
      const t0 = Date.now();
      setAhora(t0);
      setVisibles((v) => Object.fromEntries(Object.entries(v).filter(([, x]) => x.hasta > t0)));
    }, 1000);
    return () => window.clearInterval(id);
  }, [hayVisibles]);

  const revelar = async (c: Credencial, copiar: boolean) => {
    try {
      const r = await softwareService.revelarCredencial(c.LCL_Licencia_Clave);
      const valor = r.secreto ?? r.clave ?? '';
      if (copiar) {
        await navigator.clipboard.writeText(valor);
        enqueueSnackbar(t('software.cred.copied_logged'), { variant: 'success' });
      } else {
        setVisibles((v) => ({ ...v, [c.LCL_Licencia_Clave]: { valor, hasta: Date.now() + r.ocultar_en_segundos * 1000 } }));
        enqueueSnackbar(t('software.cred.shown_logged'), { variant: 'info' });
      }
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
    }
  };

  const cambiarEstado = async (c: Credencial, estado: 'DISPONIBLE' | 'SUSPENDIDA') => {
    try {
      await softwareService.editarCredencial(c.LCL_Licencia_Clave, { estado });
      await cargar();
      onChanged();
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    }
  };

  const guardarNuevas = async () => {
    try {
      await softwareService.crearCredenciales(licencia.LIC_Licencia, nuevas);
      enqueueSnackbar(t('software.cred.added', { count: nuevas.length }), { variant: 'success' });
      setAgregando(false);
      setNuevas([]);
      await cargar();
      onChanged();
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error', autoHideDuration: 8000 });
    }
  };

  const campoRotar = campos.includes('secreto') ? 'secreto' : 'clave';
  const rotar = async () => {
    if (!rotando || !nuevoSecreto.trim()) return;
    try {
      await softwareService.rotarCredencial(rotando.LCL_Licencia_Clave, { [campoRotar]: nuevoSecreto.trim() });
      enqueueSnackbar(t('software.cred.rotated'), { variant: 'success' });
      setRotando(null);
      setNuevoSecreto('');
      await cargar();
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    }
  };

  const identificador = (c: Credencial) =>
    [c.usuario, c.serial, c.servidor, c.dispositivo, c.archivo?.nombre, c.clave_mascara].filter(Boolean).join(' · ') || '—';

  return (
    <Box>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1} alignItems={{ sm: 'center' }} justifyContent="space-between" sx={{ mb: 1 }}>
        <Typography variant="subtitle2" sx={{ fontWeight: 700 }}>{t('software.cred.title')}</Typography>
        {canAdminInventory && campos.length > 0 && (
          <Button size="small" startIcon={<AddIcon />} onClick={() => setAgregando(true)}>{t('software.cred.add')}</Button>
        )}
      </Stack>
      {loading ? <CircularProgress size={18} /> : filas.length === 0 ? (
        <Typography variant="body2" color="text.secondary">{t('software.no_keys')}</Typography>
      ) : (
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{t('software.cred.identifier')}</TableCell>
                <TableCell>{t('software.key_status')}</TableCell>
                <TableCell>{t('software.key_assignment')}</TableCell>
                <TableCell>{t('software.expiry')}</TableCell>
                <TableCell align="right">{t('common.actions')}</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {filas.map((c) => {
                const visible = visibles[c.LCL_Licencia_Clave];
                return (
                  <TableRow key={c.LCL_Licencia_Clave}>
                    <TableCell sx={{ minWidth: 220 }}>
                      <Typography variant="body2" sx={{ fontWeight: 600, wordBreak: 'break-all' }}>{identificador(c)}</Typography>
                      {c.referencia && <Typography variant="caption" color="text.secondary">{c.referencia}</Typography>}
                      {visible && (
                        <Alert severity="info" icon={<KeyIcon fontSize="small" />} sx={{ mt: 0.5, py: 0 }}>
                          <Box component="span" sx={{ fontFamily: 'monospace', wordBreak: 'break-all' }}>{visible.valor}</Box>
                          <Typography variant="caption" display="block" color="text.secondary">
                            {t('software.cred.hides_in', { seconds: Math.max(0, Math.ceil((visible.hasta - ahora) / 1000)) })}
                          </Typography>
                        </Alert>
                      )}
                      <Stack direction="row" spacing={0.5} sx={{ mt: 0.5 }} flexWrap="wrap" useFlexGap>
                        {c.requiere_cambio && <Chip size="small" color="error" label={t('software.cred.must_change')} />}
                        {c.sin_rotar && <Chip size="small" color="warning" variant="outlined" label={t('software.cred.not_rotated')} />}
                      </Stack>
                    </TableCell>
                    <TableCell>
                      <Chip size="small" variant="outlined" color={COLOR[c.estado]} label={t(`software.key_status_${c.estado}`)} />
                    </TableCell>
                    <TableCell sx={{ minWidth: 160 }}>
                      {c.destino ? (
                        <Stack spacing={0.25}>
                          <Typography variant="body2">{c.destino.nombre}</Typography>
                          <Typography variant="caption" color="text.secondary">
                            {c.destino.tipo === 'PERSONA' ? t('software.assigned_person') : t('software.assigned_asset')}
                            {c.asignada_en ? ` · ${formatDate(c.asignada_en)}` : ''}
                          </Typography>
                        </Stack>
                      ) : <Typography variant="body2" color="text.secondary">{t('software.key_unassigned')}</Typography>}
                    </TableCell>
                    <TableCell>{c.vence ? formatDate(c.vence) : '—'}</TableCell>
                    <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                      {canAdminInventory && conSecreto && (c.tiene_secreto || c.tiene_clave) && !c.legada && (
                        <>
                          <Tooltip title={t('software.cred.show')}>
                            <IconButton size="small" aria-label={t('software.cred.show')} onClick={() => void revelar(c, false)}>
                              <VisibilityIcon fontSize="small" />
                            </IconButton>
                          </Tooltip>
                          <Tooltip title={t('software.copy_key')}>
                            <IconButton size="small" aria-label={t('software.copy_key')} onClick={() => void revelar(c, true)}>
                              <ContentCopyIcon fontSize="small" />
                            </IconButton>
                          </Tooltip>
                          <Tooltip title={t('software.cred.rotate')}>
                            <IconButton size="small" aria-label={t('software.cred.rotate')} onClick={() => { setRotando(c); setNuevoSecreto(''); }}>
                              <AutorenewIcon fontSize="small" />
                            </IconButton>
                          </Tooltip>
                        </>
                      )}
                      {canAdminInventory && !c.legada && c.estado === 'DISPONIBLE' && (
                        <Tooltip title={t('software.cred.suspend')}>
                          <IconButton size="small" aria-label={t('software.cred.suspend')} onClick={() => void cambiarEstado(c, 'SUSPENDIDA')}>
                            <PauseCircleIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                      )}
                      {canAdminInventory && c.estado === 'SUSPENDIDA' && (
                        <Tooltip title={t('software.cred.reactivate')}>
                          <IconButton size="small" aria-label={t('software.cred.reactivate')} onClick={() => void cambiarEstado(c, 'DISPONIBLE')}>
                            <PlayCircleIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                      )}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </Box>
      )}

      <Dialog open={agregando} onClose={() => setAgregando(false)} maxWidth="sm" fullWidth>
        <DialogTitle>{t('software.cred.add')}</DialogTitle>
        <DialogContent>
          <Box sx={{ mt: 1 }}>
            <CredentialsEditor metodo={metodo} value={nuevas} onChange={setNuevas}
              onUploadFile={async (file) => (await softwareService.subirArchivoLicencia(licencia.LIC_Licencia, file)).ADJ_Adjunto} />
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setAgregando(false)}>{t('common.cancel')}</Button>
          <Button variant="contained" disabled={nuevas.length === 0} onClick={() => void guardarNuevas()}>
            {t('software.cred.save_count', { count: nuevas.length })}
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={!!rotando} onClose={() => setRotando(null)} maxWidth="xs" fullWidth>
        <DialogTitle>{t('software.cred.rotate_title', { name: rotando ? identificador(rotando) : '' })}</DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2, mt: 1 }}>{t('software.cred.rotate_help')}</Typography>
          <TextField fullWidth autoFocus label={etiquetaCampo(t, metodo, campoRotar)} value={nuevoSecreto}
            onChange={(e) => setNuevoSecreto(e.target.value)} autoComplete="new-password"
            InputProps={{
              endAdornment: campoRotar === 'secreto' ? (
                <InputAdornment position="end">
                  <Tooltip title={t('software.cred.generate')}>
                    <IconButton size="small" aria-label={t('software.cred.generate')} onClick={() => setNuevoSecreto(generarSecreto())}>
                      <AutorenewIcon fontSize="small" />
                    </IconButton>
                  </Tooltip>
                </InputAdornment>
              ) : undefined,
            }} />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setRotando(null)}>{t('common.cancel')}</Button>
          <Button variant="contained" disabled={!nuevoSecreto.trim()} onClick={() => void rotar()}>{t('software.cred.rotate')}</Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};
