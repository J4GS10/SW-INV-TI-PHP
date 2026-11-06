import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { Autocomplete, Chip, DialogTitle, DialogContent, DialogActions, Button, TextField, CircularProgress, Stack } from '@mui/material';
import { catalogService } from '../catalogs/catalogService';
import type { Modelo } from '../../types/catalogs';
import { useTranslation } from 'react-i18next';
import { MoneyField, SectionCard, SedeField, SuggestionHint } from '../../components/ui';
import { DEFAULT_CURRENCY } from '../../utils/currency';
import { useSedes } from '../../hooks/useSedes';
import { useSuggestion } from '../../hooks/useSuggestion';
import { useSnackbar } from 'notistack';
import { getApiErrorDetail, getApiErrorMessage } from '../../api/errors';
import { consumableService } from './consumableService';
import { Consumible, ConsumibleCreate } from '../../types/consumables';
import { CatalogField } from '../../components/CatalogField';
import { useCatalogo } from '../../hooks/useCatalogo';
import { Dialog } from '../../components/ui/Dialog';
interface Props {
    open: boolean;
    onClose: (refresh?: boolean) => void;
    toEdit?: Consumible | null;
}
interface ConsumibleFormValues {
    CON_Nombre: string;
    CON_Descripcion: string;
    CON_Stock_Minimo: string | number;
    CON_Stock_Actual?: string | number;
}
export const ConsumableDialog = ({ open, onClose, toEdit }: Props) => {
    const { t } = useTranslation();
    const { enqueueSnackbar } = useSnackbar();
    const isEdit = !!toEdit;
    const { register, handleSubmit, reset, formState: { errors } } = useForm<ConsumibleFormValues>({ mode: 'onChange' });
    // Categoría y unidad del catálogo (la unidad por defecto es «Unidad»).
    const [categoria, setCategoria] = useState<number | null>(null);
    const [unidad, setUnidad] = useState<number | null>(null);
    const { items: unidades } = useCatalogo('unidades-medida');
    const [loading, setLoading] = useState(false);
    // Bodega (sede) donde se almacena; con una sola sede se prellena.
    const [sede, setSede] = useState<number | ''>('');
    // Costo del saldo inicial (valoriza el stock desde el primer día).
    const [costoInicial, setCostoInicial] = useState('');
    const [moneda, setMoneda] = useState<string>(DEFAULT_CURRENCY);
    // Modelos de equipo compatibles (tóner → impresoras): guían la salida a un equipo.
    const [modelos, setModelos] = useState<Modelo[]>([]);
    const [compatibles, setCompatibles] = useState<number[]>([]);
    const { sedes, loading: loadingSedes, unica } = useSedes(open);
    const sedeHint = useSuggestion<number | ''>({
        current: sede,
        empty: '',
        enabled: open && !isEdit,
        apply: setSede,
        suggestion: loadingSedes ? undefined : unica
            ? { value: unica.SED_Sede, label: unica.SED_Nombre, reason: t('scope.reasons.only_sede') }
            : null,
    });
    useEffect(() => {
        if (!open)
            return;
        setSede(toEdit?.SED_Sede ?? '');
        setCategoria(toEdit?.CCO_Categoria ?? null);
        setUnidad(toEdit?.UME_Unidad ?? null);
        setCompatibles([]);
        catalogService.getModelosFlat().then(setModelos).catch(() => setModelos([]));
        if (toEdit) {
            consumableService.compatibles(toEdit.CON_Consumible).then((c) => setCompatibles(c.map((x) => x.MOD_Modelo)))
                .catch(() => setCompatibles([]));
            reset({
                CON_Nombre: toEdit.CON_Nombre,
                CON_Descripcion: toEdit.CON_Descripcion ?? '',
                CON_Stock_Minimo: toEdit.CON_Stock_Minimo,
            });
        }
        else {
            reset({ CON_Nombre: '', CON_Descripcion: '', CON_Stock_Minimo: 0, CON_Stock_Actual: 0 });
            setCostoInicial('');
            setMoneda(DEFAULT_CURRENCY);
        }
    }, [open, toEdit, reset]);
    useEffect(() => {
        // Alta: la unidad por defecto es «Unidad» (el campo es obligatorio).
        if (open && !toEdit && unidad === null) setUnidad(unidades.find((u) => u.codigo === 'UNIDAD')?.id ?? null);
    }, [open, toEdit, unidad, unidades]);
    const onSubmit = async (data: ConsumibleFormValues) => {
        setLoading(true);
        try {
            if (sede === '' && !isEdit) {
                enqueueSnackbar(t('scope.sede_required'), { variant: 'warning' });
                return;
            }
            if (unidad === null) {
                enqueueSnackbar(t('consumables.unit_required'), { variant: 'warning' });
                return;
            }
            const payload: ConsumibleCreate = {
                ...data,
                CCO_Categoria: categoria,
                UME_Unidad: unidad,
                CON_Unidad: unidades.find((u) => u.id === unidad)?.nombre.toLowerCase().slice(0, 20) ?? 'unidad',
                CON_Stock_Minimo: Number(data.CON_Stock_Minimo),
                CON_Stock_Actual: Number(data.CON_Stock_Actual ?? 0),
                SED_Sede: sede === '' ? null : Number(sede),
            };
            if (!isEdit && costoInicial !== '' && Number(payload.CON_Stock_Actual) > 0) {
                payload.CON_Costo_Unitario = costoInicial;
                payload.CON_Moneda = moneda;
            }
            if (isEdit && toEdit) {
                const updatePayload: Partial<ConsumibleCreate> = { ...payload };
                delete updatePayload.CON_Stock_Actual;
                await consumableService.update(toEdit.CON_Consumible, updatePayload);
                await consumableService.setCompatibles(toEdit.CON_Consumible, compatibles);
                enqueueSnackbar(t('common.success_update'), { variant: 'success' });
            }
            else {
                const creado = await consumableService.create(payload);
                if (compatibles.length) await consumableService.setCompatibles(creado.CON_Consumible, compatibles);
                enqueueSnackbar(t('consumables.created'), { variant: 'success' });
            }
            onClose(true);
        }
        catch (err: unknown) {
            const code = getApiErrorDetail(err);
            const msg = code === 'CONSUMABLE_ALREADY_EXISTS'
                ? t('consumables.error_exists')
                : getApiErrorMessage(err, t('common.error_generic'));
            enqueueSnackbar(msg, { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    };
    return (<Dialog open={open} onClose={() => onClose()} maxWidth="sm" fullWidth>
      <DialogTitle>{isEdit ? t('common.edit') : t('consumables.new_title')}</DialogTitle>
      <form onSubmit={handleSubmit(onSubmit)}>
        <DialogContent>
          <Stack spacing={2}>
          <SectionCard title={t('consumables.sections.general')}>
          <TextField autoFocus fullWidth margin="dense" label={t('consumables.field_name')} {...register('CON_Nombre', { required: true, minLength: 2 })} error={!!errors.CON_Nombre} helperText={errors.CON_Nombre ? t('common.required') : ''}/>
          <TextField fullWidth margin="dense" label={t('consumables.field_description')} {...register('CON_Descripcion')}/>
          <Stack direction="row" spacing={2}>
            <CatalogField clave="categorias-consumible" label={t('consumables.field_category')}
              value={categoria} onChange={(v) => setCategoria(v as number | null)}/>
            <CatalogField clave="unidades-medida" label={t('consumables.field_unit')} required
              value={unidad} onChange={(v) => setUnidad(v as number | null)}/>
          </Stack>
          <SedeField margin="dense" required={!isEdit} value={sede} onChange={setSede} sedes={sedes} loading={loadingSedes} label={t('scope.warehouse_sede')} helperText={sedeHint ? undefined : t('scope.consumable_sede_help')}/>
          <SuggestionHint hint={sedeHint}/>
          </SectionCard>
          <SectionCard title={t('consumables.sections.stock')}>
          <Stack direction="row" spacing={2}>
            <TextField fullWidth margin="dense" type="number" label={t('consumables.field_min')} {...register('CON_Stock_Minimo', { required: true, min: 0 })}/>
            {!isEdit && (<TextField fullWidth margin="dense" type="number" label={t('consumables.field_initial')} {...register('CON_Stock_Actual', { required: true, min: 0 })}/>)}
            {!isEdit && (<MoneyField fullWidth margin="dense" label={t('consumables.initial_cost')} helperText={t('consumables.initial_cost_help')}
                value={costoInicial} onChange={setCostoInicial} currency={moneda} onCurrencyChange={setMoneda}/>)}
          </Stack>
          </SectionCard>
          <SectionCard title={t('consumables.compatible_title')} subtitle={t('consumables.compatible_help')}>
            <Autocomplete multiple options={modelos} value={modelos.filter((m) => compatibles.includes(m.MOD_Modelo))}
              getOptionLabel={(m) => `${(m as Modelo & { MAR_Nombre?: string }).MAR_Nombre ?? ''} ${m.MOD_Nombre}`.trim()}
              isOptionEqualToValue={(a, b) => a.MOD_Modelo === b.MOD_Modelo}
              onChange={(_, v) => setCompatibles(v.map((m) => m.MOD_Modelo))}
              renderTags={(v, getTagProps) => v.map((m, i) => <Chip {...getTagProps({ index: i })} key={m.MOD_Modelo} size="small" label={m.MOD_Nombre}/>)}
              renderInput={(params) => <TextField {...params} margin="dense" label={t('consumables.compatible_models')}/>}/>
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
