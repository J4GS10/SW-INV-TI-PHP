import { useEffect, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { DialogTitle, DialogContent, DialogActions, Button, TextField, MenuItem, CircularProgress, Box, Stack } from '@mui/material';
import { SectionCard } from '../../../components/ui';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../../api/errors';
import { catalogService } from '../catalogService';
import { Marca, Modelo, ModeloCreate, TipoConexion, TipoActivo } from '../../../types/catalogs';
import { Dialog } from '../../../components/ui/Dialog';
interface Props {
    open: boolean;
    onClose: (refresh?: boolean) => void;
    defaultMarcaId?: number;
    modelToEdit?: Modelo;
}
interface FormValues {
    MAR_Marca: number | '';
    MOD_Nombre: string;
    MOD_Anio_Lanzamiento: string;
    TCN_Tipo_Conexion: number | '';
    TAC_Tipo_Activo: number | '';
}
const emptyForm: FormValues = {
    MAR_Marca: '',
    MOD_Nombre: '',
    MOD_Anio_Lanzamiento: '',
    TCN_Tipo_Conexion: '',
    TAC_Tipo_Activo: '',
};
export const ModelDialog = ({ open, onClose, defaultMarcaId, modelToEdit }: Props) => {
    const { t } = useTranslation();
    const { enqueueSnackbar } = useSnackbar();
    const isEdit = !!modelToEdit;
    const { control, handleSubmit, reset, formState: { errors } } = useForm<FormValues>({ mode: 'onChange',
        defaultValues: emptyForm,
    });
    const [loading, setLoading] = useState(false);
    const [marcas, setMarcas] = useState<Marca[]>([]);
    const [conexiones, setConexiones] = useState<TipoConexion[]>([]);
    const [tiposActivo, setTiposActivo] = useState<TipoActivo[]>([]);
    useEffect(() => {
        if (!open)
            return;
        Promise.all([
            catalogService.getMarcas(),
            catalogService.getTiposConexion(),
            catalogService.getTiposActivo(),
        ])
            .then(([m, c, ta]) => { setMarcas(m); setConexiones(c); setTiposActivo(ta); })
            .catch(() => enqueueSnackbar(t('common.error_loading'), { variant: 'error' }));
    }, [open, enqueueSnackbar, t]);
    useEffect(() => {
        if (!open)
            return;
        if (modelToEdit) {
            reset({
                MAR_Marca: modelToEdit.MAR_Marca,
                MOD_Nombre: modelToEdit.MOD_Nombre,
                MOD_Anio_Lanzamiento: modelToEdit.MOD_Anio_Lanzamiento != null ? String(modelToEdit.MOD_Anio_Lanzamiento) : '',
                TCN_Tipo_Conexion: modelToEdit.TCN_Tipo_Conexion ?? '',
                TAC_Tipo_Activo: modelToEdit.TAC_Tipo_Activo ?? '',
            });
        }
        else {
            reset({ ...emptyForm, MAR_Marca: defaultMarcaId ?? '' });
        }
    }, [open, modelToEdit, defaultMarcaId, reset]);
    const onSubmit = async (data: FormValues) => {
        setLoading(true);
        try {
            const payload: ModeloCreate = {
                MAR_Marca: Number(data.MAR_Marca),
                MOD_Nombre: data.MOD_Nombre.trim(),
            };
            if (data.MOD_Anio_Lanzamiento)
                payload.MOD_Anio_Lanzamiento = Number(data.MOD_Anio_Lanzamiento);
            if (data.TCN_Tipo_Conexion)
                payload.TCN_Tipo_Conexion = Number(data.TCN_Tipo_Conexion);
            payload.TAC_Tipo_Activo = data.TAC_Tipo_Activo ? Number(data.TAC_Tipo_Activo) : null;
            if (modelToEdit) {
                await catalogService.updateModelo(modelToEdit.MOD_Modelo, payload);
                enqueueSnackbar(t('common.success_update'), { variant: 'success' });
            }
            else {
                await catalogService.createModelo(payload);
                enqueueSnackbar(t('catalogs.models.success_create'), { variant: 'success' });
            }
            onClose(true);
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    };
    return (<Dialog open={open} onClose={() => onClose()} maxWidth="sm" fullWidth>
      <DialogTitle sx={{ fontWeight: 'bold' }}>
        {isEdit ? t('common.edit') : t('catalogs.models.new_title')}
      </DialogTitle>
      <form onSubmit={handleSubmit(onSubmit)}>
        <DialogContent>
          <Stack spacing={2}>
          <SectionCard title={t('catalogs.sections.general')}>
          <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
            <Controller name="MAR_Marca" control={control} rules={{ required: true }} render={({ field }) => (<TextField {...field} select fullWidth required label={t('catalogs.models.field_brand')} error={!!errors.MAR_Marca}>
                  {marcas.map((m) => (<MenuItem key={m.MAR_Marca} value={m.MAR_Marca}>{m.MAR_Nombre}</MenuItem>))}
                </TextField>)}/>

            <Controller name="TAC_Tipo_Activo" control={control} render={({ field }) => (<TextField {...field} select fullWidth label={t('catalogs.models.field_type')} helperText={t('catalogs.models.type_help')}>
                  <MenuItem value=""><em>{t('catalogs.models.no_type')}</em></MenuItem>
                  {tiposActivo.map((ta) => (<MenuItem key={ta.TAC_Tipo_Activo} value={ta.TAC_Tipo_Activo}>
                      {ta.TAC_Nombre}
                    </MenuItem>))}
                </TextField>)}/>

            <Controller name="MOD_Nombre" control={control} rules={{ required: true, minLength: 2 }} render={({ field }) => (<TextField {...field} fullWidth required label={t('catalogs.models.field_name')} placeholder={t('catalogs.models.name_placeholder')} error={!!errors.MOD_Nombre}/>)}/>
          </Box>
          </SectionCard>

          <SectionCard title={t('catalogs.sections.technical')}>
          <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
            <Controller name="MOD_Anio_Lanzamiento" control={control} render={({ field }) => (<TextField {...field} type="number" fullWidth label={t('catalogs.models.field_year')} inputProps={{ min: 1970, max: 2100 }}/>)}/>

            <Controller name="TCN_Tipo_Conexion" control={control} render={({ field }) => (<TextField {...field} select fullWidth label={t('catalogs.models.field_connection')} helperText={t('catalogs.models.connection_help')}>
                  <MenuItem value=""><em>{t('catalogs.models.no_connection')}</em></MenuItem>
                  {conexiones.map((c) => (<MenuItem key={c.TCN_Tipo_Conexion} value={c.TCN_Tipo_Conexion}>
                      {c.TCN_Nombre}
                    </MenuItem>))}
                </TextField>)}/>
          </Box>
          </SectionCard>
          </Stack>
        </DialogContent>
        <DialogActions sx={{ p: 3 }}>
          <Button onClick={() => onClose()} color="inherit">{t('common.cancel')}</Button>
          <Button type="submit" variant="contained" disabled={loading}>
            {loading ? <CircularProgress size={24} color="inherit"/> : t('common.save')}
          </Button>
        </DialogActions>
      </form>
    </Dialog>);
};
