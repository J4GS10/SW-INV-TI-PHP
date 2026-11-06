import { useCallback, useEffect, useRef, useState } from 'react';
import { Box, Button, Chip, CircularProgress, IconButton, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Tooltip, Typography } from '@mui/material';
import UploadFileIcon from '@mui/icons-material/UploadFile';
import DownloadIcon from '@mui/icons-material/Download';
import DeleteIcon from '@mui/icons-material/DeleteOutline';
import { useTranslation } from 'react-i18next';
import { CatalogField } from '../../components/CatalogField';
import { useCatalogLabel } from '../../hooks/useCatalogo';
import { EmptyState } from '../../components/ui';
import { useConfirm } from '../../components/confirm-context';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { usePermissions } from '../../hooks/usePermissions';
import { formatDate } from '../../utils/dates';
import { attachmentService, Adjunto } from './attachmentService';
// Mismos límites que el servidor: se avisa antes de subir.
const MAX_MB = 10;
const ACCEPT = '.pdf,.png,.jpg,.jpeg,.webp,.docx,.xlsx,.csv,.txt';
const fmtSize = (bytes: number): string => {
    if (bytes < 1024)
        return `${bytes} B`;
    if (bytes < 1024 * 1024)
        return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};
interface Props {
    activoId?: string;
    ordenId?: number;
}
export const AttachmentsSection = ({ activoId, ordenId }: Props) => {
    const { t, i18n } = useTranslation();
    const confirm = useConfirm();
    const { canOperate, canAdminInventory, canPurchase } = usePermissions();
    const { enqueueSnackbar } = useSnackbar();
    const fileRef = useRef<HTMLInputElement>(null);
    const isOrden = ordenId != null;
    // Subir: operativos en activos, administradores en órdenes. Eliminar: administradores.
    // La factura de una orden la adjunta Compras (o TI).
    const puedeSubir = isOrden ? canPurchase : canOperate;
    const [items, setItems] = useState<Adjunto[]>([]);
    const [loading, setLoading] = useState(false);
    // Categoría = código del catálogo de tipos de evidencia (factura, foto, acta…).
    const [categoria, setCategoria] = useState(isOrden ? 'factura' : 'otro');
    const etiquetaCategoria = useCatalogLabel('tipos-evidencia');
    const [uploading, setUploading] = useState(false);
    const load = useCallback(async () => {
        if (!activoId && !ordenId)
            return;
        setLoading(true);
        try {
            setItems(isOrden
                ? await attachmentService.listOrden(ordenId as number)
                : await attachmentService.list(activoId as string));
        }
        catch {
            enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    }, [activoId, enqueueSnackbar, isOrden, ordenId, t]);
    useEffect(() => { load(); }, [load]);
    const handleFile = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (!file)
            return;
        const ext = `.${file.name.split('.').pop()?.toLowerCase() ?? ''}`;
        if (!ACCEPT.split(',').includes(ext)) {
            enqueueSnackbar(t('attachments.err_type'), { variant: 'warning' });
            if (fileRef.current)
                fileRef.current.value = '';
            return;
        }
        if (file.size > MAX_MB * 1024 * 1024) {
            enqueueSnackbar(t('attachments.err_size_max', { max: MAX_MB }), { variant: 'warning' });
            if (fileRef.current)
                fileRef.current.value = '';
            return;
        }
        setUploading(true);
        try {
            if (isOrden)
                await attachmentService.uploadOrden(ordenId as number, file, categoria);
            else
                await attachmentService.upload(activoId as string, file, categoria);
            enqueueSnackbar(t('attachments.uploaded'), { variant: 'success' });
            await load();
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
        finally {
            setUploading(false);
            if (fileRef.current)
                fileRef.current.value = '';
        }
    };
    const handleDelete = async (id: string) => {
        if (!(await confirm({ message: t('common.confirm_delete'), destructive: true, confirmText: t('common.delete') })))
            return;
        try {
            await attachmentService.remove(id);
            enqueueSnackbar(t('attachments.deleted'), { variant: 'success' });
            await load();
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
    };
    return (<Box>
      {puedeSubir && (<Stack direction="row" spacing={2} alignItems="center" useFlexGap sx={{ mb: 2, flexWrap: 'wrap' }}>
        <Box sx={{ minWidth: 200 }}>
          <CatalogField size="small" clave="tipos-evidencia" valueBy="codigo" label={t('attachments.category')}
            value={categoria} onChange={(v) => setCategoria((v as string | null) ?? 'otro')}/>
        </Box>
        <input ref={fileRef} type="file" hidden accept={ACCEPT} onChange={handleFile}/>
        <Button variant="contained" startIcon={uploading ? <CircularProgress size={18}/> : <UploadFileIcon />} onClick={() => fileRef.current?.click()} disabled={uploading}>
          {t('attachments.upload')}
        </Button>
        <Typography variant="caption" color="text.secondary">
          {t('attachments.hint')}
        </Typography>
      </Stack>)}

      {loading ? (<Box display="flex" justifyContent="center" py={3}><CircularProgress /></Box>) : items.length === 0 ? (<EmptyState compact title={t('attachments.empty')}/>) : (<TableContainer>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{t('attachments.name')}</TableCell>
                <TableCell>{t('attachments.category')}</TableCell>
                <TableCell>{t('attachments.size')}</TableCell>
                <TableCell>{t('attachments.date')}</TableCell>
                <TableCell align="right">{t('common.actions')}</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {items.map((a) => (<TableRow key={a.ADJ_Adjunto} hover>
                  <TableCell>{a.ADJ_Nombre_Original}</TableCell>
                  <TableCell><Chip size="small" label={etiquetaCategoria(a.ADJ_Categoria)}/></TableCell>
                  <TableCell>{fmtSize(a.ADJ_Tamano_Bytes)}</TableCell>
                  <TableCell>{formatDate(a.ADJ_Fecha_Subida, i18n.language)}</TableCell>
                  <TableCell align="right">
                    <Tooltip title={t('attachments.download') as string}>
                      <IconButton size="small" color="primary" onClick={() => attachmentService.download(a)}>
                        <DownloadIcon fontSize="small"/>
                      </IconButton>
                    </Tooltip>
                    {canAdminInventory && (<Tooltip title={t('common.delete') as string}>
                      <IconButton size="small" color="error" onClick={() => handleDelete(a.ADJ_Adjunto)} aria-label={t('common.delete')}>
                        <DeleteIcon fontSize="small"/>
                      </IconButton>
                    </Tooltip>)}
                  </TableCell>
                </TableRow>))}
            </TableBody>
          </Table>
        </TableContainer>)}
    </Box>);
};
