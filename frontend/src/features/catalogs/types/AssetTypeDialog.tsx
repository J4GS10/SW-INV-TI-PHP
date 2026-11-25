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
import { withRowVersion } from '../../../api/rowVersion';
import { catalogoConfigService } from '../../../api/catalogoService';
import { accountingService } from '../../accounting/accountingService';
import { prefijoDesde } from '../../../utils/prefijo';
import type { AccountingCategory } from '../../accounting/accountingService';

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
  const { register, handleSubmit, reset, control, watch, setValue } = useForm<Formulario>({ mode: 'onChange' });
  // Alta: el prefijo se propone del nombre mientras nadie lo escriba a mano.
  const [prefijoAMano, setPrefijoAMano] = useState(false);
  const nombre = watch('TAC_Nombre');
  useEffect(() => { if (open) setPrefijoAMano(!!typeToEdit); }, [open, typeToEdit]);
  useEffect(() => {
    if (!prefijoAMano && open && !typeToEdit) setValue('TAC_Prefijo', prefijoDesde(nombre ?? ''), { shouldValidate: !!nombre });
  }, [nombre, prefijoAMano, open, typeToEdit, setValue]);
  const [loading, setLoading] = useState(false);
  const [componentes, setComponentes] = useState<number[]>([]);
  // Categoría contable: de ella sale la depreciación de los equipos del tipo.
  const [categorias, setCategorias] = useState<AccountingCategory[]>([]);
  const [categoria, setCategoria] = useState<number | ''>('');
  const { enqueueSnackbar } = useSnackbar();
  const clase = watch('TAC_Clase');

  useEffect(() => {
    if (!open) return;
    setCategoria(typeToEdit?.FAC_ASSET_CATEGORY ?? '');
    accountingService.categories().then(setCategorias).catch(() => setCategorias([]));
  }, [open, typeToEdit]);

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

  // Tipos de componente creados desde este formulario (aún no están en la lista que llegó por props).
  const [nuevosComponentes, setNuevosComponentes] = useState<TipoActivo[]>([]);
  const crearComponente = async (nombre: string) => {
    try {
      const creado = await catalogService.createTipoActivo({ TAC_Nombre: nombre, TAC_Prefijo: prefijoDesde(nombre) || 'CMP', TAC_Clase: 'COMPONENTE' });
      setNuevosComponentes((l) => [...l, creado]);
      setComponentes((l) => [...l, creado.TAC_Tipo_Activo]);
      enqueueSnackbar(t('asset_class.components_created', { name: nombre }), { variant: 'success' });
    } catch (error: unknown) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_create')), { variant: 'error' });
    }
  };

  // Las especificaciones del tipo (RAM, disco, batería…) se ofrecen como componentes instalables: si aún no
  // existe un tipo «Componente» con ese nombre, elegirla lo crea (id negativo = por crear).
  const [especificaciones, setEspecificaciones] = useState<string[]>([]);
  useEffect(() => {
    if (!open || !typeToEdit) { setEspecificaciones([]); return; }
    catalogoConfigService.especificacionesPorTipo()
      .then((m) => setEspecificaciones((m[String(typeToEdit.TAC_Tipo_Activo)] ?? []).map((e) => e.nombre ?? '').filter(Boolean)))
      .catch(() => setEspecificaciones([]));
  }, [open, typeToEdit]);
  const desdeEspecificaciones = useMemo((): TipoActivo[] => {
    const existentes = new Set([...tipos, ...nuevosComponentes].filter((x) => x.TAC_Clase === 'COMPONENTE').map((x) => x.TAC_Nombre.toLowerCase()));
    return especificaciones.filter((n) => !existentes.has(n.toLowerCase())).map((n, i) => ({ TAC_Tipo_Activo: -(i + 2), TAC_Nombre: n, TAC_Prefijo: null }));
  }, [especificaciones, tipos, nuevosComponentes]);

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
        FAC_ASSET_CATEGORY: categoria === '' ? null : categoria,
      };
      const guardado = typeToEdit
        ? await catalogService.updateTipoActivo(typeToEdit.TAC_Tipo_Activo, withRowVersion(payload, typeToEdit))
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
                  InputLabelProps={{ shrink: true }}
                  {...register('TAC_Prefijo', { required: true, minLength: 2, maxLength: 5, onChange: () => setPrefijoAMano(true) })}/>
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
              <TextField select fullWidth label={t('accounting.category')} value={categoria}
                onChange={(e) => setCategoria(e.target.value === '' ? '' : Number(e.target.value))}
                helperText={t('accounting.type_category_help')}>
                <MenuItem value="">{t('accounting.without_category')}</MenuItem>
                {categorias.filter((c) => c.active || c.id === categoria).map((c) => (
                  <MenuItem key={c.id} value={c.id}>
                    {`${c.country} · ${c.name}${c.method === 'NONE' ? '' : ` (${c.annual_rate} %)`}`}
                  </MenuItem>
                ))}
              </TextField>
            </Box>
          </SectionCard>
          {clase && CON_COMPONENTES.includes(clase) && (
            <Box sx={{ mt: 2 }}><SectionCard title={t('asset_class.components_title')}>
              <Autocomplete multiple options={[...candidatos, ...nuevosComponentes, ...desdeEspecificaciones]} value={[...candidatos, ...nuevosComponentes].filter((c) => componentes.includes(c.TAC_Tipo_Activo))}
                getOptionLabel={(o) => (o.TAC_Tipo_Activo < 0 ? t('asset_class.components_create', { name: o.TAC_Nombre }) : o.TAC_Nombre)}
                isOptionEqualToValue={(a, b) => a.TAC_Tipo_Activo === b.TAC_Tipo_Activo}
                // Si lo escrito no existe, se ofrece crearlo como tipo de clase «Componente» sin salir del formulario.
                filterOptions={(opciones, { inputValue }) => {
                  const texto = inputValue.trim();
                  const visibles = opciones.filter((o) => o.TAC_Nombre.toLowerCase().includes(texto.toLowerCase()));
                  return texto.length >= 2 && !opciones.some((o) => o.TAC_Nombre.toLowerCase() === texto.toLowerCase())
                    ? [...visibles, { TAC_Tipo_Activo: -1, TAC_Nombre: texto, TAC_Prefijo: null }] : visibles;
                }}
                onChange={(_, v) => {
                  const crear = v.find((x) => x.TAC_Tipo_Activo < 0);
                  if (crear) { void crearComponente(crear.TAC_Nombre); }
                  setComponentes(v.filter((x) => x.TAC_Tipo_Activo > 0).map((x) => x.TAC_Tipo_Activo));
                }}
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
