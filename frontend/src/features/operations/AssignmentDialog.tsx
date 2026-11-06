import { useEffect, useState } from 'react';
import { useForm, Controller } from 'react-hook-form';
import { Alert, DialogTitle, DialogContent, DialogActions, Button, TextField, CircularProgress, Box, Autocomplete, Typography, MenuItem, Stack } from '@mui/material';
import { downloadFile } from '../../api/downloadCsv';
import { hoyLocal } from '../../utils/dates';
import { ContextCaption, SectionCard, SuggestionHint } from '../../components/ui';
import { useTranslation } from 'react-i18next';
import { catalogSlug, movementTypeLabel } from '../../utils/catalogLabels';
import { useEntityContext } from '../../hooks/useEntityContext';
import { useSuggestion } from '../../hooks/useSuggestion';
import { suggestionService } from '../../api/suggestionService';
import { areaReason, personContextLine, shortList } from '../../utils/suggestionReasons';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
// --- SERVICIOS ---
// Mantenemos la estructura de producción: movementService es la autoridad
import { movementService } from './movementService';
import { assetService } from '../assets/assetService';
// Agregamos userService si fuera estrictamente necesario para tipos, 
// pero usaremos el endpoint de movementService que ya funcionaba en prod.
// --- TIPOS ---
import { Activo, TipoActivo } from '../../types/inventory';
import { PersonaResumen } from '../../types/users';
import { Movimiento, MovimientoCreate, TipoMovimiento } from '../../types/traceability';
import { Area } from '../../types/geo';
import { createSearchFilter } from '../../utils/autocomplete';
import type { CatalogoItem, MovimientoAplica } from '../../api/catalogoService';
import { CatalogField } from '../../components/CatalogField';
import client from '../../api/axios';
import { motivoAplica, pideDetalle } from '../../hooks/useCatalogo';
import { Dialog } from '../../components/ui/Dialog';
import { DateField } from '../../components/ui/DateField';
interface Props {
    open: boolean;
    onClose: (refresh?: boolean) => void;
    /** Activo ya elegido (desde su ficha o tras darlo de alta). */
    activoInicial?: Activo | null;
}
interface AssignmentFormData {
    categoria: number | '';
    activo: Activo | null;
    persona: PersonaResumen | null;
    area: Area | null;
    tipo_movimiento: TipoMovimiento | null;
    observacion: string;
    hostname: string;
    motivo: CatalogoItem | null;
    motivo_detalle: string;
    fecha_prevista: string;
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
// Tipos que abre este diálogo: asignar y prestar a quien no tiene el equipo;
// transferir cuando ya tiene custodio. La devolución tiene su propio diálogo.
const TIPOS_LIBRE = ['asignacion', 'prestamo'];
const TIPOS_EN_CUSTODIA = ['transferencia'];
export const AssignmentDialog = ({ open, onClose, activoInicial }: Props) => {
    const { t, i18n } = useTranslation();
    // Fusionamos hooks: Production (control) + Implementation (watch/setValue)
    const { control, handleSubmit, reset, watch, setValue } = useForm<AssignmentFormData>({ mode: 'onChange' });
    const { enqueueSnackbar } = useSnackbar();
    // --- ESTADOS (Catalogos) ---
    const [activos, setActivos] = useState<Activo[]>([]);
    const [busquedaActivo, setBusquedaActivo] = useState('');
    const [buscandoActivo, setBuscandoActivo] = useState(false);
    const [resultado, setResultado] = useState<{ mov: Movimiento; persona: PersonaResumen | null } | null>(null);
    // «Deshacer» durante 8 s tras una asignación: se registra la devolución con motivo «Revertido».
    const [puedeDeshacer, setPuedeDeshacer] = useState(false);
    useEffect(() => {
        if (!resultado) return undefined;
        const inicio = window.setTimeout(() => setPuedeDeshacer(true), 0);
        const fin = window.setTimeout(() => setPuedeDeshacer(false), 8000);
        return () => { window.clearTimeout(inicio); window.clearTimeout(fin); };
    }, [resultado]);
    const deshacer = async () => {
        if (!resultado) return;
        try {
            // Asignación → vuelve a bodega; transferencia → vuelve al custodio anterior.
            await client.post('/trazabilidad/movimientos/revertir', { movimientos: [resultado.mov.MOV_Movimiento] });
            enqueueSnackbar(t('operations.done.undone'), { variant: 'info' });
            setResultado(null);
            onClose(true);
        } catch (err) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
        }
    };
    const [personas, setPersonas] = useState<PersonaResumen[]>([]);
    const [tiposMov, setTiposMov] = useState<TipoMovimiento[]>([]);
    const [areas, setAreas] = useState<Area[]>([]);
    const [tiposActivo, setTiposActivo] = useState<TipoActivo[]>([]);
    const [loadingData, setLoadingData] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    // --- NUEVA LÓGICA: Watch para el filtro de categoría ---
    const selectedCategory = watch('categoria');
    // --- SUGERENCIAS RELACIONALES (prellenan sin pisar lo que elija el usuario) ---
    const selectedAsset = watch('activo');
    const selectedPerson = watch('persona');
    const currentArea = watch('area');
    const currentType = watch('tipo_movimiento');
    const currentHostname = watch('hostname') ?? '';
    const personCtx = useEntityContext(selectedPerson?.PER_Persona ?? null, suggestionService.persona);
    const assetCtx = useEntityContext(selectedAsset?.ACT_Activo ?? null, suggestionService.activo);
    const areaHint = useSuggestion<number | null>({
        current: currentArea?.ARE_Area ?? null,
        empty: null,
        apply: (id) => setValue('area', areas.find((a) => a.ARE_Area === id) ?? null, { shouldDirty: true, shouldValidate: id != null }),
        suggestion: personCtx.loading ? undefined : personCtx.data?.area_sugerida ? {
            value: personCtx.data.area_sugerida.ARE_Area,
            label: personCtx.data.area_sugerida.nombre,
            reason: areaReason(t, personCtx.data.area_sugerida.origen, personCtx.data.persona?.nombre),
        } : null,
    });
    const suggestedType = assetCtx.data
        ? tiposMov.find((tm) => catalogSlug(tm.TMO_Nombre) === assetCtx.data?.tipo_movimiento_sugerido) ?? null
        : null;
    const typeHint = useSuggestion<number | null>({
        current: currentType?.TMO_Tipo_Movimiento ?? null,
        empty: null,
        apply: (id) => setValue('tipo_movimiento', tiposMov.find((tm) => tm.TMO_Tipo_Movimiento === id) ?? null, { shouldDirty: true, shouldValidate: id != null }),
        suggestion: assetCtx.loading ? undefined : suggestedType ? {
            value: suggestedType.TMO_Tipo_Movimiento,
            label: movementTypeLabel(t, suggestedType.TMO_Nombre),
            reason: assetCtx.data?.tipo_movimiento_sugerido === 'transferencia'
                ? t('suggestions.reasons.movement_in_custody')
                : t('suggestions.reasons.movement_free'),
        } : null,
    });
    const hostnameHint = useSuggestion<string>({
        current: currentHostname,
        empty: '',
        apply: (value) => setValue('hostname', value, { shouldDirty: true }),
        suggestion: selectedAsset?.ACT_Hostname
            ? { value: selectedAsset.ACT_Hostname, label: selectedAsset.ACT_Hostname, reason: t('suggestions.reasons.hostname_current') }
            : null,
    });
    const custodian = assetCtx.data?.custodio;
    const custodyLine = custodian ? [
        custodian.departamento
            ? t('suggestions.info.custody', { name: custodian.nombre ?? '', department: custodian.departamento })
            : t('suggestions.info.custody_no_dep', { name: custodian.nombre ?? '' }),
        assetCtx.data?.area_actual ? t('suggestions.info.current_area', { area: assetCtx.data.area_actual.nombre }) : null,
    ].filter(Boolean).join(' · ') : '';
    const personLine = personCtx.data ? personContextLine(t, personCtx.data) : '';
    const personAssets = personCtx.data?.activos_vigentes ?? [];
    // --- CARGA DE DATOS (Conservamos la robustez de Producción) ---
    useEffect(() => {
        if (open) {
            setLoadingData(true);
            setResultado(null);
            Promise.all([
                movementService.getPersonasLookup(),
                movementService.getTiposMovimiento(),
                movementService.getAreas(),
                assetService.getTipos() // Necesario para el filtro y labels
            ]).then(([p, tm, ar, ta]) => {
                // Solo personas activas: no se entregan equipos a quien ya salió.
                setPersonas(p.filter((persona) => persona.PER_Estado !== false));
                setTiposMov(tm);
                setAreas(ar);
                setTiposActivo(ta);
            }).catch(err => {
                console.error(err);
                enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
            }).finally(() => {
                setLoadingData(false);
            });
            // Reset completo incluyendo el nuevo campo categoria
            reset({
                categoria: '',
                activo: activoInicial ?? null,
                persona: null,
                area: null,
                tipo_movimiento: null,
                observacion: '',
                hostname: '',
                motivo: null, motivo_detalle: '',
                fecha_prevista: hoyLocal(7),
            });
            setActivos(activoInicial ? [activoInicial] : []);
        }
    }, [open, reset, t, enqueueSnackbar, activoInicial]);
    // --- BÚSQUEDA DE ACTIVOS EN EL SERVIDOR ---
    // Antes se precargaban los primeros 50 activos y los demás no se podían
    // asignar. Ahora se busca en todo el inventario mientras se escribe.
    useEffect(() => {
        if (!open) return;
        const q = busquedaActivo.trim();
        if (q.length < 2 && !selectedCategory) return;
        let vivo = true;
        const temporizador = window.setTimeout(async () => {
            setBuscandoActivo(true);
            try {
                const r = await assetService.searchActivos({
                    q: q.length >= 2 ? q : undefined,
                    tipo_activo_id: selectedCategory || undefined,
                    per_page: 30,
                });
                if (vivo) setActivos(r.items.filter((a) => !a.pending_completion));
            } catch {
                if (vivo) setActivos([]);
            } finally {
                if (vivo) setBuscandoActivo(false);
            }
        }, 250);
        return () => { vivo = false; window.clearTimeout(temporizador); };
    }, [busquedaActivo, open, selectedCategory]);
    const enCustodia = !!assetCtx.data?.custodio;
    const tiposPermitidos = tiposMov.filter((tm) => (enCustodia ? TIPOS_EN_CUSTODIA : TIPOS_LIBRE).includes(catalogSlug(tm.TMO_Nombre)));
    const esPrestamo = catalogSlug(currentType?.TMO_Nombre ?? '') === 'prestamo';
    const esTransferencia = catalogSlug(currentType?.TMO_Nombre ?? '') === 'transferencia';
    const aplicaA: MovimientoAplica = esTransferencia ? 'TRANSFERENCIA' : esPrestamo ? 'PRESTAMO' : 'ASIGNACION';
    const motivoActual = watch('motivo');
    // Un motivo que no aplica al nuevo tipo de movimiento se quita.
    useEffect(() => {
        if (motivoActual && !motivoAplica(motivoActual, aplicaA)) setValue('motivo', null);
    }, [aplicaA, motivoActual, setValue]);
    // --- LÓGICA VISUAL (Producción - Más rica) ---
    const getActivoLabel = (activo: Activo) => {
        // Usamos la lógica de producción que busca el nombre del tipo para mostrarlo bonito
        const tipo = tiposActivo.find(t => t.TAC_Tipo_Activo === activo.TAC_Tipo_Activo)?.TAC_Nombre || t('operations.table.asset');
        return `${activo.ACT_Codigo_Interno} | ${tipo} | ${activo.ACT_Hostname || t('assets.table.no_host')}`;
    };
    // --- SUBMIT (Producción) ---
    const onSubmit = async (data: AssignmentFormData) => {
        if (!data.activo || !data.persona || !data.area || !data.tipo_movimiento) {
            enqueueSnackbar(t('common.required'), { variant: 'warning' });
            return;
        }
        if (esPrestamo && !data.fecha_prevista) {
            enqueueSnackbar(t('operations.dialog.loan_date_required'), { variant: 'warning' });
            return;
        }
        setSubmitting(true);
        try {
            let mov: Movimiento;
            if (esTransferencia) {
                mov = await movementService.transfer({
                    ACT_Activo: data.activo.ACT_Activo,
                    PER_Persona_Destino: data.persona.PER_Persona,
                    ARE_Area_Destino: data.area.ARE_Area,
                    MOV_Observacion: data.motivo_detalle.trim() || undefined,
                    MMO_Motivo: data.motivo?.id ?? null,
                    MOV_Motivo_Detalle: data.motivo_detalle.trim() || null,
                });
            } else {
                const payload: MovimientoCreate = {
                    ACT_Activo: data.activo.ACT_Activo,
                    PER_Persona: data.persona.PER_Persona,
                    ARE_Area: data.area.ARE_Area,
                    TMO_Tipo_Movimiento: data.tipo_movimiento.TMO_Tipo_Movimiento,
                    MOV_Observacion: data.motivo_detalle.trim() || undefined,
                    ACT_Hostname: data.hostname ?? '',
                    MMO_Motivo: data.motivo?.id ?? null,
                    MOV_Motivo_Detalle: data.motivo_detalle.trim() || null,
                    MOV_Fecha_Prevista_Devolucion: esPrestamo ? data.fecha_prevista : null,
                };
                mov = await movementService.createMovement(payload);
            }
            // Diálogo: se confirma lo hecho y se propone el siguiente paso.
            setResultado({ mov, persona: data.persona });
        }
        catch (error: unknown) {
            enqueueSnackbar(getApiErrorMessage(error, t('common.error_create')), { variant: 'error' });
        }
        finally {
            setSubmitting(false);
        }
    };
    const descargarActa = async () => {
        if (!resultado) return;
        try {
            await downloadFile(`/trazabilidad/acta/${resultado.mov.MOV_Movimiento}`, 'acta.pdf', {
                formato: 'pdf', tipo: 'entrega', lang: i18n.language.split('-')[0],
            });
        } catch (err) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
    };
    if (resultado) {
        const nombre = resultado.persona ? personLabel(resultado.persona) : '';
        return (<Dialog open={open} onClose={() => onClose(true)} maxWidth="sm" fullWidth>
          <DialogTitle>{t('operations.done.title')}</DialogTitle>
          <DialogContent>
            <Alert severity="success" sx={{ mb: 2 }}>
              {t(esTransferencia ? 'operations.done.transferred' : 'operations.done.assigned', {
                code: resultado.mov.activo?.ACT_Codigo_Interno ?? '', person: nombre, area: resultado.mov.area?.ARE_Nombre ?? '',
              })}
            </Alert>
            <Typography variant="body2" color="text.secondary">{t('operations.done.next')}</Typography>
          </DialogContent>
          <DialogActions sx={{ p: 3, flexWrap: 'wrap', gap: 1 }}>
            <Button onClick={() => onClose(true)} color="inherit">{t('common.close')}</Button>
            {puedeDeshacer && (<Button color="warning" onClick={() => void deshacer()}>{t('common.undo')}</Button>)}
            <Button onClick={() => {
                const persona = resultado.persona;
                setResultado(null);
                reset({ categoria: '', activo: null, persona, area: null, tipo_movimiento: null, observacion: '',
                    hostname: '', motivo: null, motivo_detalle: '', fecha_prevista: hoyLocal(7) });
            }}>{t('operations.done.assign_another')}</Button>
            <Button variant="contained" onClick={descargarActa}>{t('operations.done.download_acta')}</Button>
          </DialogActions>
        </Dialog>);
    }
    return (<Dialog open={open} onClose={() => onClose()} maxWidth="sm" fullWidth>
      <DialogTitle sx={{ fontWeight: 'bold' }}>
        {t('operations.dialog.title')}
      </DialogTitle>
      
      <form onSubmit={handleSubmit(onSubmit)}>
        <DialogContent>
          {loadingData ? (<Box display="flex" justifyContent="center" p={3}><CircularProgress /></Box>) : (<Stack spacing={2}>
              <SectionCard title={t('operations.sections.asset')}>
              <Box display="flex" flexDirection="column" gap={3}>
              {/* 1. (NUEVO) FILTRO DE CATEGORÍA */}
              <Controller name="categoria" control={control} defaultValue="" render={({ field }) => (<TextField {...field} select label={t('operations.dialog.filter_category')} fullWidth helperText={t('operations.dialog.filter_category_help')} onChange={(e) => {
                    field.onChange(e);
                    // Limpiamos el activo seleccionado si cambia la categoría (UX)
                    setValue('activo', null);
                }}>
                    {tiposActivo.map((tipo) => (<MenuItem key={tipo.TAC_Tipo_Activo} value={tipo.TAC_Tipo_Activo}>
                            {tipo.TAC_Nombre}
                        </MenuItem>))}
                  </TextField>)}/>

              {/* 2. SELECCIÓN DE ACTIVO (Modificado con Filtro) */}
              <Controller name="activo" control={control} rules={{ required: t('common.required') }} render={({ field: { onChange, value }, fieldState: { error } }) => (<Autocomplete options={activos} getOptionLabel={getActivoLabel} filterOptions={(opciones) => opciones} loading={buscandoActivo} autoHighlight value={value || null} isOptionEqualToValue={(option, selected) => option.ACT_Activo === selected.ACT_Activo} onInputChange={(_, texto, motivo) => { if (motivo === 'input') setBusquedaActivo(texto); }} noOptionsText={busquedaActivo.trim().length < 2 && !selectedCategory
                    ? t('operations.dialog.type_to_search_asset')
                    : t('operations.dialog.no_asset_found', { q: busquedaActivo.trim() })} onChange={(_, data) => onChange(data)} renderOption={(props, option) => (<Box component="li" {...props} key={option.ACT_Activo}>
                        <Box sx={{ minWidth: 0 }}>
                          <Typography variant="body2" sx={{ fontWeight: 700 }}>
                            {option.ACT_Codigo_Interno}
                          </Typography>
                          <Typography variant="caption" color="text.secondary" sx={{ overflowWrap: 'anywhere' }}>
                            {option.ACT_Hostname || t('assets.table.no_host')} / {option.ACT_Serie_Fabricante}
                          </Typography>
                        </Box>
                      </Box>)} renderInput={(params) => (<TextField {...params} required label={t('operations.dialog.select_asset')} placeholder={t('operations.dialog.asset_search_placeholder')} error={!!error} helperText={error?.message}/>)}/>)}/>
              {custodyLine && (<Box sx={{ mt: -2.5 }}>
                  <ContextCaption>{custodyLine}</ContextCaption>
                </Box>)}

              {/* HOSTNAME del equipo (al asignar a un nuevo usuario) */}
              <Controller name="hostname" control={control} render={({ field }) => (<TextField {...field} fullWidth label={t('operations.dialog.hostname')} placeholder={t('operations.dialog.hostname_placeholder')} helperText={t('operations.dialog.hostname_help')} inputProps={{ maxLength: 100 }}/>)}/>
              {hostnameHint && (<Box sx={{ mt: -2.5 }}>
                  <SuggestionHint hint={hostnameHint}/>
                </Box>)}
              </Box>
              </SectionCard>

              <SectionCard title={t('operations.sections.assignment')}>
              <Box display="flex" flexDirection="column" gap={3}>
              {/* 3. SELECCIÓN DE PERSONA (Producción) */}
              <Controller name="persona" control={control} rules={{ required: t('common.required') }} render={({ field: { onChange, value }, fieldState: { error } }) => (<Autocomplete options={personas} getOptionLabel={(option) => `${personLabel(option)} (${option.PER_Email_Corporativo || t('users.dialog.no_email')})`} filterOptions={personFilter} autoHighlight openOnFocus={false} isOptionEqualToValue={(option, selected) => option.PER_Persona === selected.PER_Persona} noOptionsText={t('common.type_to_search')} value={value || null} onChange={(_, data) => onChange(data)} renderOption={(props, option) => (<Box component="li" {...props} key={option.PER_Persona}>
                        <Box sx={{ minWidth: 0 }}>
                          <Typography variant="body2" sx={{ fontWeight: 700 }}>
                            {personLabel(option)}
                          </Typography>
                          <Typography variant="caption" color="text.secondary" sx={{ overflowWrap: 'anywhere' }}>
                            {option.PER_Email_Corporativo}
                          </Typography>
                        </Box>
                      </Box>)} renderInput={(params) => (<TextField {...params} required label={t('operations.dialog.select_person')} error={!!error} helperText={error?.message}/>)}/>)}/>
              {(personLine || personAssets.length > 0) && (<Box sx={{ mt: -2.5 }}>
                  {personLine && <ContextCaption>{personLine}</ContextCaption>}
                  {personAssets.length > 0 && (<ContextCaption>{t('suggestions.info.has_assets', { list: shortList(personAssets) })}</ContextCaption>)}
                </Box>)}

              {/* 4. UBICACIÓN Y TIPO (En fila - Producción) */}
              <Box display="flex" gap={2} alignItems="flex-start">
                <Box sx={{ flex: 1, minWidth: 0 }}>
                <Controller name="area" control={control} rules={{ required: true }} render={({ field: { onChange, value }, fieldState: { error } }) => (<Autocomplete options={areas} getOptionLabel={(option) => option.ARE_Nombre} value={value || null} onChange={(_, data) => onChange(data)} renderInput={(params) => (<TextField {...params} label={t('operations.dialog.select_area')} error={!!error}/>)}/>)}/>
                <SuggestionHint hint={areaHint}/>
                </Box>
                <Box sx={{ flex: 1, minWidth: 0 }}>

                <Controller name="tipo_movimiento" control={control} rules={{ required: true }} render={({ field: { onChange, value }, fieldState: { error } }) => (<Autocomplete options={tiposPermitidos} getOptionLabel={(option) => movementTypeLabel(t, option.TMO_Nombre)} value={value || null} onChange={(_, data) => onChange(data)} renderInput={(params) => (<TextField {...params} label={t('operations.dialog.select_type')} error={!!error}/>)}/>)}/>
                <SuggestionHint hint={typeHint}/>
                </Box>
              </Box>
              </Box>
              </SectionCard>

              <SectionCard title={t('movement_reason.section')}>
              <Box display="flex" flexDirection="column" gap={2}>
              {/* 5. OBSERVACIONES (Producción - Controller seguro) */}
              {esPrestamo && (<Controller name="fecha_prevista" control={control} render={({ field }) => (<DateField {...field} required fullWidth label={t('operations.dialog.loan_return_date')} helperText={t('operations.dialog.loan_return_help')} InputLabelProps={{ shrink: true }} inputProps={{ min: hoyLocal() }}/>)}/>)}
              {/* Un motivo del catálogo (obligatorio) y una sola nota: lo que queda en el kardex y en el acta. */}
              <Controller name="motivo" control={control} rules={{ required: t('movement_reason.required') }} render={({ field, fieldState: { error } }) => (<CatalogField clave="motivos-movimiento" aplicaA={aplicaA} required error={!!error} helperText={error?.message} label={t(esTransferencia ? 'operations.dialog.transfer_reason' : 'movement_reason.reason')} value={field.value?.id ?? null} onChange={(_v, item) => field.onChange(item)}/>)}/>
              <Controller name="motivo_detalle" control={control} rules={{ validate: (v) => !pideDetalle(motivoActual) || !!v.trim() || t('returns.detail_required') }} render={({ field, fieldState: { error } }) => (<TextField {...field} label={t('movement_reason.note')} multiline minRows={2} fullWidth inputProps={{ maxLength: 255 }} error={!!error} helperText={error?.message ?? t('movement_reason.note_help')}/>)}/>

              <Typography variant="caption" color="text.secondary" sx={{ fontStyle: 'italic' }}>
                {t('operations.dialog.auto_close_note')}
              </Typography>
              </Box>
              </SectionCard>
            </Stack>)}
        </DialogContent>
        <DialogActions sx={{ p: 3 }}>
          <Button onClick={() => onClose()} color="inherit">
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="contained" disabled={submitting || loadingData}>
            {submitting ? <CircularProgress size={24}/> : t('common.save')}
          </Button>
        </DialogActions>
      </form>
    </Dialog>);
};
