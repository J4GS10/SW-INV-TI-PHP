import { useEffect, useMemo, useState } from 'react';
import { Controller, useForm } from 'react-hook-form';
import {
  Alert, Box, Button, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, MenuItem, Stack, TextField, Typography,
} from '@mui/material';
import CheckCircleIcon from '@mui/icons-material/CheckCircleOutline';
import WarningIcon from '@mui/icons-material/WarningAmberOutlined';
import ErrorIcon from '@mui/icons-material/ErrorOutline';
import { useTranslation } from 'react-i18next';
import { SectionCard } from '../../../components/ui';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../../api/errors';
import { apiCache } from '../../../api/cache';
import { catalogService, type ComponenteDeTipo } from '../catalogService';
import { TipoActivo } from '../../../types/inventory';
import { TipoActivoCreate } from '../../../types/catalogs';
import { CLASES_ACTIVO, type ClaseActivo } from '../../../utils/clasesActivo';
import { Dialog } from '../../../components/ui/Dialog';
import { withRowVersion } from '../../../api/rowVersion';
import { catalogoConfigService, type EspecificacionTipo } from '../../../api/catalogoService';
import { accountingService } from '../../accounting/accountingService';
import { prefijoDesde, prefijoLibre } from '../../../utils/prefijo';
import type { AccountingCategory } from '../../accounting/accountingService';
import { TypeSpecsEditor, type TypeSpecRow } from './TypeSpecsEditor';
import { TypeComponentsEditor } from './TypeComponentsEditor';
import { CON_COMPONENTES, isReady, typeReadiness, type ReadinessLevel } from './typeReadiness';
import { specsDuplicatedByComponents } from './specComponentOverlap';

interface Props {
  open: boolean;
  onClose: (refresh?: boolean) => void;
  typeToEdit?: TipoActivo;
  /** Todos los tipos: para elegir los componentes internos que admite. */
  tipos?: TipoActivo[];
}

type Formulario = Omit<TipoActivoCreate, 'TAC_Clase'> & { TAC_Clase: ClaseActivo | '' };

const ICONO: Record<ReadinessLevel, typeof CheckCircleIcon> = { ok: CheckCircleIcon, warning: WarningIcon, error: ErrorIcon };
const COLOR: Record<ReadinessLevel, 'success' | 'warning' | 'error'> = { ok: 'success', warning: 'warning', error: 'error' };

/**
 * Todo lo que define un tipo de activo, en una sola pantalla y en el orden en que se piensa:
 *  1. qué es (nombre, prefijo del código, clase y categoría contable);
 *  2. los datos de la ficha del equipo (procesador, pantalla…), en orden, y cuáles son obligatorios;
 *  3. las piezas internas con serie propia (memoria, disco, batería) y cuáles se exigen al registrarlo.
 * Un dato va en un solo paso: lo que es pieza interna no se pide también como dato del equipo.
 * Arriba, la lista de lo que falta para que sea apto para registrar activos.
 */
export const AssetTypeDialog = ({ open, onClose, typeToEdit, tipos = [] }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const { register, handleSubmit, reset, control, watch, setValue } = useForm<Formulario>({ mode: 'onChange' });
  // El tipo que se edita: al crear uno nuevo, el diálogo sigue abierto sobre él para revisar lo que pide.
  const [actual, setActual] = useState<TipoActivo | undefined>(typeToEdit);
  const [huboCambios, setHuboCambios] = useState(false);
  const [recienCreado, setRecienCreado] = useState(false);
  const [prefijoAMano, setPrefijoAMano] = useState(false);
  const [loading, setLoading] = useState(false);
  const [categorias, setCategorias] = useState<AccountingCategory[]>([]);
  const [categoria, setCategoria] = useState<number | ''>('');
  const [specs, setSpecs] = useState<TypeSpecRow[]>([]);
  const [specsOriginal, setSpecsOriginal] = useState('[]');
  const [componentes, setComponentes] = useState<ComponenteDeTipo[]>([]);
  const [componentesOriginal, setComponentesOriginal] = useState('[]');
  const [nombresSpecs, setNombresSpecs] = useState<string[]>([]);
  const [nombrePorSpec, setNombrePorSpec] = useState<ReadonlyMap<number, string>>(new Map());

  const nombre = watch('TAC_Nombre') ?? '';
  const prefijo = watch('TAC_Prefijo') ?? '';
  const clase = watch('TAC_Clase') ?? '';
  const conComponentes = !!clase && CON_COMPONENTES.includes(clase);

  const cargarDetalle = async (id: number) => {
    apiCache.clear();
    const [todas, comps] = await Promise.all([
      catalogoConfigService.especificacionesPorTipo().catch((): Record<string, EspecificacionTipo[]> => ({})),
      catalogService.getComponentesDetalle(id).catch(() => []),
    ]);
    const filas = (todas[String(id)] ?? []).map(({ TES_Tipo_Especificacion, obligatoria, valores }) => ({ TES_Tipo_Especificacion, obligatoria, valores }));
    setSpecs(filas);
    setSpecsOriginal(JSON.stringify(filas));
    setNombresSpecs((todas[String(id)] ?? []).map((e) => e.nombre ?? '').filter(Boolean));
    setComponentes(comps);
    setComponentesOriginal(JSON.stringify(comps));
  };

  useEffect(() => {
    if (!open) return;
    setActual(typeToEdit);
    setHuboCambios(false);
    setRecienCreado(false);
    setPrefijoAMano(!!typeToEdit);
    setCategoria(typeToEdit?.FAC_ASSET_CATEGORY ?? '');
    reset({ TAC_Nombre: typeToEdit?.TAC_Nombre ?? '', TAC_Prefijo: typeToEdit?.TAC_Prefijo ?? '', TAC_Clase: typeToEdit?.TAC_Clase ?? '' });
    setSpecs([]); setSpecsOriginal('[]'); setComponentes([]); setComponentesOriginal('[]'); setNombresSpecs([]);
    accountingService.categories().then(setCategorias).catch(() => setCategorias([]));
    catalogService.getTiposEspecificacion()
      .then((l) => setNombrePorSpec(new Map(l.map((e) => [e.TES_Tipo_Especificacion, e.TES_Nombre]))))
      .catch(() => setNombrePorSpec(new Map()));
    if (typeToEdit) void cargarDetalle(typeToEdit.TAC_Tipo_Activo);
  }, [open, typeToEdit, reset]);

  // Alta: el prefijo se propone del nombre mientras nadie lo escriba a mano, sin repetir el de otro tipo («QEA» → «QEA2»).
  // Al editar no se toca: se mira la prop typeToEdit, porque al abrir el estado todavía es el del render anterior.
  useEffect(() => {
    if (!prefijoAMano && open && !actual && !typeToEdit) {
      const base = prefijoDesde(nombre);
      setValue('TAC_Prefijo', base && prefijoLibre(base, tipos.map((x) => x.TAC_Prefijo ?? '')), { shouldValidate: !!nombre });
    }
  }, [nombre, prefijoAMano, open, actual, typeToEdit, setValue, tipos]);

  const candidatos = useMemo(() => tipos.filter((x) => x.TAC_Clase === 'COMPONENTE'), [tipos]);
  const revision = typeReadiness({
    name: nombre, prefix: prefijo, assetClass: clase, classHasComponents: conComponentes, isSupply: clase === 'INSUMO',
    hasCategory: categoria !== '', specs: specs.map((s) => ({ required: s.obligatoria })),
    components: componentes.map((c) => ({ required: c.obligatorio })),
  });
  const apto = isReady(revision);

  // Lo que ya es pieza interna (paso 3) se anota en la pieza, con su serie: no se pide otra vez como dato del equipo.
  const nombreSpec = (id: number) => nombrePorSpec.get(id) ?? '';
  const duplicadas = conComponentes
    ? specsDuplicatedByComponents(specs.map((s) => s.TES_Tipo_Especificacion), nombrePorSpec, componentes.map((c) => c.nombre))
    : [];
  const quitarDuplicadas = (ids: number[]) => setSpecs((l) => l.filter((s) => !ids.includes(s.TES_Tipo_Especificacion)));
  const cambiarComponentes = (filas: ComponenteDeTipo[]) => {
    const nuevas = filas.filter((f) => !componentes.some((c) => c.tipo === f.tipo)).map((f) => f.nombre);
    const pasan = specsDuplicatedByComponents(specs.map((s) => s.TES_Tipo_Especificacion), nombrePorSpec, nuevas);
    setComponentes(filas);
    if (pasan.length) {
      quitarDuplicadas(pasan);
      enqueueSnackbar(t('asset_type_setup.moved_to_component', { names: pasan.map(nombreSpec).join(', ') }), { variant: 'info' });
    }
  };

  const cerrar = () => onClose(huboCambios);

  const onSubmit = async (data: Formulario) => {
    setLoading(true);
    try {
      const payload: TipoActivoCreate = {
        TAC_Nombre: data.TAC_Nombre.trim(),
        TAC_Prefijo: data.TAC_Prefijo ? data.TAC_Prefijo.toUpperCase() : undefined,
        ...(data.TAC_Clase ? { TAC_Clase: data.TAC_Clase } : {}),
        FAC_ASSET_CATEGORY: categoria === '' ? null : categoria,
      };
      const guardado = actual
        ? await catalogService.updateTipoActivo(actual.TAC_Tipo_Activo, withRowVersion(payload, actual))
        : await catalogService.createTipoActivo(payload);
      const id = guardado.TAC_Tipo_Activo;
      // Alta sin características elegidas: el servidor le puso las habituales de su clase; se muestran para revisarlas.
      if (!actual && specs.length === 0) {
        setActual(guardado);
        setHuboCambios(true);
        setRecienCreado(true);
        reset({ TAC_Nombre: guardado.TAC_Nombre, TAC_Prefijo: guardado.TAC_Prefijo ?? '', TAC_Clase: (guardado.TAC_Clase ?? '') as ClaseActivo | '' });
        setPrefijoAMano(true);
        if (componentes.length && CON_COMPONENTES.includes((guardado.TAC_Clase ?? '') as ClaseActivo)) {
          await catalogService.setComponentesDetalle(id, componentes.map(({ tipo, obligatorio, cantidad }) => ({ tipo, obligatorio, cantidad })));
        }
        await cargarDetalle(id);
        enqueueSnackbar(t('asset_type_setup.created_review', { name: guardado.TAC_Nombre }), { variant: 'success' });
        return;
      }
      if (JSON.stringify(specs) !== specsOriginal || !actual) {
        await catalogoConfigService.fijarEspecificaciones(id, specs);
      }
      if (CON_COMPONENTES.includes((guardado.TAC_Clase ?? data.TAC_Clase) as ClaseActivo) && JSON.stringify(componentes) !== componentesOriginal) {
        await catalogService.setComponentesDetalle(id, componentes.map(({ tipo, obligatorio, cantidad }) => ({ tipo, obligatorio, cantidad })));
      }
      apiCache.clear();
      enqueueSnackbar(t(actual ? 'common.success_update' : 'catalogs.types.success_create'), { variant: 'success' });
      onClose(true);
    } catch (error: unknown) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_save')), { variant: 'error' });
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog open={open} onClose={cerrar} maxWidth="md" fullWidth>
      <DialogTitle>{actual ? t('asset_type_setup.title_edit', { name: actual.TAC_Nombre }) : t('asset_type_setup.title_new')}</DialogTitle>
      <form onSubmit={handleSubmit(onSubmit)}>
        <DialogContent dividers>
          <Stack spacing={2}>
            {recienCreado && <Alert severity="success">{t('asset_type_setup.created_help')}</Alert>}
            {/* Lo que falta para registrar activos de este tipo, siempre a la vista. */}
            <Alert severity={apto ? (revision.some((r) => r.level === 'warning') ? 'info' : 'success') : 'error'} icon={false}>
              <Typography variant="subtitle2" gutterBottom>{apto ? t('asset_type_setup.ready') : t('asset_type_setup.not_ready')}</Typography>
              <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                {revision.map((r) => {
                  const Icono = ICONO[r.level];
                  return <Chip key={r.key} size="small" variant="outlined" color={COLOR[r.level]} icon={<Icono />}
                    label={t(`asset_type_setup.checks.${r.key}.${r.level}`, r.values ?? {})} />;
                })}
              </Stack>
            </Alert>

            <SectionCard title={t('asset_type_setup.step1')} subtitle={t('asset_type_setup.step1_help')}>
              <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '2fr 1fr' }, gap: 2 }}>
                <TextField autoFocus fullWidth required label={t('catalogs.types.field_name')} placeholder={t('asset_type_setup.name_placeholder')}
                  {...register('TAC_Nombre', { required: true, minLength: 2 })}/>
                <TextField fullWidth required label={t('catalogs.types.field_prefix')} placeholder="LAP"
                  slotProps={{ htmlInput: { style: { textTransform: 'uppercase' }, maxLength: 5 }, inputLabel: { shrink: true } }}
                  helperText={t('asset_type_setup.prefix_help', { example: `${(prefijo || 'LAP').toUpperCase()}00001` })}
                  {...register('TAC_Prefijo', { required: true, minLength: 2, maxLength: 5, onChange: () => setPrefijoAMano(true) })}/>
                <Controller name="TAC_Clase" control={control} render={({ field }) => (
                  <TextField select fullWidth label={t('asset_class.field')} value={field.value ?? ''} onChange={field.onChange}
                    helperText={field.value ? t(`asset_class.help.${field.value}`) : t('asset_class.auto')}>
                    <MenuItem value="">{t('asset_class.auto_option')}</MenuItem>
                    {CLASES_ACTIVO.map((c) => <MenuItem key={c} value={c}>{t(`asset_class.names.${c}`)}</MenuItem>)}
                  </TextField>
                )}/>
                <TextField select fullWidth label={t('accounting.category')} value={categoria}
                  onChange={(e) => setCategoria(e.target.value === '' ? '' : Number(e.target.value))}
                  helperText={t('accounting.type_category_help')}>
                  <MenuItem value="">{t('accounting.without_category')}</MenuItem>
                  {categorias.filter((c) => c.active || c.id === categoria).map((c) => (
                    <MenuItem key={c.id} value={c.id}>{`${c.country} · ${c.name}${c.method === 'NONE' ? '' : ` (${c.annual_rate} %)`}`}</MenuItem>
                  ))}
                </TextField>
              </Box>
              {clase === 'INSUMO' && <Alert severity="warning" sx={{ mt: 2 }}>{t('asset_class.supply_notice')}</Alert>}
            </SectionCard>

            {clase !== 'INSUMO' && (
              <SectionCard title={t('asset_type_setup.step2')} subtitle={t('asset_type_setup.step2_help')}>
                {!actual && specs.length === 0 && <Alert severity="info" sx={{ mb: 1.5 }}>{t('asset_type_setup.specs.template_note')}</Alert>}
                {duplicadas.length > 0 && (
                  <Alert severity="warning" sx={{ mb: 1.5 }} action={
                    <Button color="inherit" size="small" onClick={() => quitarDuplicadas(duplicadas)}>{t('asset_type_setup.duplicated_fix')}</Button>}>
                    {t('asset_type_setup.duplicated', { names: duplicadas.map(nombreSpec).join(', ') })}
                  </Alert>
                )}
                <TypeSpecsEditor rows={specs} onChange={setSpecs} />
              </SectionCard>
            )}

            {conComponentes && (
              <SectionCard title={t('asset_type_setup.step3')} subtitle={t('asset_type_setup.step3_help')}>
                <TypeComponentsEditor rows={componentes} onChange={cambiarComponentes} candidates={candidatos} suggestedNames={nombresSpecs}
                  category={categoria === '' ? null : categoria} takenPrefixes={tipos.map((x) => x.TAC_Prefijo ?? '')} selfId={actual?.TAC_Tipo_Activo} />
              </SectionCard>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={cerrar} color="inherit">{recienCreado ? t('common.close') : t('common.cancel')}</Button>
          <Button type="submit" variant="contained" disabled={loading || !apto}>
            {loading ? <CircularProgress size={24}/> : actual ? t('common.save') : t('asset_type_setup.create')}
          </Button>
        </DialogActions>
      </form>
    </Dialog>
  );
};
