import { Box, Dialog, DialogContent, DialogTitle, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { ATAJOS_NAVEGACION } from './atajos';

const Tecla = ({ children }: { children: string }) => (
  <Box component="kbd" sx={{
    display: 'inline-block', minWidth: 22, px: 0.75, py: 0.1, textAlign: 'center', fontFamily: 'inherit',
    fontSize: '0.75rem', fontWeight: 700, borderRadius: 1, border: 1, borderColor: 'divider', bgcolor: 'action.hover',
  }}>{children}</Box>
);

export const ShortcutsDialog = ({ open, onClose }: { open: boolean; onClose: () => void }) => {
  const { t } = useTranslation();
  const filas: Array<[string[], string]> = [
    [['Ctrl', 'K'], t('shortcuts.palette')],
    [['/'], t('shortcuts.search')],
    [['N'], t('shortcuts.new')],
    [['E'], t('shortcuts.handover')],
    [['?'], t('shortcuts.help')],
    [['Esc'], t('shortcuts.close')],
    ...Object.keys(ATAJOS_NAVEGACION).map((k): [string[], string] => [['G', k.toUpperCase()], t(`shortcuts.go.${k}`)]),
  ];
  return (
    <Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
      <DialogTitle sx={{ fontWeight: 700 }}>{t('shortcuts.title')}</DialogTitle>
      <DialogContent>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t('shortcuts.subtitle')}</Typography>
        <Stack spacing={1}>
          {filas.map(([teclas, texto]) => (
            <Box key={teclas.join('+')} sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
              <Typography variant="body2" sx={{ flex: 1 }}>{texto}</Typography>
              <Stack direction="row" spacing={0.5}>{teclas.map((k) => <Tecla key={k}>{k}</Tecla>)}</Stack>
            </Box>
          ))}
        </Stack>
      </DialogContent>
    </Dialog>
  );
};
