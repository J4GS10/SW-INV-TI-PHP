import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { DialogTitle, DialogContent, DialogActions, Button, TextField, CircularProgress, Stack } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorDetail } from '../../api/errors';
import { procurementService } from './procurementService';
import { Proveedor, ProveedorCreate } from '../../types/procurement';
import { SectionCard } from '../../components/ui';
import { Dialog } from '../../components/ui/Dialog';
interface Props {
    open: boolean;
    onClose: (refresh?: boolean) => void;
    toEdit?: Proveedor | null;
}
export const ProviderDialog = ({ open, onClose, toEdit }: Props) => {
    const { t } = useTranslation();
    const { enqueueSnackbar } = useSnackbar();
    const isEdit = !!toEdit;
    const { register, handleSubmit, reset, formState: { errors } } = useForm<ProveedorCreate>({ mode: 'onChange' });
    const [loading, setLoading] = useState(false);
    useEffect(() => {
        if (!open)
            return;
        reset(toEdit ? {
            PRV_Nombre: toEdit.PRV_Nombre,
            PRV_Identificacion_Fiscal: toEdit.PRV_Identificacion_Fiscal ?? '',
            PRV_Contacto: toEdit.PRV_Contacto ?? '',
            PRV_Email: toEdit.PRV_Email ?? '',
            PRV_Telefono: toEdit.PRV_Telefono ?? '',
            PRV_Direccion: toEdit.PRV_Direccion ?? '',
        } : {
            PRV_Nombre: '', PRV_Identificacion_Fiscal: '', PRV_Contacto: '',
            PRV_Email: '', PRV_Telefono: '', PRV_Direccion: '',
        });
    }, [open, toEdit, reset]);
    const onSubmit = async (data: ProveedorCreate) => {
        setLoading(true);
        try {
            // Limpiar strings vacíos para que el backend reciba null/omitido.
            const payload: ProveedorCreate = { PRV_Nombre: data.PRV_Nombre.trim() };
            const optionalFields: Array<keyof Omit<ProveedorCreate, 'PRV_Nombre'>> = [
                'PRV_Identificacion_Fiscal',
                'PRV_Contacto',
                'PRV_Email',
                'PRV_Telefono',
                'PRV_Direccion',
            ];
            optionalFields.forEach((field) => {
                const value = data[field]?.trim();
                if (value)
                    payload[field] = value;
            });
            if (isEdit && toEdit) {
                await procurementService.updateProveedor(toEdit.PRV_Proveedor, payload);
                enqueueSnackbar(t('common.success_update'), { variant: 'success' });
            }
            else {
                await procurementService.createProveedor(payload);
                enqueueSnackbar(t('procurement.provider_created'), { variant: 'success' });
            }
            onClose(true);
        }
        catch (err: unknown) {
            const code = getApiErrorDetail(err);
            enqueueSnackbar(code === 'SUPPLIER_ALREADY_EXISTS'
                ? t('procurement.err_provider_exists')
                : t('common.error_generic'), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    };
    return (<Dialog open={open} onClose={() => onClose()} maxWidth="sm" fullWidth>
      <DialogTitle>{isEdit ? t('common.edit') : t('procurement.new_provider')}</DialogTitle>
      <form onSubmit={handleSubmit(onSubmit)}>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <SectionCard title={t('procurement.sections.general')}>
              <TextField autoFocus fullWidth margin="dense" label={t('procurement.provider_name')} {...register('PRV_Nombre', { required: true, minLength: 2 })} error={!!errors.PRV_Nombre} helperText={errors.PRV_Nombre ? t('common.required') : ''}/>
              <TextField fullWidth margin="dense" label={t('procurement.tax_id')} {...register('PRV_Identificacion_Fiscal')}/>
            </SectionCard>
            <SectionCard title={t('procurement.sections.contact')}>
              <Stack direction="row" spacing={2}>
                <TextField fullWidth margin="dense" label={t('procurement.contact')} {...register('PRV_Contacto')}/>
                <TextField fullWidth margin="dense" type="email" label={t('procurement.email')} {...register('PRV_Email')}/>
              </Stack>
              <Stack direction="row" spacing={2}>
                <TextField fullWidth margin="dense" label={t('procurement.phone')} {...register('PRV_Telefono')}/>
                <TextField fullWidth margin="dense" label={t('procurement.address')} {...register('PRV_Direccion')}/>
              </Stack>
            </SectionCard>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => onClose()} color="inherit">{t('common.cancel')}</Button>
          <Button type="submit" variant="contained" disabled={loading}>
            {loading ? <CircularProgress size={24}/> : t('common.save')}
          </Button>
        </DialogActions>
      </form>
    </Dialog>);
};
