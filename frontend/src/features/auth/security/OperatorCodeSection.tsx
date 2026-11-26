import { useCallback, useEffect, useState } from 'react';
import {
  Alert, Box, Button, Chip, CircularProgress, Collapse, ListItem, ListItemIcon, ListItemText, Stack, TextField,
} from '@mui/material';
import DialpadIcon from '@mui/icons-material/Dialpad';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { authService, type OperatorCodeStatus } from '../authService';
import { getApiErrorMessage } from '../../../api/errors';
import { operatorCodeProblem } from '../../../utils/operatorCode';

/** Solo dígitos, hasta 6: el teléfono muestra el teclado numérico. */
const soloDigitos = (v: string) => v.replace(/\D/g, '').slice(0, 6);

/**
 * Código de operador (TI y técnicos): los 6 dígitos que se escriben en el escáner para autorizar un cambio,
 * que así queda a su nombre aunque el teléfono sea de bodega. Se define aquí, con la contraseña actual.
 */
export const OperatorCodeSection = () => {
  const { t, i18n } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [estado, setEstado] = useState<OperatorCodeStatus | null>(null);
  const [editando, setEditando] = useState(false);
  const [codigo, setCodigo] = useState('');
  const [repetido, setRepetido] = useState('');
  const [contrasena, setContrasena] = useState('');
  const [error, setError] = useState('');
  const [guardando, setGuardando] = useState(false);

  const cargar = useCallback(() => {
    authService.me().then((me: { operator_code?: OperatorCodeStatus }) => setEstado(me.operator_code ?? null)).catch(() => setEstado(null));
  }, []);
  useEffect(() => { cargar(); }, [cargar]);

  if (!estado?.puede_tener) return null;

  const abrir = () => { setEditando(true); setCodigo(''); setRepetido(''); setContrasena(''); setError(''); };
  const problema = codigo.length === 6 ? operatorCodeProblem(codigo) : null;
  const noCoincide = repetido.length === 6 && repetido !== codigo;
  const listo = codigo.length === 6 && !problema && repetido === codigo;

  const guardar = async () => {
    setGuardando(true);
    setError('');
    try {
      await authService.setOperatorCode(codigo, contrasena);
      enqueueSnackbar(t('security.operator_code.saved'), { variant: 'success' });
      setEditando(false);
      cargar();
    } catch (err) {
      setError(getApiErrorMessage(err, t('common.error_save')));
    } finally {
      setGuardando(false);
    }
  };

  const bloqueado = estado.bloqueado_hasta ? new Date(estado.bloqueado_hasta) : null;
  const chip = bloqueado
    ? <Chip size="small" color="error" variant="outlined" sx={{ height: 20 }}
      label={t('security.operator_code.locked_until', { time: bloqueado.toLocaleTimeString(i18n.language, { hour: '2-digit', minute: '2-digit' }) })} />
    : estado.configurado
      ? <Chip size="small" color="success" variant="outlined" sx={{ height: 20 }} label={t('security.operator_code.set')} />
      : <Chip size="small" color="warning" variant="outlined" sx={{ height: 20 }} label={t('security.operator_code.not_set')} />;

  return (
    <>
      <ListItem disableGutters sx={{ gap: 2, py: 1.25, alignItems: 'center' }}>
        <ListItemIcon sx={{ minWidth: 0, color: 'text.secondary' }}><DialpadIcon /></ListItemIcon>
        <ListItemText sx={{ my: 0, minWidth: 0 }}
          primary={<Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap><span>{t('security.operator_code.title')}</span>{chip}</Stack>}
          secondary={t('security.operator_code.caption')}
          primaryTypographyProps={{ variant: 'body2', fontWeight: 700, component: 'div' }}
          secondaryTypographyProps={{ variant: 'caption' }} />
        {!editando && (
          <Box sx={{ flexShrink: 0 }}>
            <Button size="small" variant={estado.configurado ? 'outlined' : 'contained'} onClick={abrir}>
              {estado.configurado ? t('security.operator_code.change') : t('security.operator_code.define')}
            </Button>
          </Box>
        )}
      </ListItem>
      <Box component="li" sx={{ listStyle: 'none' }}>
        <Collapse in={editando} unmountOnExit>
          <Stack spacing={2} sx={{ pl: { xs: 0, sm: 7 }, pb: 2 }}>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
              <TextField label={t('security.operator_code.new')} type="password" value={codigo} autoComplete="new-password" fullWidth
                onChange={(e) => setCodigo(soloDigitos(e.target.value))}
                slotProps={{ htmlInput: { inputMode: 'numeric', maxLength: 6 } }}
                error={!!problema} helperText={problema ? t(`api_errors.${problema}`) : t('security.operator_code.rules')} />
              <TextField label={t('security.operator_code.repeat')} type="password" value={repetido} autoComplete="new-password" fullWidth
                onChange={(e) => setRepetido(soloDigitos(e.target.value))}
                slotProps={{ htmlInput: { inputMode: 'numeric', maxLength: 6 } }}
                error={noCoincide} helperText={noCoincide ? t('security.operator_code.mismatch') : ' '} />
            </Stack>
            <TextField label={t('security.operator_code.current_password')} type="password" value={contrasena} autoComplete="current-password"
              onChange={(e) => setContrasena(e.target.value)} helperText={t('security.operator_code.current_password_help')} />
            {error && <Alert severity="error">{error}</Alert>}
            <Stack direction="row" spacing={1} justifyContent="flex-end">
              <Button color="inherit" onClick={() => setEditando(false)} disabled={guardando}>{t('common.cancel')}</Button>
              <Button variant="contained" onClick={() => void guardar()} disabled={!listo || guardando}
                startIcon={guardando ? <CircularProgress size={16} color="inherit" /> : undefined}>
                {t('security.operator_code.save')}
              </Button>
            </Stack>
          </Stack>
        </Collapse>
      </Box>
    </>
  );
};
