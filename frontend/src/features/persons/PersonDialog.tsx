import { useEffect, useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { Autocomplete, Box, Button, CircularProgress, DialogActions, DialogContent, DialogTitle, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import client from '../../api/axios';
import { getApiErrorMessage } from '../../api/errors';
import { PersonaResumen } from '../../types/users';
import { personService, PersonaCreate, PersonaUpdate } from './personService';
import { SectionCard, SedeField, SuggestionHint } from '../../components/ui';
import { useSedes } from '../../hooks/useSedes';
import { useEntityContext } from '../../hooks/useEntityContext';
import { useSuggestion } from '../../hooks/useSuggestion';
import { suggestionService } from '../../api/suggestionService';
import { managerReason } from '../../utils/suggestionReasons';
import { createSearchFilter } from '../../utils/autocomplete';
import { Dialog } from '../../components/ui/Dialog';
interface Depto {
    DEP_Departamento: number;
    DEP_Nombre: string;
}
interface Cargo {
    CAR_Cargo: number;
    CAR_Nombre: string;
}
interface Props {
    open: boolean;
    persona?: PersonaResumen | null;
    onClose: (refresh?: boolean) => void;
    /** Alta: recibe la persona creada (p. ej. para seguir con el asistente de ingreso). */
    onCreated?: (persona: PersonaResumen) => void;
}
interface FormValues {
    PER_Primer_Nombre: string;
    PER_Segundo_Nombre: string;
    PER_Primer_Apellido: string;
    PER_Segundo_Apellido: string;
    PER_Email_Corporativo: string;
    PER_Telefono: string;
    DEP_Departamento: number | '';
    CAR_Cargo: number | '';
    PER_Jefe: string;
    SED_Sede: number | '';
}
const personFilter = createSearchFilter<PersonaResumen>((person) => [
    person.PER_Primer_Nombre,
    person.PER_Segundo_Nombre ?? '',
    person.PER_Primer_Apellido,
    person.PER_Segundo_Apellido ?? '',
    person.PER_Email_Corporativo,
].join(' '));
const personLabel = (person: PersonaResumen) => [
    person.PER_Primer_Nombre,
    person.PER_Segundo_Nombre,
    person.PER_Primer_Apellido,
    person.PER_Segundo_Apellido,
].filter(Boolean).join(' ');
const empty: FormValues = {
    PER_Primer_Nombre: '',
    PER_Segundo_Nombre: '',
    PER_Primer_Apellido: '',
    PER_Segundo_Apellido: '',
    PER_Email_Corporativo: '',
    PER_Telefono: '',
    DEP_Departamento: '',
    CAR_Cargo: '',
    PER_Jefe: '',
    SED_Sede: '',
};
export const PersonDialog = ({ open, persona, onClose, onCreated }: Props) => {
    const { t } = useTranslation();
    const { enqueueSnackbar } = useSnackbar();
    const isEdit = !!persona;
    const { control, handleSubmit, reset, watch, setValue, formState: { errors } } = useForm<FormValues>({ mode: 'onChange', defaultValues: empty });
    const [departamentos, setDeptos] = useState<Depto[]>([]);
    const [cargos, setCargos] = useState<Cargo[]>([]);
    const [personas, setPersonas] = useState<PersonaResumen[]>([]);
    const [saving, setSaving] = useState(false);
    useEffect(() => {
        if (!open)
            return;
        Promise.all([
            client.get<Depto[]>('/org/departamentos'),
            client.get<Cargo[]>('/org/cargos'),
        ]).then(([d, c]) => { setDeptos(d.data); setCargos(c.data); })
            .catch(() => enqueueSnackbar(t('common.error_loading'), { variant: 'error' }));
        // El jefe es opcional: si la lista no carga, el formulario sigue funcionando.
        personService.list().then(setPersonas).catch(() => setPersonas([]));
    }, [open, enqueueSnackbar, t]);
    // Candidatos a jefe: personas activas, sin la persona que se edita.
    const jefeOptions = useMemo(() => personas.filter((p) => p.PER_Estado !== false && p.PER_Persona !== persona?.PER_Persona), [personas, persona]);
    // Sugerencias del departamento: al crear, o al moverla a otro departamento.
    const depId = watch('DEP_Departamento');
    const cargoId = watch('CAR_Cargo');
    const jefeId = watch('PER_Jefe');
    const suggestEnabled = !persona || depId !== (persona.DEP_Departamento ?? '');
    const depCtx = useEntityContext(open && suggestEnabled ? depId : null, suggestionService.departamento);
    const pending = depCtx.loading ? undefined : null;
    const cargoSug = depCtx.data?.cargo_sugerido;
    const cargoHint = useSuggestion<number | ''>({
        current: cargoId,
        empty: '',
        enabled: suggestEnabled,
        apply: (value) => setValue('CAR_Cargo', value, { shouldDirty: true, shouldValidate: value !== '' }),
        suggestion: cargoSug && cargos.some((c) => c.CAR_Cargo === cargoSug.CAR_Cargo)
            ? { value: cargoSug.CAR_Cargo, label: cargoSug.nombre, reason: t('suggestions.reasons.position_department') }
            : pending,
    });
    const jefeSug = depCtx.data?.jefe_sugerido;
    const jefeHint = useSuggestion<string>({
        current: jefeId,
        empty: '',
        enabled: suggestEnabled,
        apply: (value) => setValue('PER_Jefe', value, { shouldDirty: true }),
        suggestion: jefeSug && jefeSug.PER_Persona !== persona?.PER_Persona
            ? { value: jefeSug.PER_Persona, label: jefeSug.nombre ?? '', reason: managerReason(t, jefeSug.origen) }
            : pending,
    });
    // Sede: la del área habitual del departamento o, con una sola sede, esa.
    const sedeId = watch('SED_Sede');
    const { sedes, loading: loadingSedes, unica } = useSedes(open);
    const sedeDep = depCtx.data?.sede_sugerida;
    const sedeHint = useSuggestion<number | ''>({
        current: sedeId,
        empty: '',
        enabled: !persona,
        apply: (value) => setValue('SED_Sede', value, { shouldDirty: true, shouldValidate: value !== '' }),
        suggestion: depCtx.loading || loadingSedes ? undefined
            : sedeDep && sedes.some((s) => s.SED_Sede === sedeDep.SED_Sede)
                ? { value: sedeDep.SED_Sede, label: sedeDep.nombre, reason: t(`scope.reasons.${sedeDep.origen === 'alcance' ? 'only_sede' : 'department_area'}`) }
                : unica ? { value: unica.SED_Sede, label: unica.SED_Nombre, reason: t('scope.reasons.only_sede') } : null,
    });
    useEffect(() => {
        if (!open)
            return;
        if (persona) {
            reset({
                PER_Primer_Nombre: persona.PER_Primer_Nombre,
                PER_Segundo_Nombre: persona.PER_Segundo_Nombre ?? '',
                PER_Primer_Apellido: persona.PER_Primer_Apellido,
                PER_Segundo_Apellido: persona.PER_Segundo_Apellido ?? '',
                PER_Email_Corporativo: persona.PER_Email_Corporativo,
                PER_Telefono: persona.PER_Telefono ?? '',
                DEP_Departamento: persona.DEP_Departamento ?? '',
                CAR_Cargo: persona.CAR_Cargo ?? '',
                PER_Jefe: persona.PER_Jefe ?? '',
                SED_Sede: persona.SED_Sede ?? '',
            });
        }
        else {
            reset(empty);
        }
    }, [open, persona, reset]);
    const onSubmit = async (data: FormValues) => {
        setSaving(true);
        try {
            const payload: PersonaCreate = {
                PER_Primer_Nombre: data.PER_Primer_Nombre.trim(),
                PER_Segundo_Nombre: data.PER_Segundo_Nombre.trim() || undefined,
                PER_Primer_Apellido: data.PER_Primer_Apellido.trim(),
                PER_Segundo_Apellido: data.PER_Segundo_Apellido.trim() || undefined,
                PER_Email_Corporativo: data.PER_Email_Corporativo.trim(),
                PER_Telefono: data.PER_Telefono.trim() || undefined,
                DEP_Departamento: Number(data.DEP_Departamento),
                CAR_Cargo: Number(data.CAR_Cargo),
                PER_Jefe: data.PER_Jefe || null,
                SED_Sede: data.SED_Sede === '' ? null : Number(data.SED_Sede),
            };
            if (isEdit && persona) {
                await personService.update(persona.PER_Persona, payload as PersonaUpdate);
                enqueueSnackbar(t('common.success_update'), { variant: 'success' });
            }
            else {
                const creada = await personService.create(payload as PersonaCreate);
                enqueueSnackbar(t('common.success_create'), { variant: 'success' });
                onCreated?.(creada);
            }
            onClose(true);
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
        }
        finally {
            setSaving(false);
        }
    };
    return (<Dialog open={open} onClose={() => onClose()} maxWidth="sm" fullWidth>
      <DialogTitle>
        {isEdit ? t('common.edit') : t('persons.new')}
      </DialogTitle>
      <form onSubmit={handleSubmit(onSubmit)}>
        <DialogContent dividers>
          <Stack spacing={2}>
            {persona?.PER_AD_GUID && (<Typography variant="body2" color="text.secondary" sx={{ mb: 0 }}>{t('persons.ad_managed_note')}</Typography>)}
            <SectionCard title={t('persons.sections.personal')}>
              <Stack spacing={2}>
                <Stack direction="row" spacing={2}>
                  <Controller name="PER_Primer_Nombre" control={control} rules={{ required: true, minLength: 2 }} render={({ field }) => (<TextField {...field} fullWidth label={t('persons.first_name')} error={!!errors.PER_Primer_Nombre} required/>)}/>
                  <Controller name="PER_Segundo_Nombre" control={control} render={({ field }) => (<TextField {...field} fullWidth label={t('persons.second_name')}/>)}/>
                </Stack>
                <Stack direction="row" spacing={2}>
                  <Controller name="PER_Primer_Apellido" control={control} rules={{ required: true, minLength: 2 }} render={({ field }) => (<TextField {...field} fullWidth label={t('persons.first_lastname')} error={!!errors.PER_Primer_Apellido} required/>)}/>
                  <Controller name="PER_Segundo_Apellido" control={control} render={({ field }) => (<TextField {...field} fullWidth label={t('persons.second_lastname')}/>)}/>
                </Stack>
              </Stack>
            </SectionCard>
            <SectionCard title={t('persons.sections.contact')}>
              <Stack spacing={2}>
                <Controller name="PER_Email_Corporativo" control={control} rules={{ required: true, pattern: /^[^\s@]+@[^\s@]+\.[^\s@]+$/ }} render={({ field }) => (<TextField {...field} fullWidth type="email" label={t('persons.col_email')} error={!!errors.PER_Email_Corporativo} required/>)}/>
                <Controller name="PER_Telefono" control={control} render={({ field }) => <TextField {...field} fullWidth label={t('persons.col_phone')}/>}/>
              </Stack>
            </SectionCard>
            <SectionCard title={t('persons.sections.organization')}>
              <Stack spacing={2}>
              <Stack direction="row" spacing={2} alignItems="flex-start">
                <Controller name="DEP_Departamento" control={control} rules={{ required: true }} render={({ field }) => (<TextField {...field} select fullWidth sx={{ flex: 1, minWidth: 0 }} label={t('persons.department')} error={!!errors.DEP_Departamento} required>
                      {departamentos.map((d) => (<MenuItem key={d.DEP_Departamento} value={d.DEP_Departamento}>{d.DEP_Nombre}</MenuItem>))}
                    </TextField>)}/>
                <Box sx={{ flex: 1, minWidth: 0 }}>
                <Controller name="CAR_Cargo" control={control} rules={{ required: true }} render={({ field }) => (<TextField {...field} select fullWidth label={t('persons.position')} error={!!errors.CAR_Cargo} required>
                      {cargos.map((c) => (<MenuItem key={c.CAR_Cargo} value={c.CAR_Cargo}>{c.CAR_Nombre}</MenuItem>))}
                    </TextField>)}/>
                <SuggestionHint hint={cargoHint}/>
                </Box>
              </Stack>
              <Box>
                <Controller name="PER_Jefe" control={control} render={({ field }) => (<Autocomplete options={jefeOptions} value={jefeOptions.find((p) => p.PER_Persona === field.value) ?? null} onChange={(_, p) => field.onChange(p?.PER_Persona ?? '')} getOptionLabel={(p) => `${personLabel(p)} (${p.PER_Email_Corporativo})`} filterOptions={personFilter} autoHighlight openOnFocus={false} isOptionEqualToValue={(option, selected) => option.PER_Persona === selected.PER_Persona} noOptionsText={t('common.type_to_search')} renderOption={(props, p) => (<Box component="li" {...props} key={p.PER_Persona}>
                        <Stack spacing={0.25} sx={{ minWidth: 0 }}>
                          <Typography variant="body2" sx={{ fontWeight: 700 }}>{personLabel(p)}</Typography>
                          <Typography variant="caption" color="text.secondary" sx={{ overflowWrap: 'anywhere' }}>{p.PER_Email_Corporativo}</Typography>
                        </Stack>
                      </Box>)} renderInput={(params) => (<TextField {...params} fullWidth label={t('persons.manager')} helperText={persona?.PER_AD_GUID ? t('persons.manager_ad_note') : undefined}/>)}/>)}/>
                <SuggestionHint hint={jefeHint}/>
              </Box>
              <Box>
                <Controller name="SED_Sede" control={control} rules={{ required: !isEdit }} render={({ field }) => (<SedeField value={field.value} onChange={field.onChange} sedes={sedes} loading={loadingSedes} required={!isEdit} error={!!errors.SED_Sede} helperText={sedeHint ? undefined : t('scope.person_sede_help')}/>)}/>
                <SuggestionHint hint={sedeHint}/>
              </Box>
              </Stack>
            </SectionCard>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => onClose()}>{t('common.cancel')}</Button>
          <Button type="submit" variant="contained" disabled={saving}>
            {saving ? <CircularProgress size={20}/> : t('common.save')}
          </Button>
        </DialogActions>
      </form>
    </Dialog>);
};
