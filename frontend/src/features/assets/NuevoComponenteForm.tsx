import { useEffect, useMemo, useRef, useState } from 'react';
import {
  Autocomplete, Box, Button, Checkbox, FormControlLabel, MenuItem, Paper, Stack, TextField, Typography,
  createFilterOptions,
} from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import client from '../../api/axios';
import { apiCache } from '../../api/cache';
import { getApiErrorMessage } from '../../api/errors';
import { catalogoConfigService, type EspecificacionTipo } from '../../api/catalogoService';
import { MoneyField } from '../../components/ui';
import { usePermissions } from '../../hooks/usePermissions';
import { catalogSlug } from '../../utils/catalogLabels';
import { DEFAULT_CURRENCY } from '../../utils/currency';
import { assetService } from './assetService';
import type { Activo, ActivoCreate, Marca, ModeloFlat, TipoActivo } from '../../types/inventory';

interface Props {
  /** Equipo donde queda instalado: de él se heredan sede, área, fecha, proveedor, factura y moneda. */
  equipo: Activo;
  /** Tipos de componente que admite el equipo. */
  tipos: TipoActivo[];
  onDone: () => void;
  onCancel: () => void;
  /** Se registró una pieza y el formulario sigue abierto para la siguiente igual. */
  onSaved?: () => void;
  /** Tipo ya elegido (al registrar un componente obligatorio que falta). */
  tipoInicial?: number;
}

/** Modelo escrito que aún no existe: se crea al guardar con la marca elegida. */
type OpcionModelo = ModeloFlat | { nuevo: string };
const esNuevo = (o: OpcionModelo | null): o is { nuevo: string } => !!o && 'nuevo' in o;
const etiquetaModelo = (o: OpcionModelo) => (esNuevo(o) ? o.nuevo : `${o.MAR_Nombre} · ${o.MOD_Nombre}`);
const filtro = createFilterOptions<OpcionModelo>({ ignoreAccents: true, ignoreCase: true, trim: true, limit: 50, stringify: etiquetaModelo });
const especificacionesRoute = (modelo: number) => `/goods-receipts/catalog/models/${modelo}/spec-defaults`;

/**
 * Registrar un componente nuevo ya instalado en el equipo, en un solo paso: tipo, modelo, serie y sus
 * características. Las características salen de los valores de fábrica del modelo; lo que se escriba
 * por primera vez se recuerda en el modelo para la próxima pieza igual.
 */
export const NuevoComponenteForm = ({ equipo, tipos, onDone, onCancel, onSaved, tipoInicial }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const { canSeeCosts } = usePermissions();
  const [tipo, setTipo] = useState<number | ''>(tipoInicial ?? (tipos.length === 1 ? tipos[0].TAC_Tipo_Activo : ''));
  const [modelos, setModelos] = useState<ModeloFlat[]>([]);
  const [marcas, setMarcas] = useState<Marca[]>([]);
  const [modelo, setModelo] = useState<OpcionModelo | null>(null);
  const [marcaNueva, setMarcaNueva] = useState<number | ''>('');
  const [serie, setSerie] = useState('');
  const [costo, setCosto] = useState('');
  const [moneda, setMoneda] = useState(equipo.ACT_Moneda || DEFAULT_CURRENCY);
  const [especificaciones, setEspecificaciones] = useState<EspecificacionTipo[]>([]);
  const [valores, setValores] = useState<Record<number, string>>({});
  const [deFabrica, setDeFabrica] = useState<Record<number, string>>({});
  const [recordar, setRecordar] = useState(true);
  const [bodega, setBodega] = useState<number | null>(null);
  const [guardando, setGuardando] = useState(false);

  useEffect(() => {
    void Promise.all([assetService.getModelosFlat(), assetService.getMarcas(), assetService.getEstados()]).then(([mo, ma, es]) => {
      setModelos(mo);
      setMarcas(ma);
      setBodega(es.find((e) => catalogSlug(e.EOP_Nombre) === 'en_bodega')?.EOP_Estado_Operativo ?? null);
    }).catch(() => enqueueSnackbar(t('common.error_loading'), { variant: 'error' }));
  }, [enqueueSnackbar, t]);

  // Las características que se preguntan son las del tipo, en el orden del catálogo.
  useEffect(() => {
    setEspecificaciones([]);
    if (tipo === '') return;
    catalogoConfigService.especificacionesPorTipo()
      .then((m) => setEspecificaciones([...(m[String(tipo)] ?? [])].sort((a, b) => a.orden - b.orden)))
      .catch(() => setEspecificaciones([]));
  }, [tipo]);

  // Al elegir un modelo existente, sus valores de fábrica llenan las características.
  const modeloId = modelo && !esNuevo(modelo) ? modelo.MOD_Modelo : null;
  useEffect(() => {
    setDeFabrica({});
    if (modeloId == null) return;
    let vivo = true;
    client.get<{ type_id: number; value: string | number | null }[]>(especificacionesRoute(modeloId)).then((r) => {
      if (!vivo) return;
      const fabrica: Record<number, string> = Object.fromEntries(
        r.data.filter((s) => s.value != null && s.value !== '').map((s) => [s.type_id, String(s.value)]));
      setDeFabrica(fabrica);
      setValores((v) => ({ ...fabrica, ...Object.fromEntries(Object.entries(v).filter(([, x]) => x !== '')) }));
    }).catch(() => undefined);
    return () => { vivo = false; };
  }, [modeloId]);

  const opcionesModelo = useMemo(
    (): OpcionModelo[] => modelos.filter((m) => tipo === '' || m.TAC_Tipo_Activo == null || m.TAC_Tipo_Activo === tipo),
    [modelos, tipo],
  );
  const faltaObligatoria = especificaciones.some((e) => e.obligatoria && !(valores[e.TES_Tipo_Especificacion] ?? '').trim());
  const listo = tipo !== '' && !!modelo && serie.trim() !== '' && bodega != null && !faltaObligatoria
    && (!esNuevo(modelo) || marcaNueva !== '');

  const serieRef = useRef<HTMLInputElement | null>(null);
  // `otro`: tras registrar, queda listo para la siguiente pieza igual (mismo tipo, modelo y características): solo falta su serie.
  const guardar = async (otro = false) => {
    if (!listo || !modelo || bodega == null || guardando) return;
    setGuardando(true);
    try {
      // Una sola operación del servidor: el modelo nuevo, el activo y los valores de fábrica, todo o nada.
      const escritas = especificaciones
        .map((e) => ({ TES_Tipo_Especificacion: e.TES_Tipo_Especificacion, ESP_Valor: (valores[e.TES_Tipo_Especificacion] ?? '').trim() }))
        .filter((e) => e.ESP_Valor !== '');
      const alta: ActivoCreate = {
        ACT_Serie_Fabricante: serie.trim(),
        ACT_Fecha_Compra: equipo.ACT_Fecha_Compra || new Date().toISOString().slice(0, 10),
        ...(equipo.ACT_Fin_Garantia ? { ACT_Fin_Garantia: equipo.ACT_Fin_Garantia } : {}),
        ...(canSeeCosts && costo ? { ACT_Costo: Number(costo), ACT_Moneda: moneda } : {}),
        ...(esNuevo(modelo)
          ? { nuevo_modelo: { MOD_Nombre: modelo.nuevo, MAR_Marca: Number(marcaNueva) } }
          : { MOD_Modelo: modelo.MOD_Modelo }),
        // Lo escrito por primera vez queda como valor de fábrica del modelo: la próxima pieza igual ya viene llena.
        recordar_valores_fabrica: recordar,
        TAC_Tipo_Activo: tipo,
        EOP_Estado_Operativo: bodega,
        SED_Sede: equipo.SED_Sede ?? null,
        ACT_Activo_Padre: equipo.ACT_Activo,
        ...(equipo.PRV_Proveedor != null ? { PRV_Proveedor: equipo.PRV_Proveedor } : {}),
        ...(equipo.ACT_Factura ? { ACT_Factura: equipo.ACT_Factura } : {}),
        especificaciones: escritas,
      };
      const creado = await assetService.createActivo(alta);
      if (esNuevo(modelo)) apiCache.clear();
      enqueueSnackbar(t('components.created_installed', { code: equipo.ACT_Codigo_Interno }), { variant: 'success' });
      if (!otro) {
        onDone();
        return;
      }
      // El modelo recién creado ya existe: la siguiente pieza lo usa (y trae sus valores de fábrica).
      if (esNuevo(modelo)) {
        const lista = await assetService.getModelosFlat().catch(() => modelos);
        setModelos(lista);
        setModelo(lista.find((m) => m.MOD_Modelo === creado.MOD_Modelo) ?? null);
      }
      setSerie('');
      onSaved?.();
      enqueueSnackbar(t('components.next_ready'), { variant: 'info' });
      window.setTimeout(() => serieRef.current?.focus(), 0);
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    } finally {
      setGuardando(false);
    }
  };

  return (
    <Paper variant="outlined" sx={{ p: 2 }} component="section" aria-label={t('components.new_title')}>
      <Typography variant="subtitle2" gutterBottom>{t('components.new_title')}</Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>{t('components.new_help', { code: equipo.ACT_Codigo_Interno })}</Typography>
      <Stack spacing={2}>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5}>
          <TextField select size="small" fullWidth required label={t('components.type')} value={tipo}
            onChange={(e) => { setTipo(e.target.value === '' ? '' : Number(e.target.value)); setModelo(null); setValores({}); }}>
            {tipos.map((x) => <MenuItem key={x.TAC_Tipo_Activo} value={x.TAC_Tipo_Activo}>{x.TAC_Nombre}</MenuItem>)}
          </TextField>
          <Autocomplete fullWidth size="small" options={opcionesModelo} value={modelo} disabled={tipo === ''}
            onChange={(_, v) => { setModelo(v); if (!esNuevo(v)) setMarcaNueva(''); }}
            getOptionLabel={etiquetaModelo} isOptionEqualToValue={(a, b) => etiquetaModelo(a) === etiquetaModelo(b)}
            filterOptions={(o, s) => {
              const r = filtro(o, s);
              const texto = s.inputValue.trim();
              return texto.length >= 2 && !r.some((x) => etiquetaModelo(x).toLowerCase().endsWith(texto.toLowerCase())) ? [...r, { nuevo: texto }] : r;
            }}
            renderOption={(props, o) => <li {...props} key={etiquetaModelo(o)}>{esNuevo(o) ? t('components.create_model', { name: o.nuevo }) : etiquetaModelo(o)}</li>}
            renderInput={(p) => <TextField {...p} required autoFocus={tipos.length === 1 || tipoInicial != null} label={t('components.model')} helperText={t('components.model_help')} />} />
        </Stack>
        {esNuevo(modelo) && (
          <TextField select size="small" required label={t('components.brand_new_model', { name: modelo.nuevo })} value={marcaNueva}
            onChange={(e) => setMarcaNueva(e.target.value === '' ? '' : Number(e.target.value))}>
            {marcas.map((m) => <MenuItem key={m.MAR_Marca} value={m.MAR_Marca}>{m.MAR_Nombre}</MenuItem>)}
          </TextField>
        )}
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5}>
          <TextField size="small" fullWidth required label={t('components.serial')} value={serie} inputRef={serieRef}
            onChange={(e) => setSerie(e.target.value)} helperText={t('components.serial_enter')}
            onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); void guardar(); } }} />
          {canSeeCosts && <MoneyField name="costo-componente" size="small" fullWidth label={t('components.cost')} value={costo}
            onChange={setCosto} currency={moneda} onCurrencyChange={setMoneda} />}
        </Stack>
        {especificaciones.length > 0 && (
          <Box component="fieldset" sx={{ border: 0, p: 0, m: 0 }}>
            <Typography component="legend" variant="caption" color="text.secondary">{t('components.features')}</Typography>
            <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' }, gap: 1.5, mt: 1 }}>
              {especificaciones.map((e) => {
                const id = e.TES_Tipo_Especificacion;
                const valor = valores[id] ?? '';
                const fabrica = deFabrica[id] != null && deFabrica[id] === valor;
                const cambiar = (v: string) => setValores((x) => ({ ...x, [id]: v }));
                const etiqueta = e.unidad ? `${e.nombre ?? ''} (${e.unidad})` : (e.nombre ?? '');
                return e.valores.length > 0 ? (
                  <TextField key={id} select size="small" required={e.obligatoria} label={etiqueta} value={valor}
                    onChange={(ev) => cambiar(ev.target.value)} helperText={fabrica ? t('components.from_model') : undefined}>
                    <MenuItem value="">—</MenuItem>
                    {e.valores.map((v) => <MenuItem key={v} value={v}>{v}</MenuItem>)}
                  </TextField>
                ) : (
                  <TextField key={id} size="small" required={e.obligatoria} label={etiqueta} value={valor}
                    onChange={(ev) => cambiar(ev.target.value)} helperText={fabrica ? t('components.from_model') : undefined} />
                );
              })}
            </Box>
            <FormControlLabel sx={{ mt: 1 }} control={<Checkbox size="small" checked={recordar} onChange={(_, c) => setRecordar(c)} />}
              label={<Typography variant="body2">{t('components.remember_defaults')}</Typography>} />
          </Box>
        )}
        <Stack direction="row" spacing={1} justifyContent="flex-end">
          <Button onClick={onCancel}>{t('common.cancel')}</Button>
          <Button onClick={() => { void guardar(true); }} disabled={!listo || guardando}>{t('components.create_and_another')}</Button>
          <Button variant="contained" onClick={() => { void guardar(); }} disabled={!listo || guardando}>{t('components.create_and_install')}</Button>
        </Stack>
      </Stack>
    </Paper>
  );
};
