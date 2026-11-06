import { useState } from 'react';
import { Box, Button, Checkbox, FormControlLabel, Paper, Stack, Typography } from '@mui/material';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import DownloadIcon from '@mui/icons-material/Download';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { useAuth } from '../../../context/auth-context';
import { useConfig } from '../../../context/config-context';

interface Props {
  codes: string[];
  /** Exige marcar "Los guardé" antes de continuar. */
  requireAck?: boolean;
  onContinue: () => void;
  continueLabel?: string;
}

/** Códigos de recuperación (se muestran una sola vez) con copiar y descargar. */
export const RecoveryCodesPanel = ({ codes, requireAck = true, onContinue, continueLabel }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const { user } = useAuth();
  const { config } = useConfig();
  const [saved, setSaved] = useState(false);

  const copy = () => {
    navigator.clipboard?.writeText(codes.join('\n')).then(
      () => enqueueSnackbar(t('security.recovery.copied'), { variant: 'success' }),
      () => undefined,
    );
  };

  const download = () => {
    const username = user?.sub ?? '';
    const content = [
      t('security.recovery.file_header', { user: username, app: config.SYS_Nombre_Empresa }),
      t('security.recovery.file_generated', { date: new Date().toLocaleString() }),
      t('security.recovery.file_note'),
      '',
      ...codes,
      '',
    ].join('\r\n');
    const url = URL.createObjectURL(new Blob([content], { type: 'text/plain;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `recovery-codes-${username || 'account'}.txt`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  };

  return (
    <Stack spacing={2}>
      <Box>
        <Typography variant="subtitle2" fontWeight={700}>{t('security.recovery.title')}</Typography>
        <Typography variant="body2" color="text.secondary">{t('security.recovery.description')}</Typography>
      </Box>
      <Paper variant="outlined" sx={{ p: 2, bgcolor: 'action.hover' }}>
        <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(2, minmax(0, 1fr))', gap: 1 }}>
          {codes.map((code) => (
            <Typography key={code} sx={{ fontFamily: 'monospace', fontSize: '0.95rem', letterSpacing: 1 }}>
              {code}
            </Typography>
          ))}
        </Box>
      </Paper>
      <Stack direction="row" spacing={1}>
        <Button variant="outlined" size="small" startIcon={<ContentCopyIcon />} onClick={copy}>
          {t('security.recovery.copy')}
        </Button>
        <Button variant="outlined" size="small" startIcon={<DownloadIcon />} onClick={download}>
          {t('security.recovery.download')}
        </Button>
      </Stack>
      {requireAck && (
        <FormControlLabel
          control={<Checkbox checked={saved} onChange={(_, checked) => setSaved(checked)} />}
          label={<Typography variant="body2">{t('security.recovery.ack')}</Typography>}
        />
      )}
      <Button variant="contained" onClick={onContinue} disabled={requireAck && !saved}>
        {continueLabel ?? t('security.recovery.continue')}
      </Button>
    </Stack>
  );
};
