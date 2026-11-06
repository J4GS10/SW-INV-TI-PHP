import { useEffect, useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import { Alert, DialogTitle, DialogContent, DialogActions, Button, TextField, MenuItem, CircularProgress, Box, Stack, Autocomplete, Typography } from '@mui/material';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import AssignmentIndIcon from '@mui/icons-material/AssignmentInd';
import QrCode2Icon from '@mui/icons-material/QrCode2';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import { AssignmentDialog } from '../operations/AssignmentDialog';
import { normalizeLanguage } from '../../types/language';
import { catalogSlug } from '../../utils/catalogLabels';
import { createFilterOptions } from '@mui/material/Autocomplete';
import { MoneyField, SectionCard, SedeField, SuggestionHint } from '../../components/ui';
import { DEFAULT_CURRENCY } from '../../utils/currency';
import { useSedes } from '../../hooks/useSedes';
import { useTranslation } from 'react-i18next';
import { stateLabel } from '../../utils/catalogLabels';
import { useSnackbar } from 'notistack';
import { getApiErrorCode, getApiErrorMessage } from '../../api/errors';
import { goodsReceiptService } from '../goods-receipts/goodsReceiptService';
import { ActivoUpdate, assetService } from './assetService';
import { Activo, ActivoCreate, EstadoOperativo, Marca, ModeloFlat, TipoActivo } from '../../types/inventory';
import { useEntityContext } from '../../hooks/useEntityContext';
import { usePermissions } from '../../hooks/usePermissions';
import { reglasDe } from '../../utils/clasesActivo';
import { useSuggestion } from '../../hooks/useSuggestion';
import { suggestionService } from '../../api/suggestionService';
import { Dialog } from '../../components/ui/Dialog';
import { DateField } from '../../components/ui/DateField';
interface Props {
    open: boolean;
    assetToEdit?: Activo | null;
    onClose: (refresh?: boolean) => void;
}
interface FormValues {
    ACT_Codigo_Interno: string;
    ACT_Serie_Fabricante: string;
    ACT_Hostname: string;
    ACT_Fecha_Compra: string;
    ACT_Fin_Garantia: string;
    ACT_Costo: string;
    ACT_Moneda: string;
    TAC_Tipo_Activo: number | '';
    MAR_Marca: number | '';
    MOD_Modelo: number | '';
    EOP_Estado_Operativo: number | '';
    SED_Sede: number | '';
}
const emptyForm: FormValues = {
    ACT_Codigo_Interno: '',
    ACT_Serie_Fabricante: '',
    ACT_Hostname: '',
    ACT_Fecha_Compra: '',
    ACT_Fin_Garantia: '',
    ACT_Costo: '',
    ACT_Moneda: DEFAULT_CURRENCY,
    TAC_Tipo_Activo: '',
    MAR_Marca: '',
    MOD_Modelo: '',
    EOP_Estado_Operativo: '',
    SED_Sede: '',
};
/** Relleno de la secuencia del código interno (backend: prefijo + 5 dígitos). */
const CODE_PADDING = '#####';
const modelLabel = (m: ModeloFlat) => (m.MAR_Nombre ? `${m.MAR_Nombre} · ${m.MOD_Nombre}` : m.MOD_Nombre);
const modelFilter = createFilterOptions<ModeloFlat>({ ignoreAccents: true, ignoreCase: true, trim: true, limit: 50, stringify: modelLabel });
export const AssetDialog = ({ open, assetToEdit, onClose }: Props) => {
    const { t, i18n } = useTranslation();
    const { canSeeCosts } = usePermissions();
    const { enqueueSnackbar } = useSnackbar();
    const isEdit = !!assetToEdit;
    // Tras crear: el diálogo propone el siguiente paso (asignar, etiqueta, otro igual).
    const [creado, setCreado] = useState<Activo | null>(null);
    const [asignando, setAsignando] = useState(false);
    const { control, handleSubmit, reset, watch, setValue, setError, clearErrors, formState: { errors } } = useForm<FormValues>({ mode: 'onChange',
        defaultValues: emptyForm,
    });
    const [marcas, setMarcas] = useState<Marca[]>([]);
    const [modelos, setModelos] = useState<ModeloFlat[]>([]);
    const [tipos, setTipos] = useState<TipoActivo[]>([]);
    const [estados, setEstados] = useState<EstadoOperativo[]>([]);
    const [loading, setLoading] = useState(false);
    const [loadingModelos, setLoadingModelos] = useState(false);
    const marcaSeleccionada = watch('MAR_Marca');
    const tipoSeleccionado = watch('TAC_Tipo_Activo');
    const modeloSeleccionado = watch('MOD_Modelo');
    const codigo = watch('ACT_Codigo_Interno');
    const sedeSeleccionada = watch('SED_Sede');
    // Sedes del alcance; con una sola se prellena como sugerencia.
    const { sedes, loading: loadingSedes, unica } = useSedes(open);
    const sedeHint = useSuggestion<number | ''>({
        current: sedeSeleccionada,
        empty: '',
        enabled: open && !isEdit,
        apply: (value) => setValue('SED_Sede', value, { shouldDirty: true, shouldValidate: value !== '' }),
        suggestion: loadingSedes ? undefined : unica
            ? { value: unica.SED_Sede, label: unica.SED_Nombre, reason: t('scope.reasons.only_sede') }
            : null,
    });
    // Cargar catálogos al abrir (todos los modelos con su marca, en una sola consulta)
    useEffect(() => {
        if (!open)
            return;
        setLoadingModelos(true);
        Promise.all([
            assetService.getMarcas(),
            assetService.getTipos(),
            assetService.getEstados(),
            assetService.getModelosFlat(),
        ])
            .then(([m, ti, e, mo]) => {
            setMarcas(m);
            setTipos(ti);
            setEstados(e);
            setModelos(mo);
            // Alta: siempre entra en bodega; los demás estados los fija su operación.
            const bodega = e.find((x) => catalogSlug(x.EOP_Nombre) === 'en_bodega');
            if (!assetToEdit && bodega)
                setValue('EOP_Estado_Operativo', bodega.EOP_Estado_Operativo);
        })
            .catch(() => enqueueSnackbar(t('common.error_loading'), { variant: 'error' }))
            .finally(() => setLoadingModelos(false));
    }, [open, enqueueSnackbar, t, assetToEdit, setValue]);
    // Pre-llenar form al editar (la marca se deduce del modelo, ver efecto siguiente)
    useEffect(() => {
        if (!open)
            return;
        if (assetToEdit) {
            reset({
                ACT_Codigo_Interno: assetToEdit.ACT_Codigo_Interno ?? '',
                ACT_Serie_Fabricante: assetToEdit.ACT_Serie_Fabricante ?? '',
                ACT_Hostname: assetToEdit.ACT_Hostname ?? '',
                ACT_Fecha_Compra: assetToEdit.ACT_Fecha_Compra ?? '',
                ACT_Fin_Garantia: assetToEdit.ACT_Fin_Garantia ?? '',
                ACT_Costo: assetToEdit.ACT_Costo != null ? String(assetToEdit.ACT_Costo) : '',
                ACT_Moneda: assetToEdit.ACT_Moneda ?? DEFAULT_CURRENCY,
                TAC_Tipo_Activo: assetToEdit.TAC_Tipo_Activo,
                MAR_Marca: '',
                MOD_Modelo: assetToEdit.MOD_Modelo,
                EOP_Estado_Operativo: assetToEdit.EOP_Estado_Operativo,
                SED_Sede: assetToEdit.SED_Sede ?? '',
            });
        }
        else {
            reset(emptyForm);
        }
    }, [open, assetToEdit, reset]);
    const modeloActual = useMemo(() => modelos.find((m) => m.MOD_Modelo === modeloSeleccionado) ?? null, [modelos, modeloSeleccionado]);
    // El modelo determina la marca: al elegirlo (o al editar) la marca se fija sola.
    useEffect(() => {
        if (modeloActual && marcaSeleccionada !== modeloActual.MAR_Marca) {
            setValue('MAR_Marca', modeloActual.MAR_Marca);
        }
    }, [modeloActual, marcaSeleccionada, setValue]);
    // Tipo sugerido por el modelo: al crear, o si en edición se cambia el modelo.
    const suggestEnabled = !isEdit || modeloSeleccionado !== assetToEdit?.MOD_Modelo;
    const modelCtx = useEntityContext(open && suggestEnabled ? modeloSeleccionado : null, suggestionService.modelo);
    const tipoSugerido = useMemo(() => {
        const ctxTipo = modelCtx.data?.tipo_sugerido;
        if (ctxTipo && tipos.some((ti) => ti.TAC_Tipo_Activo === ctxTipo.TAC_Tipo_Activo)) {
            return { value: ctxTipo.TAC_Tipo_Activo, label: ctxTipo.nombre, reason: t('suggestions.reasons.type_model_assets') };
        }
        const catalogTipo = tipos.find((ti) => ti.TAC_Tipo_Activo === modeloActual?.TAC_Tipo_Activo);
        return catalogTipo
            ? { value: catalogTipo.TAC_Tipo_Activo, label: catalogTipo.TAC_Nombre, reason: t('suggestions.reasons.type_model_catalog') }
            : null;
    }, [modelCtx.data, tipos, modeloActual, t]);
    const tipoHint = useSuggestion<number | ''>({
        current: tipoSeleccionado,
        empty: '',
        enabled: suggestEnabled,
        apply: (value) => setValue('TAC_Tipo_Activo', value, { shouldDirty: true, shouldValidate: value !== '' }),
        suggestion: modelCtx.loading ? undefined : tipoSugerido,
    });
    // Cascada: la marca filtra los modelos; el tipo también, salvo que sea la
    // sugerencia del propio modelo (si no, no se podría cambiar de modelo).
    const filtrarPorTipo = !!tipoSeleccionado && !(tipoHint && !tipoHint.modified);
    const modelosFiltrados = useMemo(() => {
        const visibles = modelos.filter((m) => (!marcaSeleccionada || m.MAR_Marca === Number(marcaSeleccionada))
            && (!filtrarPorTipo || m.TAC_Tipo_Activo == null || m.TAC_Tipo_Activo === Number(tipoSeleccionado)));
        return modeloActual && !visibles.includes(modeloActual) ? [modeloActual, ...visibles] : visibles;
    }, [modelos, marcaSeleccionada, filtrarPorTipo, tipoSeleccionado, modeloActual]);
    // Código que se generará si el campo queda vacío.
    const tipoActual = tipos.find((ti) => ti.TAC_Tipo_Activo === tipoSeleccionado);
    const codeHelp = isEdit || codigo || !tipoActual
        ? t('assets.dialog.auto_code_help')
        : tipoActual.TAC_Prefijo
            ? t('assets.dialog.code_preview', { code: `${tipoActual.TAC_Prefijo}${CODE_PADDING}` })
            : t('assets.dialog.code_no_prefix');
    // Solo se da de alta en almacén; los demás estados los fija la operación
    // (asignar, préstamo, mantenimiento, baja) desde la ficha del activo.
    const ALMACEN = ['en_bodega'];
    const estadoActual = estados.find((e) => e.EOP_Estado_Operativo === assetToEdit?.EOP_Estado_Operativo);
    const estadoBloqueado = isEdit && !!estadoActual && !ALMACEN.includes(catalogSlug(estadoActual.EOP_Nombre));
    const estadosPermitidos = estadoBloqueado && estadoActual
        ? [estadoActual]
        : estados.filter((e) => ALMACEN.includes(catalogSlug(e.EOP_Nombre)));
    const registrarOtroIgual = () => {
        // Conserva modelo, tipo, sede, fechas y costo; limpia lo que identifica a la unidad.
        setValue('ACT_Serie_Fabricante', '');
        setValue('ACT_Codigo_Interno', '');
        setValue('ACT_Hostname', '');
        setCreado(null);
    };
    const imprimirEtiqueta = async () => {
        if (!creado) return;
        try {
            const blob = await assetService.downloadLabels({ activos_ids: [creado.ACT_Activo] }, normalizeLanguage(i18n.language));
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `etiqueta-${creado.ACT_Codigo_Interno}.pdf`;
            a.click();
            window.setTimeout(() => URL.revokeObjectURL(url), 10_000);
        }
        catch (err) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
    };
    // Serie y nombre de host duplicados se avisan al salir del campo, no al guardar.
    const avisarDuplicado = async (campo: 'ACT_Serie_Fabricante' | 'ACT_Hostname', valor: string) => {
        const texto = (valor ?? '').trim();
        if (!texto) return;
        const existe = await (campo === 'ACT_Serie_Fabricante' ? goodsReceiptService.serialExists(texto, assetToEdit?.ACT_Activo) : goodsReceiptService.hostnameExists(texto, assetToEdit?.ACT_Activo)).catch(() => false);
        if (existe) setError(campo, { type: 'server', message: t(campo === 'ACT_Serie_Fabricante' ? 'goods_receipt.errors.serial_taken' : 'goods_receipt.errors.hostname_taken') });
        else clearErrors(campo);
    };
    const onSubmit = async (data: FormValues) => {
        setLoading(true);
        try {
            const payload: ActivoUpdate = {
                ACT_Serie_Fabricante: data.ACT_Serie_Fabricante,
                ACT_Hostname: data.ACT_Hostname || null,
                ACT_Fecha_Compra: data.ACT_Fecha_Compra,
                ACT_Fin_Garantia: data.ACT_Fin_Garantia || null,
                // Quien no ve costos no los envía (el servidor también los ignora).
                ...(canSeeCosts ? { ACT_Costo: data.ACT_Costo ? Number(data.ACT_Costo) : null, ACT_Moneda: data.ACT_Moneda } : {}),
                TAC_Tipo_Activo: Number(data.TAC_Tipo_Activo),
                MOD_Modelo: Number(data.MOD_Modelo),
                EOP_Estado_Operativo: Number(data.EOP_Estado_Operativo),
                SED_Sede: data.SED_Sede === '' ? null : Number(data.SED_Sede),
            };
            if (data.ACT_Codigo_Interno)
                payload.ACT_Codigo_Interno = data.ACT_Codigo_Interno;
            if (isEdit && assetToEdit) {
                await assetService.updateActivo(assetToEdit.ACT_Activo, payload as ActivoUpdate);
                enqueueSnackbar(t('common.success_update'), { variant: 'success' });
            }
            else {
                setCreado(await assetService.createActivo(payload as ActivoCreate));
                enqueueSnackbar(t('assets.messages.success_create'), { variant: 'success' });
                return;
            }
            onClose(true);
        }
        catch (err: unknown) {
            const codigo = getApiErrorCode(err);
            if (codigo === 'HOSTNAME_ALREADY_EXISTS') setError('ACT_Hostname', { type: 'server', message: t('goods_receipt.errors.hostname_taken') });
            if (codigo === 'SERIAL_NUMBER_ALREADY_EXISTS' || codigo === 'NEW_SERIAL_NUMBER_ALREADY_EXISTS') setError('ACT_Serie_Fabricante', { type: 'server', message: t('goods_receipt.errors.serial_taken') });
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    };
    if (creado) {
        return (<Dialog open={open} onClose={() => { setCreado(null); onClose(true); }} maxWidth="sm" fullWidth>
          <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <CheckCircleIcon color="success"/> {t('assets.created.title', { code: creado.ACT_Codigo_Interno })}
          </DialogTitle>
          <DialogContent>
            <Alert severity="success" sx={{ mb: 2 }}>{t('assets.created.message', { code: creado.ACT_Codigo_Interno, serial: creado.ACT_Serie_Fabricante })}</Alert>
            <Typography variant="subtitle2" gutterBottom>{t('operations.done.next')}</Typography>
            <Stack spacing={1}>
              <Button variant="contained" startIcon={<AssignmentIndIcon />} onClick={() => setAsignando(true)}>{t('assets.created.assign')}</Button>
              <Button variant="outlined" startIcon={<QrCode2Icon />} onClick={imprimirEtiqueta}>{t('assets.created.print_label')}</Button>
              <Button variant="outlined" startIcon={<ContentCopyIcon />} onClick={registrarOtroIgual}>{t('assets.created.another')}</Button>
            </Stack>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => { setCreado(null); onClose(true); }}>{t('common.close')}</Button>
          </DialogActions>
          <AssignmentDialog open={asignando} activoInicial={creado} onClose={(refresh) => {
                setAsignando(false);
                if (refresh) {
                    setCreado(null);
                    onClose(true);
                }
            }}/>
        </Dialog>);
    }
    return (<Dialog open={open} onClose={() => onClose()} maxWidth="md" fullWidth>
      <DialogTitle sx={{ fontWeight: 'bold' }}>
        {isEdit ? t('assets.dialog.edit_title') : t('assets.dialog.title')}
      </DialogTitle>
      <form onSubmit={handleSubmit(onSubmit)} noValidate>
        <DialogContent>
          <Stack spacing={2}>
          <SectionCard title={t('assets.dialog.classification')}>
          <Box sx={{ display: 'flex', gap: 2, flexDirection: { xs: 'column', sm: 'row' }, alignItems: { sm: 'flex-start' } }}>
            <Box sx={{ flex: 2, minWidth: 0, width: '100%' }}>
            <Controller name="MOD_Modelo" control={control} rules={{ required: true }} render={({ field }) => (<Autocomplete options={modelosFiltrados} value={modeloActual} onChange={(_, m) => field.onChange(m ? m.MOD_Modelo : '')} getOptionLabel={modelLabel} filterOptions={modelFilter} autoHighlight loading={loadingModelos} isOptionEqualToValue={(option, selected) => option.MOD_Modelo === selected.MOD_Modelo} noOptionsText={marcaSeleccionada ? t('assets.dialog.no_models_for_brand') : t('assets.dialog.no_models')} renderOption={(props, m) => (<Box component="li" {...props} key={m.MOD_Modelo}>
                    <Box sx={{ minWidth: 0 }}>
                      <Typography variant="body2" sx={{ fontWeight: 700 }}>{m.MOD_Nombre}</Typography>
                      <Typography variant="caption" color="text.secondary">{m.MAR_Nombre}</Typography>
                    </Box>
                  </Box>)} renderInput={(params) => (<TextField {...params} fullWidth required label={t('assets.dialog.model')} placeholder={t('assets.dialog.model_placeholder')} error={!!errors.MOD_Modelo} helperText={loadingModelos ? t('assets.dialog.loading_models') : undefined}/>)}/>)}/>
            </Box>

            {/* La marca solo filtra: el modelo la determina. */}
            <Controller name="MAR_Marca" control={control} render={({ field }) => (<TextField {...field} select fullWidth sx={{ flex: 1, minWidth: 0 }} label={t('assets.dialog.brand_filter')} onChange={(e) => {
                const value = e.target.value === '' ? '' : Number(e.target.value);
                field.onChange(value);
                if (modeloActual && value !== '' && modeloActual.MAR_Marca !== value)
                    setValue('MOD_Modelo', '');
            }}>
                  <MenuItem value="">{t('assets.dialog.all_brands')}</MenuItem>
                  {marcas.map((m) => (<MenuItem key={m.MAR_Marca} value={m.MAR_Marca}>
                      {m.MAR_Nombre}
                    </MenuItem>))}
                </TextField>)}/>

            <Box sx={{ flex: 1, minWidth: 0, width: '100%' }}>
            <Controller name="TAC_Tipo_Activo" control={control} rules={{ required: true }} render={({ field }) => (<TextField {...field} select fullWidth required label={t('assets.dialog.type')} error={!!errors.TAC_Tipo_Activo} helperText={tipoHint ? undefined : t('assets.dialog.prefix_hint')}>
                  {/* Un insumo no se registra como activo (se controla en Consumibles). */}
                  {tipos.filter((ti) => ti.TAC_Clase !== 'INSUMO' || ti.TAC_Tipo_Activo === assetToEdit?.TAC_Tipo_Activo).map((ti) => (<MenuItem key={ti.TAC_Tipo_Activo} value={ti.TAC_Tipo_Activo}>
                      {ti.TAC_Nombre} {ti.TAC_Prefijo ? `(${ti.TAC_Prefijo})` : ''}
                      {ti.TAC_Clase && <Typography component="span" variant="caption" color="text.secondary" sx={{ ml: 1 }}>· {t(`asset_class.names.${ti.TAC_Clase}`)}</Typography>}
                    </MenuItem>))}
                </TextField>)}/>
            <SuggestionHint hint={tipoHint}/>
            </Box>
          </Box>
          </SectionCard>

          <SectionCard title={t('assets.dialog.identification')}>
          <Box sx={{ display: 'flex', gap: 2, flexDirection: { xs: 'column', sm: 'row' } }}>
            <Controller name="ACT_Serie_Fabricante" control={control} rules={{ required: true }} render={({ field }) => (<TextField {...field} fullWidth label={t('assets.dialog.serial')} onBlur={() => { field.onBlur(); void avisarDuplicado('ACT_Serie_Fabricante', field.value); }} error={!!errors.ACT_Serie_Fabricante} helperText={errors.ACT_Serie_Fabricante?.message || (errors.ACT_Serie_Fabricante ? t('common.required') : undefined)} required/>)}/>
            <Controller name="ACT_Codigo_Interno" control={control} render={({ field }) => (<TextField {...field} fullWidth label={t('assets.dialog.code')} placeholder={t('assets.dialog.code_placeholder')} helperText={codeHelp} disabled={isEdit}/>)} />
            {/* Hostname solo en equipos con identidad de red (no en un mouse o un monitor). */}
            {(!tipoActual || reglasDe(tipoActual).red || !!watch('ACT_Hostname')) && <Controller name="ACT_Hostname" control={control} render={({ field }) => <TextField {...field} fullWidth label={t('assets.dialog.hostname')} onBlur={() => { field.onBlur(); void avisarDuplicado('ACT_Hostname', field.value); }} error={!!errors.ACT_Hostname} helperText={errors.ACT_Hostname?.message}/>}/>}
          </Box>
          <Box sx={{ mt: 2 }}>
            <Controller name="SED_Sede" control={control} rules={{ required: !isEdit }} render={({ field }) => (<SedeField value={field.value} onChange={field.onChange} sedes={sedes} loading={loadingSedes} required={!isEdit} error={!!errors.SED_Sede} helperText={sedeHint ? undefined : t('scope.asset_sede_help')}/>)}/>
            <SuggestionHint hint={sedeHint}/>
          </Box>
          </SectionCard>

          <SectionCard title={t('assets.dialog.financial_state')}>
          <Box sx={{ display: 'flex', gap: 2, flexDirection: { xs: 'column', sm: 'row' } }}>
            <Controller name="ACT_Fecha_Compra" control={control} rules={{ required: true }} render={({ field }) => (<DateField {...field} fullWidth label={t('assets.dialog.purchase_date')} InputLabelProps={{ shrink: true }} error={!!errors.ACT_Fecha_Compra} helperText={errors.ACT_Fecha_Compra ? t('common.required') : undefined} required/>)}/>
            <Controller name="ACT_Fin_Garantia" control={control} render={({ field }) => (<DateField {...field} fullWidth label={t('assets.dialog.warranty_end')} InputLabelProps={{ shrink: true }}/>)}/>
            {canSeeCosts && <Controller name="ACT_Moneda" control={control} render={({ field: moneda }) => (<Controller name="ACT_Costo" control={control} render={({ field }) => (<MoneyField name={field.name} value={field.value} onChange={field.onChange} onBlur={field.onBlur} currency={moneda.value} onCurrencyChange={moneda.onChange} fullWidth label={t('assets.dialog.cost')}/>)}/>)}/>}
            {estadoBloqueado && <Controller name="EOP_Estado_Operativo" control={control} rules={{ required: true }} render={({ field }) => (<TextField {...field} select fullWidth label={t('assets.dialog.operational_status')} error={!!errors.EOP_Estado_Operativo} disabled={estadoBloqueado} helperText={estadoBloqueado ? t('assets.dialog.state_locked_help') : errors.EOP_Estado_Operativo ? t('common.required') : t('assets.dialog.state_storage_help')}>
                  {estadosPermitidos.map((e) => (<MenuItem key={e.EOP_Estado_Operativo} value={e.EOP_Estado_Operativo}>
                      {stateLabel(t, e.EOP_Nombre)}
                    </MenuItem>))}
                </TextField>)}/>}
          </Box>
          </SectionCard>
          </Stack>
        </DialogContent>
        <DialogActions sx={{ p: 3 }}>
          <Button onClick={() => onClose()} color="inherit" variant="outlined">
            {t('common.cancel')}
          </Button>
          <Button type="submit" variant="contained" disabled={loading} sx={{ px: 4 }}>
            {loading ? <CircularProgress size={24} color="inherit"/> : t('common.save')}
          </Button>
        </DialogActions>
      </form>
    </Dialog>);
};
