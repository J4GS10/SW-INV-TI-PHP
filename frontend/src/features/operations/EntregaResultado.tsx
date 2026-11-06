import { useState } from 'react';
import { Alert, Button, Chip, CircularProgress, Stack, Typography } from '@mui/material';
import PictureAsPdfIcon from '@mui/icons-material/PictureAsPdf';
import QrCode2Icon from '@mui/icons-material/QrCode2';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { guardarArchivo, type ResultadoLote } from '../../api/productividadService';
import { normalizeLanguage } from '../../types/language';
import { assetService } from '../assets/assetService';
import { movementService } from './movementService';
import client from '../../api/axios';
import { useConfirm } from '../../components/confirm-context';

interface Props {
  resultado: ResultadoLote;
  /** Texto principal («Se entregaron 7 equipos a …»). */
  mensaje: string;
  tipoActa: 'entrega' | 'descargo';
  /** Entregas: permite deshacerla (dentro de los 30 min siguientes); se llama al terminar. */
  onDeshecho?: () => void;
}

/** Cierre de una operación en lote: un solo documento y las etiquetas de todos los equipos. */
export const EntregaResultado = ({ resultado, mensaje, tipoActa, onDeshecho }: Props) => {
  const { t, i18n } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [ocupado, setOcupado] = useState<'acta' | 'etiquetas' | 'deshacer' | null>(null);
  const confirm = useConfirm();

  const deshacer = async () => {
    if (!(await confirm({ message: t('handover.undo_confirm', { count: resultado.total }), confirmText: t('common.undo') }))) return;
    setOcupado('deshacer');
    try {
      await client.post('/trazabilidad/movimientos/revertir', {
        movimientos: resultado.movimientos,
        instalaciones: (resultado.licencias ?? []).map((l) => l.INS_Instalacion),
      });
      enqueueSnackbar(t('handover.undone'), { variant: 'info' });
      onDeshecho?.();
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error', autoHideDuration: 8000 });
    } finally {
      setOcupado(null);
    }
  };
  const lang = normalizeLanguage(i18n.resolvedLanguage);

  const descargarActa = async () => {
    setOcupado('acta');
    try {
      const blob = await movementService.downloadActaLote(resultado.movimientos, 'pdf', tipoActa, lang);
      guardarArchivo(blob, `${tipoActa === 'entrega' ? t('handover.acta_file') : t('handover.discharge_file')}.pdf`);
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
    } finally {
      setOcupado(null);
    }
  };

  const etiquetas = async () => {
    setOcupado('etiquetas');
    try {
      const blob = await assetService.downloadLabels({ activos_ids: resultado.items.map((i) => i.ACT_Activo) }, lang);
      guardarArchivo(blob, `etiquetas-${resultado.total}.pdf`);
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
    } finally {
      setOcupado(null);
    }
  };

  return (
    <Stack spacing={2}>
      <Alert severity="success">{mensaje}</Alert>
      <Stack direction="row" useFlexGap flexWrap="wrap" gap={0.5}>
        {resultado.items.map((i) => <Chip key={i.ACT_Activo} size="small" label={i.codigo} />)}
      </Stack>
      <Typography variant="subtitle2">{t('operations.done.next')}</Typography>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1}>
        <Button variant="contained" onClick={descargarActa} disabled={!!ocupado}
          startIcon={ocupado === 'acta' ? <CircularProgress size={16} color="inherit" /> : <PictureAsPdfIcon />}>
          {tipoActa === 'entrega' ? t('handover.download_acta') : t('handover.download_discharge')}
        </Button>
        {tipoActa === 'entrega' && (
          <Button variant="outlined" onClick={etiquetas} disabled={!!ocupado}
            startIcon={ocupado === 'etiquetas' ? <CircularProgress size={16} /> : <QrCode2Icon />}>
            {t('handover.print_labels', { count: resultado.total })}
          </Button>
        )}
        {tipoActa === 'entrega' && onDeshecho && (
          <Button color="warning" onClick={() => void deshacer()} disabled={!!ocupado}>
            {t('handover.undo')}
          </Button>
        )}
      </Stack>
    </Stack>
  );
};
