import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { AxiosError, type AxiosResponse, type InternalAxiosRequestConfig } from 'axios';
import {
  Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Stack, TextField, Typography, useMediaQuery, useTheme,
} from '@mui/material';
import DialpadIcon from '@mui/icons-material/Dialpad';
import { useTranslation } from 'react-i18next';
import client from '../../api/axios';
import { useAuth } from '../../context/auth-context';
import { OperatorCodeContext, type OperatorCodeContextValue } from './operator-code-context';
import { OPERATOR_CODE_HEADER, OPERATOR_USER_HEADER, operatorActionFor, type OperatorAction } from './operatorCodeRoutes';

interface Credencial { usuario: string; codigo: string }
interface Pedido { accion: OperatorAction; resolver: (c: Credencial | null) => void }

const ULTIMO_OPERADOR = 'scanner-ultimo-operador';
const leerUltimo = (): string => { try { return window.localStorage.getItem(ULTIMO_OPERADOR) ?? ''; } catch { return ''; } };
const guardarUltimo = (u: string) => { try { window.localStorage.setItem(ULTIMO_OPERADOR, u); } catch { /* sin storage */ } };

/** Rechazo propio al cancelar: no se reintenta ni cierra la sesión, y su texto dice qué pasó. */
const cancelado = (config: InternalAxiosRequestConfig): AxiosError => new AxiosError('OPERATOR_CODE_CANCELLED', 'ERR_CANCELED', config, undefined,
  { data: { detail: 'OPERATOR_CODE_CANCELLED' }, status: 499, statusText: 'Cancelled', headers: {}, config } as AxiosResponse);

/**
 * Pide el código de operador antes de guardar un cambio cuando la cuenta es de escáner (siempre) o cuando una
 * pantalla de escaneo está abierta (TI y técnicos confirman igual). El cambio queda a nombre del dueño del código.
 */
export const OperatorCodeProvider = ({ children }: { children: ReactNode }) => {
  const { t } = useTranslation();
  const theme = useTheme();
  const telefono = useMediaQuery(theme.breakpoints.down('sm'));
  const { user } = useAuth();
  const [pedido, setPedido] = useState<Pedido | null>(null);
  const [usuario, setUsuario] = useState('');
  const [codigo, setCodigo] = useState('');
  const modo = useRef(0);
  const cuenta = useRef<{ rol?: string; usuario?: string }>({});
  const enCurso = useRef<Promise<Credencial | null> | null>(null);
  useEffect(() => { cuenta.current = { rol: user?.role, usuario: user?.sub }; }, [user]);

  const pedir = useCallback((accion: OperatorAction): Promise<Credencial | null> => {
    // Dos escrituras a la vez (p. ej. entrega y su acta) usan el mismo código: se pide una sola vez.
    if (enCurso.current) return enCurso.current;
    const promesa = new Promise<Credencial | null>((resolver) => {
      const esEscaner = cuenta.current.rol === 'ESCANER';
      setUsuario(esEscaner ? leerUltimo() : (cuenta.current.usuario ?? ''));
      setCodigo('');
      setPedido({ accion, resolver });
    }).finally(() => { enCurso.current = null; });
    enCurso.current = promesa;
    return promesa;
  }, []);

  useEffect(() => {
    const id = client.interceptors.request.use(async (config) => {
      const accion = operatorActionFor(config.method, config.url);
      // Ya autorizada (reintento o refresco del token): no se vuelve a pedir.
      if (!accion || config.headers[OPERATOR_CODE_HEADER]) return config;
      if (cuenta.current.rol !== 'ESCANER' && modo.current === 0) return config;
      const credencial = await pedir(accion);
      if (!credencial) throw cancelado(config);
      config.headers[OPERATOR_USER_HEADER] = credencial.usuario;
      config.headers[OPERATOR_CODE_HEADER] = credencial.codigo;
      return config;
    });
    return () => client.interceptors.request.eject(id);
  }, [pedir]);

  const valor = useMemo<OperatorCodeContextValue>(() => ({
    enterScannerMode: () => { modo.current += 1; return () => { modo.current = Math.max(0, modo.current - 1); }; },
  }), []);

  const cerrar = (credencial: Credencial | null) => {
    pedido?.resolver(credencial);
    setPedido(null);
    setCodigo('');
  };
  const listo = usuario.trim().length > 0 && /^\d{6}$/.test(codigo);
  const autorizar = () => {
    if (!listo) return;
    if (cuenta.current.rol === 'ESCANER') guardarUltimo(usuario.trim());
    cerrar({ usuario: usuario.trim(), codigo });
  };

  return (
    <OperatorCodeContext.Provider value={valor}>
      {children}
      <Dialog open={!!pedido} onClose={() => cerrar(null)} fullScreen={telefono} maxWidth="xs" fullWidth aria-labelledby="operator-code-title">
        <DialogTitle id="operator-code-title" sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <DialpadIcon color="primary" />{t('operator_code.title')}
        </DialogTitle>
        <DialogContent>
          <Stack spacing={2.5} sx={{ pt: 1 }}>
            <Typography variant="body1" fontWeight={600}>{pedido ? t(`operator_code.actions.${pedido.accion}`) : ''}</Typography>
            <TextField label={t('operator_code.user')} value={usuario} onChange={(e) => setUsuario(e.target.value)} autoComplete="username"
              autoFocus={!usuario} fullWidth slotProps={{ htmlInput: { autoCapitalize: 'none', spellCheck: false } }} />
            <TextField label={t('operator_code.code')} type="password" value={codigo} autoComplete="off" autoFocus={!!usuario} fullWidth
              onChange={(e) => setCodigo(e.target.value.replace(/\D/g, '').slice(0, 6))}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); autorizar(); } }}
              slotProps={{ htmlInput: { inputMode: 'numeric', maxLength: 6, style: { fontSize: 28, letterSpacing: 12, textAlign: 'center' } } }} />
            <Box>
              <Typography variant="body2" color="text.secondary">{t('operator_code.note')}</Typography>
            </Box>
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button color="inherit" onClick={() => cerrar(null)}>{t('common.cancel')}</Button>
          <Button variant="contained" size="large" onClick={autorizar} disabled={!listo}>{t('operator_code.authorize')}</Button>
        </DialogActions>
      </Dialog>
    </OperatorCodeContext.Provider>
  );
};
