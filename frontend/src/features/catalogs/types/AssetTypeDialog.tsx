import { useEffect, useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { Alert, Autocomplete, Box, Button, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, MenuItem, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { SectionCard } from '../../../components/ui';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../../api/errors';
import { catalogService } from '../catalogService';
import { TipoActivo } from '../../../types/inventory';
import { TipoActivoCreate } from '../../../types/catalogs';
import { CLASES_ACTIVO, type ClaseActivo } from '../../../utils/clasesActivo';
import { Dialog } from '../../../components/ui/Dialog';

interface Props {
  open: boolean;
  onClose: (refresh?: boolean) => void;
  typeToEdit?: TipoActivo;
  /** Todos los tipos: para elegir los componentes internos que admite. */
  tipos?: TipoActivo[];
}

// Clases que admiten componentes internos (espejo de app/core/clases_activo.py).
const CON_COMPONENTES: ClaseActivo[] = ['COMPUTO', 'IMPRESION', 'RED', 'ENERGIA'];

type Formulario = Omit<TipoActivoCreate, 'TAC_Clase'> & { TAC_Clase: ClaseActivo | '' };

/**
 * Alta y edición de un tipo de activo. La clase decide cómo se opera cada
 * equipo del tipo (se asigna o se instala en un área, lleva software o
 * componentes) y, si el tipo no tiene especificaciones, el servidor le pone
 * la plantilla de su clase.
 */
export const AssetTypeDialog = ({ open, onClose, typeToEdit, tipos = [] }: Props) => {
  const { t } = useTranslation();
  const { register, handleSubmit, reset, control, watch } = useForm<Formulario>({ mode: 'onChange' });
  const [loading, setLoading] = useState(false);
  const [componentes, setComponentes] = useState<number[]>([]);
  const { enqueueSnackbar } = useSnackbar();
  const clase = watch('TAC_Clase');

  useEffect(() => {
    if (!open) return;
    reset({
      TAC_Nombre: typeToEdit?.TAC_Nombre ?? '',
      TAC_Prefijo: typeToEdit?.TAC_Prefijo ?? '',
      TAC_Clase: typeToEdit?.TAC_Clase ?? '',
    });
    setComponentes([]);
    if (typeToEdit) {
      catalogService.getComponentesTipo(typeToEdit.TAC_Tipo_Activo).then(setComponentes).catch(() => setComponentes([]));
    }
  }, [open, typeToEdit, reset]);

  const candidatos = useMemo(
    () => tipos.filter((x) => x.TAC_Tipo_Activo !== typeToEdit?.TAC_Tipo_Activo && x.TAC_Clase === 'COMPONENTE'),
    [tipos, typeToEdit],
  );

  const onSubmit = async (data: Formulario) => {
    setLoading(true);
    try {
      const payload: TipoActivoCreate = {
        TAC_Nombre: data.TAC_Nombre,
        TAC_Prefijo: data.TAC_Prefijo ? data.TAC_Prefijo.toUpperCase() : undefined,
        ...(data.TAC_Clase ? { TAC_Clase: data.TAC_Clase } : {}),
      };
      const guardado = typeToEdit
        ? await catalogService.updateTipoActivo(typeToEdit.TAC_Tipo_Activo, payload)
        : await catalogService.createTipoActivo(payload);
      if (CON_COMPONENTES.includes((guardado.TAC_Clase ?? data.TAC_Clase) as ClaseActivo)) {
        await catalogService.setComponentesTipo(guardado.TAC_Tipo_Activo, componentes);
      }
      enqueueSnackbar(t(typeToEdit ? 'common.success_update' : 'catalogs.types.success_create'), { variant: 'success' });
      onClose(true);
    } catch (error: unknown) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_create')), { variant: 'error' });
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog open={open} onClose={() => onClose()} maxWidth="sm" fullWidth>
      <DialogTitle>{typeToEdit ? t('common.edit') : t('catalogs.types.new_title')}</DialogTitle>
      <form onSubmit={handleSubmit(onSubmit)}>
        <DialogContent>
          <SectionCard title={t('catalogs.sections.general')}>
            <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
              <TextField autoFocus fullWidth label={t('catalogs.types.field_name')}
                {...register('TAC_Nombre', { required: true, minLength: 2 })}/>
              <Box>
                <TextField fullWidth label={t('catalogs.types.field_prefix')}
                  inputProps={{ style: { textTransform: 'uppercase' }, maxLength: 5 }} placeholder="AAA"
                  {...register('TAC_Prefijo', { required: true, minLength: 2, maxLength: 5 })}/>
                <Typography variant="caption" color="text.secondary">{t('catalogs.types.prefix_help')}</Typography>
              </Box>
              <Controller name="TAC_Clase" control={control} render={({ field }) => (
                <TextField select fullWidth label={t('asset_class.field')} value={field.value ?? ''}
                  onChange={field.onChange}
                  helperText={field.value ? t(`asset_class.help.${field.value}`) : t('asset_class.auto')}>
                  <MenuItem value="">{t('asset_class.auto_option')}</MenuItem>
                  {CLASES_ACTIVO.map((c) => <MenuItem key={c} value={c}>{t(`asset_class.names.${c}`)}</MenuItem>)}
                </TextField>
              )}/>
              {clase === 'INSUMO' && <Alert severity="info">{t('asset_class.supply_notice')}</Alert>}
            </Box>
          </SectionCard>
          {clase && CON_COMPONENTES.includes(clase) && (
            <Box sx={{ mt: 2 }}><SectionCard title={t('asset_class.components_title')}>
              <Autocomplete multiple options={candidatos} value={candidatos.filter((c) => componentes.includes(c.TAC_Tipo_Activo))}
                getOptionLabel={(o) => o.TAC_Nombre} isOptionEqualToValue={(a, b) => a.TAC_Tipo_Activo === b.TAC_Tipo_Activo}
                onChange={(_, v) => setComponentes(v.map((x) => x.TAC_Tipo_Activo))}
                renderTags={(v, getTagProps) => v.map((o, i) => <Chip {...getTagProps({ index: i })} key={o.TAC_Tipo_Activo} size="small" label={o.TAC_Nombre}/>)}
                renderInput={(params) => <TextField {...params} label={t('asset_class.components_field')}
                  helperText={t('asset_class.components_help')}/>}
                noOptionsText={t('asset_class.components_none')}/>
            </SectionCard></Box>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => onClose()} color="inherit">{t('common.cancel')}</Button>
          <Button type="submit" variant="contained" disabled={loading}>
            {loading ? <CircularProgress size={24}/> : t('common.save')}
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  );
};
