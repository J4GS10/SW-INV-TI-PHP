import { Button, DialogActions, DialogContent, DialogTitle } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { Dialog } from '../../components/ui/Dialog';
import { AttachmentsSection } from './AttachmentsSection';

interface Props {
  /** El activo o componente cuyos archivos se ven; null = cerrado. */
  asset: { id: string; code: string } | null;
  onClose: () => void;
}

/** Fotos, facturas y actas de un activo o de un componente, sin salir de la pantalla donde está. */
export const AttachmentsDialog = ({ asset, onClose }: Props) => {
  const { t } = useTranslation();
  return (
    <Dialog open={asset !== null} onClose={onClose} maxWidth="md" fullWidth>
      <DialogTitle>{t('attachments.of_asset', { code: asset?.code ?? '' })}</DialogTitle>
      <DialogContent>{asset && <AttachmentsSection activoId={asset.id}/>}</DialogContent>
      <DialogActions><Button onClick={onClose}>{t('common.close')}</Button></DialogActions>
    </Dialog>
  );
};
