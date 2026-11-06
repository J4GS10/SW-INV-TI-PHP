import { useMemo, useState } from 'react';
import { Autocomplete, CircularProgress, TextField, createFilterOptions } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import {
  catalogoService, type CatalogoClave, type CatalogoItem, type MovimientoAplica,
} from '../api/catalogoService';
import { motivoAplica, useCatalogo } from '../hooks/useCatalogo';
import { getApiErrorMessage } from '../api/errors';
import { usePermissions } from '../hooks/usePermissions';
import { catalogItemLabel } from '../utils/catalogLabels';

type Opcion = CatalogoItem | { nuevo: string };

interface Props {
  clave: CatalogoClave;
  /** Id del registro (o su código, con `valueBy="codigo"`). */
  value: number | string | null;
  valueBy?: 'id' | 'codigo';
  onChange: (value: number | string | null, item: CatalogoItem | null) => void;
  label: string;
  /** Solo motivos que aplican a este tipo de movimiento. */
  aplicaA?: MovimientoAplica;
  required?: boolean;
  error?: boolean;
  helperText?: string;
  disabled?: boolean;
  size?: 'small' | 'medium';
  /** Permite «Agregar…» un registro nuevo (solo administradores). Por defecto, sí. */
  allowCreate?: boolean;
}

const filtro = createFilterOptions<Opcion>();

/**
 * Selector de un catálogo estándar. Reemplaza los campos de texto libre: el
 * usuario elige de la lista y, si es administrador, puede agregar un registro
 * nuevo sin salir del formulario.
 */
export const CatalogField = ({
  clave, value, valueBy = 'id', onChange, label, aplicaA, required, error, helperText, disabled, size,
  allowCreate = true,
}: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const { canAdminInventory } = usePermissions();
  const { items, loading, reload } = useCatalogo(clave);
  const [creando, setCreando] = useState(false);

  const opciones = useMemo(() => items.filter((i) => motivoAplica(i, aplicaA)), [items, aplicaA]);
  const seleccionado = useMemo(
    () => items.find((i) => (valueBy === 'codigo' ? i.codigo === value : i.id === value)) ?? null,
    [items, value, valueBy],
  );
  const etiqueta = (o: Opcion) => ('nuevo' in o ? t('catalog_center.add_named', { name: o.nuevo }) : catalogItemLabel(t, clave, o));

  const crear = async (nombre: string) => {
    setCreando(true);
    try {
      const nuevo = await catalogoService.crear(clave, {
        nombre, extras: aplicaA && clave === 'motivos-movimiento' ? { MMO_Aplica_A: [aplicaA] } : {},
      });
      await reload();
      onChange(valueBy === 'codigo' ? nuevo.codigo : nuevo.id, nuevo);
      enqueueSnackbar(t('catalog_center.created', { name: nuevo.nombre }), { variant: 'success' });
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    } finally {
      setCreando(false);
    }
  };

  return (
    <Autocomplete<Opcion>
      size={size}
      options={opciones}
      value={seleccionado}
      loading={loading || creando}
      disabled={disabled || creando}
      getOptionLabel={etiqueta}
      isOptionEqualToValue={(a, b) => !('nuevo' in a) && !('nuevo' in b) && a.id === b.id}
      filterOptions={(opts, state) => {
        const filtradas = filtro(opts, { ...state, getOptionLabel: etiqueta });
        const texto = state.inputValue.trim();
        const existe = opts.some((o) => !('nuevo' in o) && etiqueta(o).toLowerCase() === texto.toLowerCase());
        if (allowCreate && canAdminInventory && texto.length >= 2 && !existe) filtradas.push({ nuevo: texto });
        return filtradas;
      }}
      onChange={(_e, opcion) => {
        if (opcion && 'nuevo' in opcion) {
          void crear(opcion.nuevo);
          return;
        }
        onChange(opcion ? (valueBy === 'codigo' ? opcion.codigo : opcion.id) : null, opcion ?? null);
      }}
      renderInput={(params) => (
        <TextField
          {...params} label={label} required={required} error={error} helperText={helperText}
          InputProps={{
            ...params.InputProps,
            endAdornment: (<>{(loading || creando) && <CircularProgress size={16} />}{params.InputProps.endAdornment}</>),
          }}
        />
      )}
    />
  );
};
