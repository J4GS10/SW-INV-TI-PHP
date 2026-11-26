import { useEffect, useMemo, useState } from 'react';
import {
  Autocomplete, Box, Button, FormControlLabel, IconButton, MenuItem, Paper, Stack, Switch, TextField, Tooltip, Typography,
  createFilterOptions,
} from '@mui/material';
import ArrowUpwardIcon from '@mui/icons-material/ArrowUpward';
import DeleteIcon from '@mui/icons-material/DeleteOutline';
import AddIcon from '@mui/icons-material/Add';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { catalogoConfigService, catalogoService } from '../../../api/catalogoService';
import { getApiErrorMessage } from '../../../api/errors';
import { apiCache } from '../../../api/cache';
import { AtomicValuesField } from '../../../components/AtomicValuesField';
import { CatalogField } from '../../../components/CatalogField';
import { catalogService } from '../catalogService';
import type { TipoEspecificacion } from '../../../types/catalogs';

/** Una característica que se pide al registrar un equipo del tipo. */
export interface TypeSpecRow {
  TES_Tipo_Especificacion: number;
  obligatoria: boolean;
  valores: string[];
}

type Opcion = TipoEspecificacion | { crear: string };
const esCrear = (o: Opcion | null): o is { crear: string } => !!o && 'crear' in o;
const filtro = createFilterOptions<Opcion>({ ignoreAccents: true, ignoreCase: true, trim: true,
  stringify: (o) => (esCrear(o) ? o.crear : o.TES_Nombre) });

interface Props {
  rows: TypeSpecRow[];
  onChange: (rows: TypeSpecRow[]) => void;
  disabled?: boolean;
}

/**
 * Qué se anota de cada equipo del tipo (memoria, disco, procesador…), en orden, si es obligatorio
 * y, si se quiere, de qué lista se elige. Una característica que no existe se crea aquí mismo.
 */
export const TypeSpecsEditor = ({ rows, onChange, disabled = false }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [catalogo, setCatalogo] = useState<TipoEspecificacion[]>([]);
  const [sugerencias, setSugerencias] = useState<Map<number, string[]>>(new Map());
  const [nueva, setNueva] = useState<{ nombre: string; tipo: 'TEXT' | 'NUMBER' | 'BOOLEAN'; unidad: number | null } | null>(null);
  const [creando, setCreando] = useState(false);

  useEffect(() => {
    catalogService.getTiposEspecificacion().then(setCatalogo).catch(() => setCatalogo([]));
    // Lo que otros tipos ya aceptan se ofrece aquí: «DDR4» se escribe igual en todos.
    catalogoConfigService.especificacionesPorTipo().then((todas) => {
      const usados = new Map<number, Set<string>>();
      Object.values(todas).flat().forEach((f) => {
        const lista = usados.get(f.TES_Tipo_Especificacion) ?? new Set<string>();
        f.valores.forEach((v) => lista.add(v));
        usados.set(f.TES_Tipo_Especificacion, lista);
      });
      setSugerencias(new Map([...usados].map(([id, lista]) => [id, [...lista]])));
    }).catch(() => setSugerencias(new Map()));
  }, []);

  const porId = useMemo(() => new Map(catalogo.map((e) => [e.TES_Tipo_Especificacion, e])), [catalogo]);
  const libres = catalogo.filter((e) => !rows.some((r) => r.TES_Tipo_Especificacion === e.TES_Tipo_Especificacion));
  const cambiar = (i: number, cambio: Partial<TypeSpecRow>) => onChange(rows.map((r, j) => (j === i ? { ...r, ...cambio } : r)));
  const subir = (i: number) => {
    const copia = [...rows];
    [copia[i - 1], copia[i]] = [copia[i], copia[i - 1]];
    onChange(copia);
  };

  const crear = async () => {
    if (!nueva || nueva.nombre.trim().length < 2) return;
    setCreando(true);
    try {
      const creada = await catalogoService.crear('tipos-especificacion', {
        nombre: nueva.nombre.trim(),
        extras: { TES_Data_Type: nueva.tipo, ...(nueva.tipo === 'NUMBER' && nueva.unidad ? { UME_Unidad: nueva.unidad } : {}) },
      });
      apiCache.clear();
      const lista = await catalogService.getTiposEspecificacion();
      setCatalogo(lista);
      onChange([...rows, { TES_Tipo_Especificacion: creada.id, obligatoria: false, valores: [] }]);
      setNueva(null);
      enqueueSnackbar(t('asset_type_setup.specs.created', { name: creada.nombre }), { variant: 'success' });
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    } finally {
      setCreando(false);
    }
  };

  return (
    <Stack spacing={1.5}>
      {rows.length === 0 && (
        <Typography variant="body2" color="text.secondary">{t('asset_type_setup.specs.empty')}</Typography>
      )}
      {rows.map((r, i) => {
        const e = porId.get(r.TES_Tipo_Especificacion);
        return (
          <Paper key={r.TES_Tipo_Especificacion} variant="outlined" sx={{ p: 1.5 }}>
            <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} alignItems={{ md: 'center' }}>
              <Box sx={{ minWidth: 180, flexShrink: 0 }}>
                <Typography variant="body2" fontWeight={600}>{i + 1}. {e?.TES_Nombre ?? '—'}</Typography>
                {e?.TES_Unidad_Medida && <Typography variant="caption" color="text.secondary">{t('asset_type_setup.specs.unit', { unit: e.TES_Unidad_Medida })}</Typography>}
              </Box>
              <FormControlLabel sx={{ flexShrink: 0, mr: 0 }} disabled={disabled}
                control={<Switch size="small" checked={r.obligatoria} onChange={(ev) => cambiar(i, { obligatoria: ev.target.checked })} />}
                label={<Typography variant="body2">{t('asset_type_setup.specs.required')}</Typography>} />
              <Box sx={{ flex: 1, minWidth: 0 }}>
                <AtomicValuesField value={r.valores} disabled={disabled} suggestions={sugerencias.get(r.TES_Tipo_Especificacion)}
                  placeholder={t('asset_type_setup.specs.values_placeholder')} ariaLabel={t('asset_type_setup.specs.values_of', { name: e?.TES_Nombre ?? '' })}
                  onChange={(v) => cambiar(i, { valores: v })} />
              </Box>
              {!disabled && (
                <Stack direction="row" sx={{ flexShrink: 0 }}>
                  <Tooltip title={t('catalog_center.move_up')}>
                    <span><IconButton size="small" disabled={i === 0} onClick={() => subir(i)} aria-label={t('catalog_center.move_up')}><ArrowUpwardIcon fontSize="small" /></IconButton></span>
                  </Tooltip>
                  <Tooltip title={t('asset_type_setup.specs.remove')}>
                    <IconButton size="small" onClick={() => onChange(rows.filter((_x, j) => j !== i))} aria-label={t('asset_type_setup.specs.remove_of', { name: e?.TES_Nombre ?? '' })}>
                      <DeleteIcon fontSize="small" />
                    </IconButton>
                  </Tooltip>
                </Stack>
              )}
            </Stack>
          </Paper>
        );
      })}
      {!disabled && !nueva && (
        <Autocomplete<Opcion> size="small" options={libres} value={null} blurOnSelect clearOnBlur
          getOptionLabel={(o) => (esCrear(o) ? o.crear : o.TES_Nombre)}
          filterOptions={(o, s) => {
            const r = filtro(o, s);
            const texto = s.inputValue.trim();
            return texto.length >= 2 && !catalogo.some((e) => e.TES_Nombre.toLowerCase() === texto.toLowerCase()) ? [...r, { crear: texto }] : r;
          }}
          renderOption={(props, o) => (
            <li {...props} key={esCrear(o) ? `crear-${o.crear}` : o.TES_Tipo_Especificacion}>
              {esCrear(o) ? t('asset_type_setup.specs.create_option', { name: o.crear })
                : `${o.TES_Nombre}${o.TES_Unidad_Medida ? ` (${o.TES_Unidad_Medida})` : ''}`}
            </li>
          )}
          onChange={(_, o) => {
            if (!o) return;
            if (esCrear(o)) setNueva({ nombre: o.crear, tipo: 'TEXT', unidad: null });
            else onChange([...rows, { TES_Tipo_Especificacion: o.TES_Tipo_Especificacion, obligatoria: false, valores: [] }]);
          }}
          renderInput={(p) => <TextField {...p} label={t('asset_type_setup.specs.add')} helperText={t('asset_type_setup.specs.add_help')} />} />
      )}
      {nueva && (
        <Paper variant="outlined" sx={{ p: 1.5, borderStyle: 'dashed' }}>
          <Typography variant="subtitle2" gutterBottom>{t('asset_type_setup.specs.new_title', { name: nueva.nombre })}</Typography>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} alignItems={{ sm: 'flex-start' }}>
            <TextField size="small" label={t('asset_type_setup.specs.name')} value={nueva.nombre} sx={{ flex: 1 }}
              onChange={(e) => setNueva({ ...nueva, nombre: e.target.value })} />
            <TextField select size="small" label={t('asset_type_setup.specs.data_type')} value={nueva.tipo} sx={{ minWidth: 170 }}
              helperText={t(`asset_type_setup.specs.data_type_help.${nueva.tipo}`)}
              onChange={(e) => setNueva({ ...nueva, tipo: e.target.value as 'TEXT' | 'NUMBER' | 'BOOLEAN' })}>
              {(['TEXT', 'NUMBER', 'BOOLEAN'] as const).map((d) => <MenuItem key={d} value={d}>{t(`catalog_center.data_types.${d}`)}</MenuItem>)}
            </TextField>
            {nueva.tipo === 'NUMBER' && (
              <Box sx={{ minWidth: 170 }}>
                <CatalogField size="small" clave="unidades-medida" label={t('asset_type_setup.specs.unit_field')} value={nueva.unidad}
                  allowCreate={false} onChange={(v) => setNueva({ ...nueva, unidad: (v as number | null) ?? null })} />
              </Box>
            )}
          </Stack>
          <Stack direction="row" spacing={1} justifyContent="flex-end" sx={{ mt: 1.5 }}>
            <Button size="small" onClick={() => setNueva(null)}>{t('common.cancel')}</Button>
            <Button size="small" variant="contained" startIcon={<AddIcon />} disabled={creando || nueva.nombre.trim().length < 2}
              onClick={() => { void crear(); }}>{t('asset_type_setup.specs.create')}</Button>
          </Stack>
        </Paper>
      )}
    </Stack>
  );
};
