import { useEffect, useState } from 'react';
import { DialogTitle, DialogContent, DialogActions, Button, TextField, ToggleButton, ToggleButtonGroup, Stack, Typography, CircularProgress, Box } from '@mui/material';
import PictureAsPdfIcon from '@mui/icons-material/PictureAsPdf';
import DescriptionIcon from '@mui/icons-material/Description';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { movementService } from './movementService';
import { ExportLanguageSelect } from '../../components/ExportLanguageSelect';
import { normalizeLanguage, type SupportedLanguage } from '../../types/language';
import { SectionCard } from '../../components/ui';
import { Dialog } from '../../components/ui/Dialog';
interface Props {
    open: boolean;
    movimientoId: string | null;
    codigo: string;
    defaultLanguage?: SupportedLanguage;
    onClose: () => void;
}
export const ActaDescargoDialog = ({ open, movimientoId, codigo, defaultLanguage, onClose }: Props) => {
    const { t, i18n } = useTranslation();
    const { enqueueSnackbar } = useSnackbar();
    const [formato, setFormato] = useState<'pdf' | 'docx'>('pdf');
    const [mensajero, setMensajero] = useState('');
    const [loading, setLoading] = useState(false);
    const [documentLanguage, setDocumentLanguage] = useState<SupportedLanguage>(defaultLanguage ?? normalizeLanguage(i18n.resolvedLanguage ?? i18n.language));
    useEffect(() => {
        if (open) {
            setDocumentLanguage(defaultLanguage ?? normalizeLanguage(i18n.resolvedLanguage ?? i18n.language));
        }
    }, [defaultLanguage, i18n.language, i18n.resolvedLanguage, open]);
    const handleDownload = async () => {
        if (!movimientoId)
            return;
        setLoading(true);
        try {
            const blob = await movementService.downloadActa(movimientoId, formato, 'descargo', mensajero, documentLanguage);
            const url = window.URL.createObjectURL(new Blob([blob]));
            const link = document.createElement('a');
            link.href = url;
            const fecha = new Date().toISOString().split('T')[0];
            link.setAttribute('download', `Descargo_${codigo}_${fecha}.${formato}`);
            document.body.appendChild(link);
            link.click();
            link.parentNode?.removeChild(link);
            enqueueSnackbar(t('operations.messages.doc_generated'), { variant: 'success' });
            setMensajero('');
            onClose();
        }
        catch {
            enqueueSnackbar(t('common.error_generic'), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    };
    return (<Dialog open={open} onClose={onClose} maxWidth="xs" fullWidth>
      <DialogTitle sx={{ fontWeight: 'bold' }}>
        {t('operations.descargo.title')}
      </DialogTitle>
      <DialogContent>
        <Box sx={{ mt: 1 }}>
          <SectionCard title={t('operations.sections.document')} icon={<DescriptionIcon fontSize="small"/>}>
            <Stack spacing={2.5}>
              <Box>
                <Typography variant="caption" color="text.secondary">{t('operations.descargo.format')}</Typography>
                <ToggleButtonGroup exclusive value={formato} fullWidth size="small" onChange={(_, v) => v && setFormato(v)} sx={{ mt: 0.5 }}>
                  <ToggleButton value="pdf"><PictureAsPdfIcon fontSize="small" sx={{ mr: 1 }}/> PDF</ToggleButton>
                  <ToggleButton value="docx"><DescriptionIcon fontSize="small" sx={{ mr: 1 }}/> Word</ToggleButton>
                </ToggleButtonGroup>
              </Box>
              <ExportLanguageSelect value={documentLanguage} onChange={setDocumentLanguage}/>
              <TextField label={t('operations.descargo.messenger')} placeholder={t('operations.descargo.messenger_ph')} value={mensajero} onChange={(e) => setMensajero(e.target.value)} fullWidth inputProps={{ maxLength: 120 }} helperText={t('operations.descargo.messenger_help')}/>
            </Stack>
          </SectionCard>
        </Box>
      </DialogContent>
      <DialogActions sx={{ p: 2.5 }}>
        <Button onClick={onClose} color="inherit">{t('common.cancel')}</Button>
        <Button variant="contained" onClick={handleDownload} disabled={loading}>
          {loading ? <CircularProgress size={20}/> : t('common.download')}
        </Button>
      </DialogActions>
    </Dialog>);
};
