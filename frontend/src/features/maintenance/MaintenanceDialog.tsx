import { useEffect, useState } from 'react';
import { useForm, Controller } from 'react-hook-form';
import { DialogTitle, DialogContent, DialogActions, Button, TextField, CircularProgress, Box, Autocomplete, MenuItem, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { MoneyField, SectionCard, SuggestionHint } from '../../components/ui';
import { DEFAULT_CURRENCY } from '../../utils/currency';
import { useEntityContext } from '../../hooks/useEntityContext';
import { useSuggestion } from '../../hooks/useSuggestion';
import { suggestionService } from '../../api/suggestionService';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { maintenanceService } from './maintenanceService';
import { assetService } from '../assets/assetService';
import { userService } from '../users/userService';
import { MantenimientoCreate, TipoMantenimiento } from '../../types/maintenance';
import { Activo } from '../../types/inventory';
import { PersonaCombo } from '../../types/users';
import { createSearchFilter } from '../../utils/autocomplete';
import type { CatalogoItem } from '../../api/catalogoService';
import { CatalogField } from '../../components/CatalogField';
import { pideDetalle } from '../../hooks/useCatalogo';
import { Dialog } from '../../components/ui/Dialog';
interface Props {
    open: boolean;
    onClose: (refresh?: boolean) => void;
    /** Equipo ya elegido (desde su ficha o tras una devolución con daño). */
    activoInicialId?: string | null;
}
interface MaintenanceFormValues {
    activo: Activo | null;
    persona: PersonaCombo | null;
    tipo_mantenimiento: number | '';
    falla: CatalogoItem | null;
    descripcion: string;
    costo: string;
    moneda: string;
}
const personFilter = createSearchFilter<PersonaCombo>((person) => [
    person.PER_Primer_Nombre,
    person.PER_Primer_Apellido,
    person.PER_Email_Corporativo,
].join(' '));
const personLabel = (person: PersonaCombo) => `${person.PER_Primer_Nombre} ${person.PER_Primer_Apellido}`;
export const MaintenanceDialog = ({ open, onClose, activoInicialId }: Props) => {
    const { t } = useTranslation();
    const { control, handleSubmit, reset, watch, setValue } = useForm<MaintenanceFormValues>({ mode: 'onChange' });
    const { enqueueSnackbar } = useSnackbar();
    // Listas
    const [tipos, setTipos] = useState<TipoMantenimiento[]>([]);
    const [activos, setActivos] = useState<Activo[]>([]);
    const [personas, setPersonas] = useState<PersonaCombo[]>([]);
    const [loadingData, setLoadingData] = useState(false);
    const [busquedaActivo, setBusquedaActivo] = useState('');
    const [submitting, setSubmitting] = useState(false);
    // El solicitante habitual es el custodio actual del activo (editable).
    const selectedAsset = watch('activo');
    const selectedPerson = watch('persona');
    const assetCtx = useEntityContext(selectedAsset?.ACT_Activo ?? null, suggestionService.activo);
    const custodian = assetCtx.data?.custodio;
    const requesterHint = useSuggestion<string | null>({
        current: selectedPerson?.PER_Persona ?? null,
        empty: null,
        apply: (id) => setValue('persona', personas.find((p) => p.PER_Persona === id) ?? null, { shouldDirty: true, shouldValidate: id != null }),
        suggestion: assetCtx.loading ? undefined : custodian && personas.some((p) => p.PER_Persona === custodian.PER_Persona)
            ? { value: custodian.PER_Persona, label: custodian.nombre ?? '', reason: t('suggestions.reasons.custodian') }
            : null,
    });
    useEffect(() => {
        if (open) {
            setLoadingData(true);
            Promise.all([
                maintenanceService.getTipos(),
                userService.getAllPersonas(), // Todos los empleados activos pueden reportar
                activoInicialId ? assetService.getActivo(activoInicialId) : Promise.resolve(null),
            ]).then(([t, p, inicial]) => {
                setTipos(t);
                setPersonas(p.filter((persona) => persona.PER_Estado !== false));
                setActivos(inicial ? [inicial] : []);
                if (inicial) setValue('activo', inicial);
            }).catch(console.error)
                .finally(() => setLoadingData(false));
            reset({
                activo: null,
                persona: null,
                tipo_mantenimiento: '',
                falla: null,
                descripcion: '',
                costo: '',
                moneda: DEFAULT_CURRENCY,
            });
        }
    }, [open, reset, activoInicialId, setValue]);
    // Búsqueda de activos en el servidor (antes solo los primeros 200).
    useEffect(() => {
        const q = busquedaActivo.trim();
        if (!open || q.length < 2) return;
        let vivo = true;
        const tm = window.setTimeout(async () => {
            try {
                const r = await assetService.searchActivos({ q, per_page: 30 });
                if (vivo) setActivos(r.items.filter((a) => !a.pending_completion));
            } catch {
                if (vivo) setActivos([]);
            }
        }, 250);
        return () => { vivo = false; window.clearTimeout(tm); };
    }, [busquedaActivo, open]);
    const onSubmit = async (data: MaintenanceFormValues) => {
        if (!data.activo || !data.persona || !data.tipo_mantenimiento) {
            enqueueSnackbar(t('common.required'), { variant: 'warning' });
            return;
        }
        setSubmitting(true);
        try {
            const payload: MantenimientoCreate = {
                ACT_Activo: data.activo.ACT_Activo,
                PER_Persona_Solicita: data.persona.PER_Persona,
                TMA_Tipo_Mantenimiento: Number(data.tipo_mantenimiento),
                TFA_Tipo_Falla: data.falla?.id ?? null,
                MAN_Descripcion_Falla: data.descripcion.trim(),
                MAN_Costo_Total: Number(data.costo || 0),
                MAN_Moneda: data.moneda || DEFAULT_CURRENCY,
            };
            await maintenanceService.create(payload);
            enqueueSnackbar(t('maintenance.created_ok'), { variant: 'success' });
            onClose(true);
        }
        catch (error: unknown) {
            enqueueSnackbar(getApiErrorMessage(error, t('common.error_create')), { variant: 'error' });
        }
        finally {
            setSubmitting(false);
        }
    };
    return (<Dialog open={open} onClose={() => onClose()} maxWidth="md" fullWidth>
      <DialogTitle sx={{ fontWeight: 'bold' }}>
        {t('maintenance.register')}
      </DialogTitle>
      <form onSubmit={handleSubmit(onSubmit)}>
        <DialogContent>
          {loadingData ? <Box p={4} display="flex" justifyContent="center"><CircularProgress /></Box> : (<Stack spacing={2}>
              <SectionCard title={t('maintenance.sections.asset_requester')}>
              <Box display="flex" flexDirection="column" gap={2}>
              {/* 1. ACTIVO DAÑADO */}
              <Controller name="activo" control={control} rules={{ required: true }} render={({ field: { onChange, value }, fieldState: { error } }) => (<Autocomplete options={activos} getOptionLabel={(opt) => `${opt.ACT_Codigo_Interno} - ${opt.ACT_Serie_Fabricante}`} filterOptions={(x) => x} autoHighlight onInputChange={(_, texto, motivo) => { if (motivo === 'input') setBusquedaActivo(texto); }} isOptionEqualToValue={(option, selected) => option.ACT_Activo === selected.ACT_Activo} noOptionsText={busquedaActivo.trim().length < 2 ? t('operations.dialog.type_to_search_asset') : t('operations.dialog.no_asset_found', { q: busquedaActivo.trim() })} value={value || null} onChange={(_, data) => onChange(data)} renderOption={(props, option) => (<Box component="li" {...props} key={option.ACT_Activo}>
                        <Stack spacing={0.25} sx={{ minWidth: 0 }}>
                          <Typography variant="body2" sx={{ fontWeight: 700 }}>
                            {option.ACT_Codigo_Interno}
                          </Typography>
                          <Typography variant="caption" color="text.secondary" sx={{ overflowWrap: 'anywhere' }}>
                            {option.ACT_Hostname || t('assets.table.no_host')} / {option.ACT_Serie_Fabricante}
                          </Typography>
                        </Stack>
                      </Box>)} renderInput={(params) => (<TextField {...params} label={t('maintenance.asset_to_repair')} error={!!error} helperText={error ? t('common.required') : ""}/>)}/>)}/>

              {/* 2. SOLICITANTE */}
              <Controller name="persona" control={control} rules={{ required: true }} render={({ field: { onChange, value }, fieldState: { error } }) => (<Autocomplete options={personas} getOptionLabel={personLabel} filterOptions={personFilter} autoHighlight openOnFocus={false} isOptionEqualToValue={(option, selected) => option.PER_Persona === selected.PER_Persona} noOptionsText={t('common.type_to_search')} value={value || null} onChange={(_, data) => onChange(data)} renderOption={(props, option) => (<Box component="li" {...props} key={option.PER_Persona}>
                        <Stack spacing={0.25} sx={{ minWidth: 0 }}>
                          <Typography variant="body2" sx={{ fontWeight: 700 }}>
                            {personLabel(option)}
                          </Typography>
                          <Typography variant="caption" color="text.secondary" sx={{ overflowWrap: 'anywhere' }}>
                            {option.PER_Email_Corporativo}
                          </Typography>
                        </Stack>
                      </Box>)} renderInput={(params) => (<TextField {...params} label={t('maintenance.requester')} error={!!error}/>)}/>)}/>
              {requesterHint && (<Box sx={{ mt: -1.5 }}>
                  <SuggestionHint hint={requesterHint}/>
                </Box>)}
              </Box>
              </SectionCard>

              <SectionCard title={t('maintenance.sections.service')}>
              <Box display="flex" flexDirection="column" gap={2}>
              {/* 3. TIPO Y COSTO */}
              <Box display="flex" gap={2}>
                <Controller name="tipo_mantenimiento" control={control} rules={{ required: true }} defaultValue="" render={({ field, fieldState }) => (<TextField {...field} select fullWidth required label={t('maintenance.service_type')} error={!!fieldState.error}>
                      {tipos.map((tm) => (<MenuItem key={tm.TMA_Tipo_Mantenimiento} value={tm.TMA_Tipo_Mantenimiento}>
                          {tm.TMA_Nombre}
                        </MenuItem>))}
                    </TextField>)}/>

                <Controller name="moneda" control={control} defaultValue={DEFAULT_CURRENCY} render={({ field: moneda }) => (<Controller name="costo" control={control} defaultValue="" render={({ field }) => (<MoneyField name={field.name} value={field.value} onChange={field.onChange} onBlur={field.onBlur} currency={moneda.value} onCurrencyChange={moneda.onChange} fullWidth label={t('maintenance.estimated_cost')}/>)}/>)}/>
              </Box>

              {/* 4. DESCRIPCIÓN */}
              <Controller name="falla" control={control} rules={{ required: t('common.required') }} render={({ field, fieldState }) => (<CatalogField clave="tipos-falla" label={t('maintenance.failure_type')} required value={field.value?.id ?? null} onChange={(_v, item) => field.onChange(item)} error={!!fieldState.error} helperText={fieldState.error?.message}/>)}/>
              <Controller name="descripcion" control={control} rules={{ validate: (v) => !pideDetalle(watch('falla')) || v.trim().length >= 5 || t('maintenance.errors.short_action') }} defaultValue="" render={({ field, fieldState }) => (<TextField {...field} label={t('maintenance.failure_description')} multiline rows={3} error={!!fieldState.error} helperText={fieldState.error?.message ?? t('maintenance.failure_description_help')}/>)}/>
              </Box>
              </SectionCard>
            </Stack>)}
        </DialogContent>
        <DialogActions sx={{ p: 3 }}>
          <Button onClick={() => onClose()} color="inherit">{t('common.cancel')}</Button>
          <Button type="submit" variant="contained" disabled={submitting}>
            {submitting ? <CircularProgress size={24} color="inherit"/> : t('common.save')}
          </Button>
        </DialogActions>
      </form>
    </Dialog>);
};
