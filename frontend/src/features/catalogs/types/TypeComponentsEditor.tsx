import { useMemo, useState } from 'react';
import {
  Autocomplete, Box, FormControlLabel, IconButton, Paper, Stack, Switch, TextField, Tooltip, Typography, createFilterOptions,
} from '@mui/material';
import DeleteIcon from '@mui/icons-material/DeleteOutline';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { prefijoDesde, prefijoLibre } from '../../../utils/prefijo';
import { getApiErrorCode, getApiErrorMessage } from '../../../api/errors';
import { catalogService, type ComponenteDeTipo } from '../catalogService';
import type { TipoActivo } from '../../../types/inventory';

type Opcion = TipoActivo | { crear: string };
const esCrear = (o: Opcion | null): o is { crear: string } => !!o && 'crear' in o;
const filtro = createFilterOptions<Opcion>({ ignoreAccents: true, ignoreCase: true, trim: true,
  stringify: (o) => (esCrear(o) ? o.crear : o.TAC_Nombre) });

interface Props {
  rows: ComponenteDeTipo[];
  onChange: (rows: ComponenteDeTipo[]) => void;
  /** Tipos de clase «Componente» que se pueden admitir. */
  candidates: TipoActivo[];
  /** Nombres de las características del tipo (Memoria RAM, Disco…): se ofrecen como componentes por crear. */
  suggestedNames: string[];
  /** Categoría contable del equipo: un componente creado aquí deprecia igual. */
  category: number | null;
  /** Prefijos que ya usan otros tipos: el componente creado aquí toma uno libre. */
  takenPrefixes?: string[];
  /** Id del tipo que se edita (no se admite a sí mismo). */
  selfId?: number;
  disabled?: boolean;
}

/**
 * Qué componentes internos lleva un equipo del tipo y cuáles son obligatorios al registrarlo
 * (una laptop: memoria, disco y batería). Lo obligatorio se pide al registrar el equipo; si se deja
 * para después, el equipo queda como pendiente hasta completarlo.
 */
export const TypeComponentsEditor = ({ rows, onChange, candidates, suggestedNames, category, takenPrefixes = [], selfId, disabled = false }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [creados, setCreados] = useState<TipoActivo[]>([]);
  const todos = useMemo(() => [...candidates, ...creados].filter((c) => c.TAC_Tipo_Activo !== selfId), [candidates, creados, selfId]);
  const libres = todos.filter((c) => !rows.some((r) => r.tipo === c.TAC_Tipo_Activo));
  // Una característica del tipo que aún no existe como componente se ofrece para crearla con un clic.
  const porCrear = suggestedNames.filter((n) => !todos.some((c) => c.TAC_Nombre.toLowerCase() === n.toLowerCase()));
  const cambiar = (i: number, cambio: Partial<ComponenteDeTipo>) => onChange(rows.map((r, j) => (j === i ? { ...r, ...cambio } : r)));

  const crear = async (nombre: string) => {
    // El prefijo se arma de las iniciales («Batería QA» → «BQA»): si ya lo usa otro tipo, se toma el siguiente libre,
    // y si otro usuario lo ocupa en el mismo instante, se reintenta con el que sigue.
    const usados = [...takenPrefixes, ...todos.map((x) => x.TAC_Prefijo ?? '')];
    try {
      let creado: TipoActivo | null = null;
      for (let intento = 0; !creado; intento++) {
        const prefijo = prefijoLibre(prefijoDesde(nombre) || 'CMP', usados);
        try {
          creado = await catalogService.createTipoActivo({ TAC_Nombre: nombre, TAC_Prefijo: prefijo, TAC_Clase: 'COMPONENTE',
            FAC_ASSET_CATEGORY: category });
        } catch (err) {
          if (getApiErrorCode(err) !== 'ASSET_TYPE_PREFIX_ALREADY_EXISTS' || intento >= 3) throw err;
          usados.push(prefijo);
        }
      }
      const nuevo = creado;
      setCreados((l) => [...l, nuevo]);
      onChange([...rows, { tipo: nuevo.TAC_Tipo_Activo, nombre: nuevo.TAC_Nombre, obligatorio: true, cantidad: 1 }]);
      enqueueSnackbar(t('asset_class.components_created', { name: nombre }), { variant: 'success' });
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_create')), { variant: 'error' });
    }
  };

  return (
    <Stack spacing={1.5}>
      {rows.length === 0 && <Typography variant="body2" color="text.secondary">{t('asset_type_setup.components.empty')}</Typography>}
      {rows.map((r, i) => (
        <Paper key={r.tipo} variant="outlined" sx={{ p: 1.5 }}>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} alignItems={{ sm: 'center' }}>
            <Typography variant="body2" fontWeight={600} sx={{ flex: 1, minWidth: 0 }}>{r.nombre}</Typography>
            <FormControlLabel sx={{ mr: 0 }} disabled={disabled}
              control={<Switch size="small" checked={r.obligatorio} onChange={(e) => cambiar(i, { obligatorio: e.target.checked })} />}
              label={<Typography variant="body2">{t('asset_type_setup.components.required')}</Typography>} />
            {r.obligatorio && (
              <TextField size="small" type="number" label={t('asset_type_setup.components.quantity')} value={r.cantidad} disabled={disabled}
                sx={{ width: 120 }} slotProps={{ htmlInput: { min: 1, max: 20 } }}
                onChange={(e) => cambiar(i, { cantidad: Math.max(1, Math.min(20, Number(e.target.value) || 1)) })} />
            )}
            {!disabled && (
              <Tooltip title={t('asset_type_setup.components.remove')}>
                <IconButton size="small" onClick={() => onChange(rows.filter((_x, j) => j !== i))} aria-label={t('asset_type_setup.components.remove_of', { name: r.nombre })}>
                  <DeleteIcon fontSize="small" />
                </IconButton>
              </Tooltip>
            )}
          </Stack>
        </Paper>
      ))}
      {!disabled && (
        <Box>
          <Autocomplete<Opcion> size="small" options={[...libres, ...porCrear.map((n) => ({ crear: n }))]} value={null} autoHighlight blurOnSelect clearOnBlur
            getOptionLabel={(o) => (esCrear(o) ? o.crear : o.TAC_Nombre)}
            filterOptions={(o, s) => {
              const r = filtro(o, s);
              const texto = s.inputValue.trim();
              return texto.length >= 2 && !o.some((x) => (esCrear(x) ? x.crear : x.TAC_Nombre).toLowerCase() === texto.toLowerCase())
                ? [...r, { crear: texto }] : r;
            }}
            renderOption={(props, o) => (
              <li {...props} key={esCrear(o) ? `crear-${o.crear}` : o.TAC_Tipo_Activo}>
                {esCrear(o) ? t('asset_class.components_create', { name: o.crear }) : o.TAC_Nombre}
              </li>
            )}
            onChange={(_, o) => {
              if (!o) return;
              if (esCrear(o)) void crear(o.crear);
              else onChange([...rows, { tipo: o.TAC_Tipo_Activo, nombre: o.TAC_Nombre, obligatorio: true, cantidad: 1 }]);
            }}
            renderInput={(p) => <TextField {...p} label={t('asset_type_setup.components.add')} helperText={t('asset_type_setup.components.add_help')} />} />
        </Box>
      )}
    </Stack>
  );
};
