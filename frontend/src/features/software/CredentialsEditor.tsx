import { useMemo, useState } from 'react';
import {
  Alert, Box, Button, IconButton, InputAdornment, Stack, Tab, Table, TableBody, TableCell, TableHead, TableRow, Tabs,
  TextField, Tooltip, Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import AutorenewIcon from '@mui/icons-material/Autorenew';
import DeleteIcon from '@mui/icons-material/DeleteOutline';
import VisibilityIcon from '@mui/icons-material/Visibility';
import VisibilityOffIcon from '@mui/icons-material/VisibilityOff';
import { useTranslation } from 'react-i18next';
import type { CatalogoItem } from '../../api/catalogoService';
import type { CampoCredencial, CredencialIn } from '../../types/software';
import { camposDelMetodo, etiquetaCampo, generarSecreto } from './credenciales';

interface Props {
  /** Método de activación elegido (decide qué campos pide cada credencial). */
  metodo: CatalogoItem | null;
  value: CredencialIn[];
  onChange: (value: CredencialIn[]) => void;
  /** Sube el archivo de licencia y devuelve su id (solo con la licencia ya creada). */
  onUploadFile?: (file: File) => Promise<string>;
  max?: number;
}

const SECRETOS: CampoCredencial[] = ['secreto', 'clave'];

/**
 * Editor de credenciales o cupos: formulario con los campos que pide el método
 * (correo, clave con campo oculto y generador, serial, servidor…) y carga por
 * pegado, una línea por credencial, con vista previa antes de agregarlas.
 */
export const CredentialsEditor = ({ metodo, value, onChange, onUploadFile, max }: Props) => {
  const { t } = useTranslation();
  const campos = useMemo(() => camposDelMetodo(metodo), [metodo]);
  const camposTexto = campos.filter((c) => c !== 'archivo');
  const [pestana, setPestana] = useState<'una' | 'lista'>('una');
  const [actual, setActual] = useState<CredencialIn>({});
  const [ver, setVer] = useState<Partial<Record<CampoCredencial, boolean>>>({});
  const [texto, setTexto] = useState('');
  const [subiendo, setSubiendo] = useState(false);

  if (!metodo) {
    return <Typography variant="body2" color="text.secondary">{t('software.cred.choose_method')}</Typography>;
  }
  if (campos.length === 0) {
    return <Typography variant="body2" color="text.secondary">{t('software.cred.no_credentials')}</Typography>;
  }

  const completa = (c: CredencialIn) => campos.every((campo) => !!(c[campo] ?? '').toString().trim());
  const lleno = max !== undefined && value.length >= max;
  const agregar = () => {
    if (!completa(actual) || lleno) return;
    onChange([...value, actual]);
    setActual({});
  };

  // Pegado: una línea por credencial, campos separados por «;» o tabulación,
  // en el orden del método (p. ej. «correo;clave»).
  const filas = texto.split(/\r?\n/).map((l) => l.trim()).filter(Boolean).map((linea) => {
    const partes = linea.split(/[;\t]/).map((p) => p.trim());
    const cred: CredencialIn = {};
    camposTexto.forEach((campo, i) => { cred[campo] = partes[i] || null; });
    return { cred, ok: camposTexto.every((c) => !!cred[c]) && partes.length <= camposTexto.length };
  });
  const validas = filas.filter((f) => f.ok).map((f) => f.cred);

  const subir = async (file: File | undefined) => {
    if (!file || !onUploadFile) return;
    setSubiendo(true);
    try {
      const id = await onUploadFile(file);
      setActual((a) => ({ ...a, archivo: id }));
    } finally {
      setSubiendo(false);
    }
  };

  return (
    <Stack spacing={2}>
      <Tabs value={pestana} onChange={(_e, v) => setPestana(v)} variant="scrollable" allowScrollButtonsMobile>
        <Tab value="una" label={t('software.cred.one')} />
        <Tab value="lista" label={t('software.cred.paste')} disabled={campos.includes('archivo')} />
      </Tabs>

      {pestana === 'una' ? (
        <Stack spacing={1.5}>
          {campos.map((campo) => {
            const etiqueta = etiquetaCampo(t, metodo, campo);
            if (campo === 'archivo') {
              return onUploadFile ? (
                <Button key={campo} variant="outlined" component="label" disabled={subiendo} sx={{ alignSelf: 'flex-start' }}>
                  {actual.archivo ? t('software.cred.file_ready') : etiqueta}
                  <input hidden type="file" accept=".txt,.pdf" onChange={(e) => void subir(e.target.files?.[0])} />
                </Button>
              ) : (
                <Alert key={campo} severity="info">{t('software.cred.file_after_create')}</Alert>
              );
            }
            const secreto = SECRETOS.includes(campo);
            return (
              <TextField
                key={campo} size="small" fullWidth label={etiqueta} value={actual[campo] ?? ''}
                type={secreto && !ver[campo] ? 'password' : 'text'} autoComplete="new-password"
                onChange={(e) => setActual((a) => ({ ...a, [campo]: e.target.value }))}
                InputProps={secreto ? {
                  endAdornment: (
                    <InputAdornment position="end">
                      <Tooltip title={ver[campo] ? t('software.hide_key') : t('software.reveal_key')}>
                        <IconButton size="small" onClick={() => setVer((v) => ({ ...v, [campo]: !v[campo] }))}
                          aria-label={ver[campo] ? t('software.hide_key') : t('software.reveal_key')}>
                          {ver[campo] ? <VisibilityOffIcon fontSize="small" /> : <VisibilityIcon fontSize="small" />}
                        </IconButton>
                      </Tooltip>
                      {campo === 'secreto' && (
                        <Tooltip title={t('software.cred.generate')}>
                          <IconButton size="small" aria-label={t('software.cred.generate')}
                            onClick={() => { setActual((a) => ({ ...a, secreto: generarSecreto() })); setVer((v) => ({ ...v, secreto: true })); }}>
                            <AutorenewIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                      )}
                    </InputAdornment>
                  ),
                } : undefined}
              />
            );
          })}
          <TextField size="small" fullWidth label={t('software.key_reference')} value={actual.referencia ?? ''}
            onChange={(e) => setActual((a) => ({ ...a, referencia: e.target.value }))} inputProps={{ maxLength: 120 }} />
          <Button startIcon={<AddIcon />} onClick={agregar} disabled={!completa(actual) || lleno} sx={{ alignSelf: 'flex-start' }}>
            {t('software.cred.add_to_list')}
          </Button>
        </Stack>
      ) : (
        <Stack spacing={1.5}>
          <TextField multiline minRows={4} fullWidth value={texto} onChange={(e) => setTexto(e.target.value)}
            label={t('software.cred.paste_label')}
            helperText={t('software.cred.paste_help', { format: camposTexto.map((c) => etiquetaCampo(t, metodo, c)).join(';') })} />
          {filas.length > 0 && (
            <Typography variant="body2" color={filas.length === validas.length ? 'text.secondary' : 'warning.main'}>
              {t('software.cred.paste_preview', { ok: validas.length, total: filas.length })}
            </Typography>
          )}
          <Button startIcon={<AddIcon />} disabled={validas.length === 0 || (max !== undefined && value.length + validas.length > max)}
            onClick={() => { onChange([...value, ...validas]); setTexto(''); }} sx={{ alignSelf: 'flex-start' }}>
            {t('software.cred.add_valid', { count: validas.length })}
          </Button>
        </Stack>
      )}

      {value.length > 0 && (
        <Box sx={{ overflowX: 'auto' }}>
          <Typography variant="subtitle2" sx={{ mb: 0.5 }}>{t('software.cred.pending', { count: value.length })}</Typography>
          <Table size="small">
            <TableHead>
              <TableRow>
                {campos.map((c) => <TableCell key={c}>{etiquetaCampo(t, metodo, c)}</TableCell>)}
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {value.map((cred, i) => (
                <TableRow key={i}>
                  {campos.map((c) => (
                    <TableCell key={c} sx={{ fontFamily: SECRETOS.includes(c) ? 'monospace' : undefined }}>
                      {SECRETOS.includes(c) ? '••••••••' : c === 'archivo' ? '✓' : cred[c]}
                    </TableCell>
                  ))}
                  <TableCell align="right">
                    <IconButton size="small" aria-label={t('common.delete')} onClick={() => onChange(value.filter((_x, j) => j !== i))}>
                      <DeleteIcon fontSize="small" />
                    </IconButton>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      )}
      {max !== undefined && value.length > max && <Alert severity="warning">{t('software.cred.excess', { max })}</Alert>}
    </Stack>
  );
};

