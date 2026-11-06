import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Box, Button, Chip, FormControlLabel, Link, MenuItem, Stack, Switch, TableCell, TableRow, TextField, Tooltip,
  Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import DragIndicatorIcon from '@mui/icons-material/DragIndicator';
import CallMergeIcon from '@mui/icons-material/CallMerge';
import LockIcon from '@mui/icons-material/LockOutlined';
import { Link as RouterLink } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import {
  catalogoConfigService, catalogoService, type CatalogoDef, type CatalogoExtraDef, type CatalogoItem, type MovimientoAplica,
} from '../../../api/catalogoService';
import { getApiErrorMessage } from '../../../api/errors';
import { DataTableShell } from '../../../components/ui';
import { SearchInput } from '../../../components/SearchInput';
import { CatalogField } from '../../../components/CatalogField';
import { usePermissions } from '../../../hooks/usePermissions';
import { catalogItemLabel } from '../../../utils/catalogLabels';
import { mergeAtomicValues } from '../../../utils/atomicValues';
import { MergeDialog } from './MergeDialog';

const SPEC_TYPES = 'tipos-especificacion';
/** Reading order of a specification: what kind of data comes first, its unit and range depend on it. */
const SPEC_EXTRAS = ['TES_Data_Type', 'UME_Unidad', 'TES_Min_Value', 'TES_Max_Value'];

const APLICA: MovimientoAplica[] = ['ASIGNACION', 'PRESTAMO', 'TRANSFERENCIA', 'DEVOLUCION', 'INSTALACION', 'RETIRO'];

interface Props {
  def: CatalogoDef;
}

/**
 * Texto que se guarda al salir del campo (edición en línea). Quien lo usa le
 * pasa `key={value}`: si el valor guardado cambia, el campo se reinicia.
 */
const CampoEnLinea = ({ value, onSave, disabled, max, placeholder, label }: {
  value: string; onSave: (v: string) => void; disabled?: boolean; max: number; placeholder?: string; label?: string;
}) => {
  const [texto, setTexto] = useState(value);
  return (
    <TextField
      variant="standard" fullWidth value={texto} disabled={disabled} placeholder={placeholder} label={label}
      inputProps={{ maxLength: max }}
      onChange={(e) => setTexto(e.target.value)}
      onBlur={() => { if (texto.trim() !== value) onSave(texto.trim()); }}
      onKeyDown={(e) => { if (e.key === 'Enter') (e.target as HTMLInputElement).blur(); }}
    />
  );
};

/** Dónde se pide una especificación y qué valores acepta. */
const SpecUsage = ({ uso }: { uso?: { tipos: number; valores: string[] } }) => {
  const { t } = useTranslation();
  return (
    <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
      {uso ? t('catalog_center.spec_used_in', { count: uso.tipos }) : t('catalog_center.spec_unused')}
      {uso && uso.valores.length > 0 && ` · ${t('catalog_center.spec_values', { values: uso.valores.join(', ') })}`}
      {' · '}
      <Link component={RouterLink} to="/config/catalogos?g=ACTIVOS&c=especificaciones-tipo">{t('catalog_center.spec_configure')}</Link>
    </Typography>
  );
};

/**
 * Registros de un catálogo:
 edición en línea, orden por arrastre, activar o
 * desactivar, campos propios del catálogo y fusión de duplicados.
 */
export const CatalogItemsPanel = ({ def }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const { canAdminInventory } = usePermissions();
  const [items, setItems] = useState<CatalogoItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [busqueda, setBusqueda] = useState('');
  const [inactivos, setInactivos] = useState(false);
  const [nuevo, setNuevo] = useState('');
  const [arrastrando, setArrastrando] = useState<number | null>(null);
  const [fusion, setFusion] = useState<CatalogoItem | null>(null);
  const [usoEspec, setUsoEspec] = useState<Map<number, { tipos: number; valores: string[] }>>(new Map());
  const clave = def.clave;
  // Con varios campos propios la fila es ancha: descripción y fusionar se apilan para que todo se vea sin desplazar.
  const ancha = def.extras.length > 1;
  const porAgregar = useMemo(() => mergeAtomicValues([nuevo]), [nuevo]);

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      setItems(await catalogoService.listar(clave, true));
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_loading')), { variant: 'error' });
    } finally {
      setLoading(false);
    }
  }, [clave, enqueueSnackbar, t]);

  useEffect(() => { void cargar(); }, [cargar]);

  // Dónde se pide cada especificación y qué valores acepta: así se ve dónde vive «DDR4».
  useEffect(() => {
    if (clave !== SPEC_TYPES) return;
    catalogoConfigService.especificacionesPorTipo().then((todas) => {
      const uso = new Map<number, { tipos: number; valores: string[] }>();
      Object.values(todas).flat().forEach((f) => {
        const actual = uso.get(f.TES_Tipo_Especificacion) ?? { tipos: 0, valores: [] };
        uso.set(f.TES_Tipo_Especificacion, { tipos: actual.tipos + 1, valores: mergeAtomicValues([...actual.valores, ...f.valores]) });
      });
      setUsoEspec(uso);
    }).catch(() => setUsoEspec(new Map()));
  }, [clave]);

  const visibles = useMemo(() => {
    const q = busqueda.trim().toLowerCase();
    return items.filter((i) => (inactivos || i.activo)
      && (!q || catalogItemLabel(t, clave, i).toLowerCase().includes(q) || (i.codigo ?? '').toLowerCase().includes(q)));
  }, [items, inactivos, busqueda, t, clave]);

  const guardar = async (item: CatalogoItem, cambios: Parameters<typeof catalogoService.editar>[2]) => {
    try {
      const actualizado = await catalogoService.editar(clave, item.id, cambios);
      setItems((prev) => prev.map((i) => (i.id === item.id ? { ...actualizado, uso: i.uso } : i)));
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    }
  };

  const agregar = async () => {
    if (porAgregar.length === 0) return;
    const existentes = new Set(items.map((i) => i.nombre.toLowerCase()));
    const creados: string[] = [];
    try {
      for (const nombre of porAgregar.filter((n) => !existentes.has(n.toLowerCase()))) {
        await catalogoService.crear(clave, { nombre });
        creados.push(nombre);
      }
      setNuevo('');
      if (creados.length === 1) enqueueSnackbar(t('catalog_center.created', { name: creados[0] }), { variant: 'success' });
      else if (creados.length > 1) enqueueSnackbar(t('catalog_center.created_many', { count: creados.length }), { variant: 'success' });
      else enqueueSnackbar(t('catalog_center.already_exists'), { variant: 'info' });
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    }
    await cargar();
  };

  const soltar
 = async (destinoId: number) => {
    if (arrastrando === null || arrastrando === destinoId) return;
    const orden = items.filter((i) => i.activo || inactivos).map((i) => i.id);
    const desde = orden.indexOf(arrastrando);
    const hasta = orden.indexOf(destinoId);
    orden.splice(hasta, 0, ...orden.splice(desde, 1));
    setArrastrando(null);
    setItems((prev) => [...prev].sort((a, b) => orden.indexOf(a.id) - orden.indexOf(b.id)));
    try {
      await catalogoService.reordenar(clave, orden);
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
      await cargar();
    }
  };

  /** Extras of a row, in reading order. Unit and range only mean something for a number. */
  const extrasDe = (item: CatalogoItem): CatalogoExtraDef[] => {
    if (clave !== SPEC_TYPES) return def.extras;
    const esNumero = item.extras.TES_Data_Type === 'NUMBER';
    return SPEC_EXTRAS.flatMap((columna) => def.extras.filter((e) => e.columna === columna))
      .filter((e) => esNumero || e.columna === 'TES_Data_Type');
  };

  /** Una especificación solo se fusiona con otra que guarda el mismo dato. */
  const destinosDeFusion = (origen: CatalogoItem | null): CatalogoItem[] => items.filter((i) => i.activo
    && (clave !== SPEC_TYPES || (i.extras.TES_Data_Type === origen?.extras.TES_Data_Type && i.extras.UME_Unidad === origen?.extras.UME_Unidad)));
  const hayDuplicables = items.filter((i) => i.activo).length > 1;

  const extra = (item: CatalogoItem, e: CatalogoExtraDef) => {
    const valor = item.extras[e.columna];
    const bloqueado = !canAdminInventory;
    if (e.tipo === 'bool') {
      return (
        <FormControlLabel
          control={<Switch size="small" checked={valor === true} disabled={bloqueado}
            onChange={(ev) => void guardar(item, { extras: { [e.columna]: ev.target.checked } })} />}
          label={<Typography variant="caption">{t(`catalog_center.extras.${e.columna}`, { defaultValue: t('catalog_center.extras.pide_detalle') })}</Typography>}
        />
      );
    }
    if (e.tipo === 'texto') {
      return (
        <CampoEnLinea key={(valor as string) ?? ''} value={(valor as string) ?? ''} max={e.largo} disabled={bloqueado}
          placeholder={t(`catalog_center.extras.${e.columna}`)}
          onSave={(v) => void guardar(item, { extras: { [e.columna]: v || null } })} />
      );
    }
    if (e.tipo === 'tipo_dato') {
      return (
        <TextField select size="small" sx={{ minWidth: 130 }} disabled={bloqueado} label={t(`catalog_center.extras.${e.columna}`)} value={(valor as string) ?? 'TEXT'}
          onChange={(ev) => void guardar(item, { extras: { [e.columna]: ev.target.value } })}>
          {['TEXT', 'NUMBER', 'BOOLEAN'].map((tipo) => <MenuItem key={tipo} value={tipo}>{t(`catalog_center.data_types.${tipo}`)}</MenuItem>)}
        </TextField>
      );
    }
    if (e.tipo === 'numero') {
      return (
        <CampoEnLinea key={String(valor ?? '')} value={valor === null || valor === undefined ? '' : String(valor)} max={14} disabled={bloqueado}
          label={t(`catalog_center.extras.${e.columna}`)}
          onSave={(v) => void guardar(item, { extras: { [e.columna]: v.trim() === '' ? null : v.trim() } })} />
      );
    }
    if (e.tipo === 'aplica_a') {
      const lista = (valor as MovimientoAplica[] | null) ?? [];
      return (
        <TextField
          select variant="standard" fullWidth disabled={bloqueado}
          SelectProps={{
            multiple: true,
            renderValue: (v) => ((v as string[]).length
              ? (v as string[]).map((x) => t(`catalog_center.applies.${x}`)).join(', ')
              : t('catalog_center.applies.ALL')),
            displayEmpty: true,
          }}
          value={lista}
          onChange={(ev) => void guardar(item, { extras: { [e.columna]: ev.target.value as unknown as string[] } })}
        >
          {APLICA.map((a) => <MenuItem key={a} value={a}>{t(`catalog_center.applies.${a}`)}</MenuItem>)}
        </TextField>
      );
    }
    if (e.tipo === 'catalogo' && e.catalogo) {
      return (
        <CatalogField size="small" clave={e.catalogo} label={t(`catalog_center.extras.${e.columna}`)}
          value={(valor as number) ?? null} disabled={bloqueado} allowCreate={false}
          onChange={(v) => void guardar(item, { extras: { [e.columna]: v } })} />
      );
    }
    return null;
  };

  const descripcion = (item: CatalogoItem) => (
    <CampoEnLinea key={item.descripcion ?? ''} value={item.descripcion ?? ''} max={255} disabled={!canAdminInventory}
      placeholder={ancha ? t('catalog_center.description') : undefined}
      onSave={(v) => void guardar(item, { descripcion: v || null })} />
  );

  const fusionar = (item: CatalogoItem) => canAdminInventory && !item.sistema && item.activo && hayDuplicables && (
    <Tooltip title={t('catalog_center.merge_hint')}>
      <Button size="small" color="inherit" startIcon={<CallMergeIcon />} onClick={() => setFusion(item)} sx={{ whiteSpace: 'nowrap' }}>
        {t('catalog_center.merge')}
      </Button>
    </Tooltip>
  );

  const columnas = [
    { key: 'drag', label: '', width: 32 },
    { key: 'name', label: t('catalog_center.name') },
    ...(def.tiene_descripcion && !ancha ? [{ key: 'desc', label: t('catalog_center.description') }] : []),
    ...(def.extras.length ? [{ key: 'extras', label: t('catalog_center.options') }] : []),
    { key: 'uso', label: t('catalog_center.in_use'), align: 'right' as const },
    { key: 'activo', label: t('catalog_center.active'), align: 'center' as const },
    ...(ancha ? [] : [{ key: 'actions', label: '', align: 'right' as const }]),
  ];

  return (
    <Box>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} alignItems={{ sm: 'center' }} sx={{ mb: 1.5 }}>
        <Box sx={{ flex: 1, minWidth: 0 }}><SearchInput value={busqueda} onChange={setBusqueda} /></Box>
        <FormControlLabel control={<Switch checked={inactivos} onChange={(e) => setInactivos(e.target.checked)} />}
          label={t('catalog_center.show_inactive')} />
      </Stack>
      {canAdminInventory && (
        <Stack direction="row" spacing={1} sx={{ mb: 1.5 }} alignItems="flex-start">
          <TextField size="small" fullWidth value={nuevo} label={t('catalog_center.new_item')}
            helperText={porAgregar.length > 1 ? t('catalog_center.will_add', { count: porAgregar.length, names: porAgregar.join(' · ') }) : t('catalog_center.new_item_help')}
            onChange={(e) => setNuevo(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') void agregar(); }} />
          <Button variant="contained" startIcon={<AddIcon />} onClick={() => void agregar()} disabled={porAgregar.length === 0}
            sx={{ flexShrink: 0 }}>
            {porAgregar.length > 1 ? t('catalog_center.add_many', { count: porAgregar.length }) : t('common.add')}
          </Button>
        </Stack>
      )}

      <DataTableShell
        columns={columnas}
        rows={visibles}
        loading={loading}
        emptyTitle={t('common.no_records')}
        renderRow={(item) => (
          <TableRow
            key={item.id}
            draggable={canAdminInventory && !busqueda}
            onDragStart={() => setArrastrando(item.id)}
            onDragOver={(e) => e.preventDefault()}
            onDrop={() => void soltar(item.id)}
            sx={{ opacity: item.activo ? 1 : 0.55, '&:hover': { bgcolor: 'action.hover' } }}
          >
            <TableCell sx={{ cursor: canAdminInventory && !busqueda ? 'grab' : 'default', color: 'text.disabled', px: 1 }}>
              <DragIndicatorIcon fontSize="small" />
            </TableCell>
            <TableCell sx={{ minWidth: ancha ? 210 : 150 }}>
              {item.sistema ? (
                <Stack direction="row" spacing={1} alignItems="center">
                  <Typography variant="body2" fontWeight={600}>{catalogItemLabel(t, clave, item)}</Typography>
                  <Tooltip title={t('catalog_center.system_hint')}>
                    <Chip size="small" icon={<LockIcon />} label={t('catalog_center.system')} variant="outlined" />
                  </Tooltip>
                </Stack>
              ) : (
                <CampoEnLinea key={item.nombre} value={item.nombre} max={def.largo_nombre} disabled={!canAdminInventory || !!item.fusionado_en}
                  onSave={(v) => v && void guardar(item, { nombre: v })} />
              )}
              {clave === SPEC_TYPES && <SpecUsage uso={usoEspec.get(item.id)} />}
              {ancha && def.tiene_descripcion && descripcion(item)}
              {item.fusionado_en && (
                <Typography variant="caption" color="text.secondary">
                  {t('catalog_center.merged_into', { name: items.find((i) => i.id === item.fusionado_en)?.nombre ?? '—' })}
                </Typography>
              )}
            </TableCell>
            {def.tiene_descripcion && !ancha && <TableCell sx={{ minWidth: 110 }}>{descripcion(item)}</TableCell>}
            {def.extras.length > 0 && (
              <TableCell sx={{ minWidth: def.extras.length > 1 ? 400 : 170 }}>
                <Box sx={{ display: 'grid', gridTemplateColumns: def.extras.length > 1 ? 'repeat(2, minmax(180px, 1fr))' : '1fr', gap: 1.5, py: 0.75, alignItems: 'end' }}>
                  {extrasDe(item).map((e) => <Box key={e.columna}>{extra(item, e)}</Box>)}
                </Box>
              </TableCell>
            )}
            <TableCell align="right" sx={{ color: 'text.secondary' }}>{item.uso}</TableCell>
            <TableCell align="center">
              <Switch size="small" checked={item.activo}
                disabled={!canAdminInventory || item.sistema || !!item.fusionado_en}
                onChange={(e) => void guardar(item, { activo: e.target.checked })}
                slotProps={{ input: { 'aria-label': t('catalog_center.active') } }} />
              {ancha && <Box sx={{ mt: 0.5 }}>{fusionar(item)}</Box>}
            </TableCell>
            {!ancha && <TableCell align="right">{fusionar(item)}</TableCell>}
          </TableRow>
        )}
      />
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
        {t('catalog_center.drag_hint')} {hayDuplicables && t('catalog_center.merge_hint')}
      </Typography>

      <MergeDialog
        open={!!fusion} clave={clave} origen={fusion} items={destinosDeFusion(fusion)}
        onClose={(hecho) => { setFusion(null); if (hecho) void cargar(); }}
      />
    </Box>
  );
};
