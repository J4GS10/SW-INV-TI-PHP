import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
  Alert, Autocomplete, Box, Button, Checkbox, Chip, CircularProgress, FormControlLabel, IconButton, Stack, Table, TableBody, TableCell,
  TableHead, TableRow, TextField, Tooltip, Typography,
} from '@mui/material';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import CheckIcon from '@mui/icons-material/Check';
import AutoAwesomeIcon from '@mui/icons-material/AutoAwesome';
import QrCodeScannerIcon from '@mui/icons-material/QrCodeScanner';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { ContextCaption, SectionCard, SuggestionHint } from '../../components/ui';
import { getApiErrorMessage } from '../../api/errors';
import { suggestionService } from '../../api/suggestionService';
import { productividadService, type KitSugerido, type ResultadoLote } from '../../api/productividadService';
import { softwareService } from '../software/softwareService';
import type { LicenciaDisponible } from '../../types/software';
import { useEntityContext } from '../../hooks/useEntityContext';
import { useSuggestion } from '../../hooks/useSuggestion';
import { areaReason, personContextLine, shortList } from '../../utils/suggestionReasons';
import { stateLabel } from '../../utils/catalogLabels';
import type { CatalogoItem } from '../../api/catalogoService';
import { CatalogField } from '../../components/CatalogField';
import { pideDetalle, useCatalogo } from '../../hooks/useCatalogo';
import { createSearchFilter } from '../../utils/autocomplete';
import { nombrePersona } from '../../utils/personas';
import { assetService } from '../assets/assetService';
import { movementService } from './movementService';
import type { Activo, EstadoOperativo, TipoActivo } from '../../types/inventory';
import type { PersonaResumen } from '../../types/users';
import type { Area } from '../../types/geo';


const filtroPersona = createSearchFilter<PersonaResumen>((p) => `${nombrePersona(p)} ${p.PER_Email_Corporativo}`);

interface Props {
  /** Persona fija (asistente de alta): no se puede cambiar. */
  personaFija?: PersonaResumen | null;
  personaInicial?: PersonaResumen | null;
  activosIniciales?: Activo[];
  onCompleted: (resultado: ResultadoLote, persona: PersonaResumen, activos: Activo[]) => void;
  onCancel?: () => void;
}

/**
 * Entrega de puesto: varios equipos para una persona en una sola operación
 * (una transacción, un acta). Acepta lector de códigos: escribir o escanear un
 * código y pulsar Enter lo agrega a la lista.
 */
export const EntregaPuestoForm = ({ personaFija, personaInicial, activosIniciales, onCompleted, onCancel }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [personas, setPersonas] = useState<PersonaResumen[]>([]);
  const [areas, setAreas] = useState<Area[]>([]);
  const [tipos, setTipos] = useState<TipoActivo[]>([]);
  const [estados, setEstados] = useState<EstadoOperativo[]>([]);
  const [persona, setPersona] = useState<PersonaResumen | null>(personaFija ?? personaInicial ?? null);
  const [area, setArea] = useState<Area | null>(null);
  const [lista, setLista] = useState<Activo[]>(activosIniciales ?? []);
  const [custodios, setCustodios] = useState<Record<string, string | null>>({});
  const [busqueda, setBusqueda] = useState('');
  const [tipoFiltro, setTipoFiltro] = useState<number | null>(null);
  const [opciones, setOpciones] = useState<Activo[]>([]);
  const [buscando, setBuscando] = useState(false);
  // Motivo del catálogo; por defecto «Ingreso de personal» (una sola vez: el usuario puede quitarlo).
  const { items: motivos } = useCatalogo('motivos-movimiento');
  const [motivo, setMotivo] = useState<CatalogoItem | null>(null);
  const [detalleMotivo, setDetalleMotivo] = useState('');
  const motivoInicial = useRef(false);
  useEffect(() => {
    if (motivoInicial.current || motivos.length === 0) return;
    motivoInicial.current = true;
    setMotivo(motivos.find((m) => m.codigo === 'INGRESO_PERSONAL') ?? null);
  }, [motivos]);
  const [ticket, setTicket] = useState('');
  const [kit, setKit] = useState<KitSugerido | null>(null);
  // Licencias por persona (p. ej. la cuenta de Microsoft 365) en la misma entrega.
  const [licDisponibles, setLicDisponibles] = useState<LicenciaDisponible[]>([]);
  const [licencias, setLicencias] = useState<number[]>([]);
  useEffect(() => {
    softwareService.licenciasDisponibles('PERSONA').then(setLicDisponibles).catch(() => setLicDisponibles([]));
  }, []);
  const [enviando, setEnviando] = useState(false);

  useEffect(() => {
    Promise.all([movementService.getPersonasLookup(), movementService.getAreas(), assetService.getTipos(), assetService.getEstados()])
      .then(([p, a, tp, es]) => {
        setPersonas(p.filter((x) => x.PER_Estado !== false));
        setAreas(a);
        setTipos(tp);
        setEstados(es);
      })
      .catch(() => enqueueSnackbar(t('common.error_loading'), { variant: 'error' }));
  }, [enqueueSnackbar, t]);

  // Contexto de la persona: área habitual, línea informativa y kit sugerido.
  const personCtx = useEntityContext(persona?.PER_Persona ?? null, suggestionService.persona);
  const areaHint = useSuggestion<number | null>({
    current: area?.ARE_Area ?? null,
    empty: null,
    apply: (id) => setArea(areas.find((a) => a.ARE_Area === id) ?? null),
    suggestion: personCtx.loading ? undefined : personCtx.data?.area_sugerida ? {
      value: personCtx.data.area_sugerida.ARE_Area,
      label: personCtx.data.area_sugerida.nombre,
      reason: areaReason(t, personCtx.data.area_sugerida.origen, personCtx.data.persona?.nombre),
    } : null,
  });
  useEffect(() => {
    setKit(null);
    if (!persona) return undefined;
    let vivo = true;
    productividadService.kitSugerido(persona.PER_Persona).then((k) => {
      if (!vivo) return;
      setKit(k);
      // El kit estándar trae sus licencias ya marcadas.
      setLicencias(k.licencias ?? []);
    }).catch(() => undefined);
    return () => { vivo = false; };
  }, [persona]);

  // Búsqueda de equipos en el servidor (texto o tipo del kit).
  useEffect(() => {
    const q = busqueda.trim();
    if (q.length < 2 && !tipoFiltro) { setOpciones([]); return undefined; }
    let vivo = true;
    const timer = window.setTimeout(async () => {
      setBuscando(true);
      try {
        const r = await assetService.searchActivos({ q: q.length >= 2 ? q : undefined, tipo_activo_id: tipoFiltro ?? undefined, per_page: 30 });
        if (vivo) setOpciones(r.items);
      } catch {
        if (vivo) setOpciones([]);
      } finally {
        if (vivo) setBuscando(false);
      }
    }, 200);
    return () => { vivo = false; window.clearTimeout(timer); };
  }, [busqueda, tipoFiltro]);

  const estadoDe = useCallback((a: Activo) => estados.find((e) => e.EOP_Estado_Operativo === a.EOP_Estado_Operativo)?.EOP_Nombre ?? '', [estados]);
  const tipoDe = useCallback((id: number) => tipos.find((x) => x.TAC_Tipo_Activo === id)?.TAC_Nombre ?? '', [tipos]);
  const esLibre = useCallback((a: Activo) => estadoDe(a) === 'En Bodega', [estadoDe]);

  const agregar = useCallback((a: Activo) => {
    setLista((prev) => (prev.some((x) => x.ACT_Activo === a.ACT_Activo) ? prev : [...prev, a]));
    setBusqueda('');
    if (!(a.ACT_Activo in custodios)) {
      suggestionService.activo(a.ACT_Activo)
        .then((ctx) => setCustodios((c) => ({ ...c, [a.ACT_Activo]: ctx.custodio?.nombre ?? null })))
        .catch(() => setCustodios((c) => ({ ...c, [a.ACT_Activo]: null })));
    }
  }, [custodios]);

  useEffect(() => {
    (activosIniciales ?? []).forEach((a) => {
      suggestionService.activo(a.ACT_Activo)
        .then((ctx) => setCustodios((c) => ({ ...c, [a.ACT_Activo]: ctx.custodio?.nombre ?? null })))
        .catch(() => undefined);
    });
  }, [activosIniciales]);

  // Enter con un código escrito o escaneado: se busca exacto.
  const agregarPorCodigo = async (codigo: string) => {
    const c = codigo.trim();
    if (!c) return;
    try {
      agregar(await assetService.getActivoByCode(c));
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('handover.code_not_found', { code: c })), { variant: 'warning' });
    }
  };

  const opcionesOrdenadas = useMemo(
    () => [...opciones].sort((a, b) => Number(esLibre(b)) - Number(esLibre(a))),
    [opciones, esLibre],
  );
  const tiposEnLista = new Set(lista.map((a) => a.TAC_Tipo_Activo));
  const nombre = persona ? nombrePersona(persona) : '';
  const propios = lista.filter((a) => custodios[a.ACT_Activo] && custodios[a.ACT_Activo] === nombre);
  const faltaDetalle = pideDetalle(motivo) && !detalleMotivo.trim();
  const puedeEnviar = !!persona && !!area && lista.length > 0 && propios.length === 0 && !enviando && !faltaDetalle && !!motivo;

  const enviar = async () => {
    if (!persona || !area) return;
    setEnviando(true);
    try {
      const res = await productividadService.entregaLote({
        PER_Persona: persona.PER_Persona, ARE_Area: area.ARE_Area, activos: lista.map((a) => a.ACT_Activo),
        licencias: licencias.filter((id) => licDisponibles.some((l) => l.LIC_Licencia === id)),
        MMO_Motivo: motivo?.id ?? null, MOV_Motivo_Detalle: detalleMotivo.trim() || null, ticket: ticket.trim() || undefined,
        MOV_Observacion: detalleMotivo.trim() || undefined,
      });
      onCompleted(res, persona, lista);
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_create')), { variant: 'error', autoHideDuration: 8000 });
    } finally {
      setEnviando(false);
    }
  };

  const personLine = personCtx.data ? personContextLine(t, personCtx.data) : '';
  const yaTiene = personCtx.data?.activos_vigentes ?? [];

  return (
    <Stack spacing={2}>
      <SectionCard title={t('handover.sections.recipient')}>
        <Stack spacing={2}>
          {personaFija ? (
            <Typography variant="subtitle1" fontWeight={700}>{nombrePersona(personaFija)}</Typography>
          ) : (
            <Autocomplete
              options={personas}
              value={persona}
              filterOptions={filtroPersona}
              autoHighlight
              openOnFocus={false}
              getOptionLabel={(p) => `${nombrePersona(p)} (${p.PER_Email_Corporativo})`}
              isOptionEqualToValue={(a, b) => a.PER_Persona === b.PER_Persona}
              noOptionsText={t('common.type_to_search')}
              onChange={(_, p) => setPersona(p)}
              renderInput={(params) => <TextField {...params} autoFocus label={t('handover.person')} />}
            />
          )}
          {(personLine || yaTiene.length > 0) && (
            <Box sx={{ mt: -1 }}>
              {personLine && <ContextCaption>{personLine}</ContextCaption>}
              {yaTiene.length > 0 && <ContextCaption>{t('suggestions.info.has_assets', { list: shortList(yaTiene) })}</ContextCaption>}
            </Box>
          )}
          <Box>
            <Autocomplete
              options={areas}
              value={area}
              getOptionLabel={(a) => a.ARE_Nombre}
              isOptionEqualToValue={(a, b) => a.ARE_Area === b.ARE_Area}
              onChange={(_, a) => setArea(a)}
              renderInput={(params) => <TextField {...params} label={t('handover.area')} />}
            />
            <SuggestionHint hint={areaHint} />
          </Box>
        </Stack>
      </SectionCard>

      <SectionCard title={t('handover.sections.equipment', { count: lista.length })}>
        <Stack spacing={1.5}>
          {kit && kit.tipos.length > 0 && (
            <Box>
              <Typography variant="caption" color="text.secondary" sx={{ display: 'flex', alignItems: 'center', gap: 0.5, mb: 0.75 }}>
                <AutoAwesomeIcon sx={{ fontSize: 14 }} />
                {kit.origen === 'kit'
                  ? t('handover.kit_standard', { name: kit.kit })
                  : kit.origen === 'departamento'
                    ? t('handover.kit_department', { department: kit.departamento, count: kit.personas_referencia })
                    : t('handover.kit_general')}
              </Typography>
              <Stack direction="row" useFlexGap flexWrap="wrap" gap={0.75}>
                {kit.tipos.map((k) => {
                  const cubierto = tiposEnLista.has(k.TAC_Tipo_Activo) || k.ya_tiene;
                  return (
                    <Chip
                      key={k.TAC_Tipo_Activo}
                      size="small"
                      clickable
                      color={tipoFiltro === k.TAC_Tipo_Activo ? 'primary' : cubierto ? 'success' : 'default'}
                      variant={tipoFiltro === k.TAC_Tipo_Activo ? 'filled' : 'outlined'}
                      icon={cubierto ? <CheckIcon /> : undefined}
                      label={t('handover.kit_chip', { type: k.nombre, count: k.disponibles })}
                      onClick={() => setTipoFiltro(tipoFiltro === k.TAC_Tipo_Activo ? null : k.TAC_Tipo_Activo)}
                    />
                  );
                })}
              </Stack>
            </Box>
          )}
          <Autocomplete<Activo, false, false, true>
            freeSolo
            options={opcionesOrdenadas}
            loading={buscando}
            filterOptions={(o) => o}
            inputValue={busqueda}
            value={null}
            openOnFocus={!!tipoFiltro}
            onInputChange={(_, v, motivoCambio) => { if (motivoCambio !== 'reset') setBusqueda(v); }}
            getOptionLabel={(o) => (typeof o === 'string' ? o : o.ACT_Codigo_Interno)}
            onChange={(_, v) => {
              if (typeof v === 'string') void agregarPorCodigo(v);
              else if (v) agregar(v);
            }}
            renderOption={(props, o) => {
              const libre = esLibre(o);
              const enLista = lista.some((x) => x.ACT_Activo === o.ACT_Activo);
              return (
                <Box component="li" {...props} key={o.ACT_Activo} sx={{ opacity: enLista ? 0.5 : 1 }}>
                  <Box sx={{ minWidth: 0, flex: 1 }}>
                    <Typography variant="body2" fontWeight={700}>{o.ACT_Codigo_Interno} · {tipoDe(o.TAC_Tipo_Activo)}</Typography>
                    <Typography variant="caption" color="text.secondary" sx={{ overflowWrap: 'anywhere' }}>
                      {[o.ACT_Serie_Fabricante, o.ACT_Hostname].filter(Boolean).join(' · ')}
                    </Typography>
                  </Box>
                  <Chip size="small" variant="outlined" color={libre ? 'success' : 'warning'} label={stateLabel(t, estadoDe(o))} />
                </Box>
              );
            }}
            renderInput={(params) => (
              <TextField
                {...params}
                label={t('handover.add_equipment')}
                placeholder={t('handover.add_placeholder')}
                helperText={tipoFiltro ? t('handover.filtered_by', { type: tipoDe(tipoFiltro) }) : t('handover.scanner_help')}
                InputProps={{
                  ...params.InputProps,
                  startAdornment: <QrCodeScannerIcon fontSize="small" sx={{ color: 'text.secondary', mr: 0.5 }} />,
                  endAdornment: <>{buscando && <CircularProgress size={16} />}{params.InputProps.endAdornment}</>,
                }}
              />
            )}
          />
          {lista.length > 0 ? (
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>{t('assets.table.code')}</TableCell>
                  <TableCell>{t('assets.table.type')}</TableCell>
                  <TableCell>{t('handover.current_status')}</TableCell>
                  <TableCell align="right" />
                </TableRow>
              </TableHead>
              <TableBody>
                {lista.map((a) => {
                  const custodio = custodios[a.ACT_Activo];
                  const propio = !!custodio && custodio === nombre;
                  return (
                    <TableRow key={a.ACT_Activo}>
                      <TableCell sx={{ fontWeight: 700 }}>{a.ACT_Codigo_Interno}</TableCell>
                      <TableCell>{tipoDe(a.TAC_Tipo_Activo)}</TableCell>
                      <TableCell>
                        {propio ? (
                          <Typography variant="caption" color="error.main">{t('handover.already_with_person')}</Typography>
                        ) : custodio ? (
                          <Typography variant="caption" color="warning.main">{t('handover.will_transfer', { name: custodio })}</Typography>
                        ) : (
                          <Typography variant="caption" color="text.secondary">{stateLabel(t, estadoDe(a))}</Typography>
                        )}
                      </TableCell>
                      <TableCell align="right">
                        <Tooltip title={t('handover.remove')}>
                          <IconButton size="small" aria-label={t('handover.remove')} onClick={() => setLista((l) => l.filter((x) => x.ACT_Activo !== a.ACT_Activo))}>
                            <DeleteOutlineIcon fontSize="small" />
                          </IconButton>
                        </Tooltip>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          ) : (
            <Typography variant="body2" color="text.secondary">{t('handover.empty_list')}</Typography>
          )}
        </Stack>
      </SectionCard>

      <SectionCard title={t('handover.sections.licenses')}>
        {licDisponibles.length === 0 ? (
          <Typography variant="body2" color="text.secondary">{t('handover.licenses_none')}</Typography>
        ) : (
          <Stack>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>{t('handover.licenses_help')}</Typography>
            {licDisponibles.map((l) => (
              <FormControlLabel key={l.LIC_Licencia}
                control={<Checkbox checked={licencias.includes(l.LIC_Licencia)}
                  onChange={(e) => setLicencias((prev) => e.target.checked
                    ? [...prev, l.LIC_Licencia] : prev.filter((x) => x !== l.LIC_Licencia))} />}
                label={t('handover.license_option', { name: l.software, count: l.libres ?? 0 })} />
            ))}
          </Stack>
        )}
      </SectionCard>

      <SectionCard title={t('handover.sections.record')}>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
          <Box sx={{ flex: 1 }}>
            <CatalogField clave="motivos-movimiento" aplicaA="ASIGNACION" label={t('movement_reason.reason')} required
              value={motivo?.id ?? null} onChange={(_v, item) => setMotivo(item)} />
          </Box>
          <TextField label={t('movement_reason.note')} value={detalleMotivo} onChange={(e) => setDetalleMotivo(e.target.value)}
            sx={{ flex: 1 }} inputProps={{ maxLength: 255 }} error={faltaDetalle}
            helperText={faltaDetalle ? t('returns.detail_required') : t('movement_reason.note_help')} />
          <TextField label={t('handover.ticket')} value={ticket} onChange={(e) => setTicket(e.target.value)} sx={{ minWidth: { sm: 180 } }} inputProps={{ maxLength: 50 }} />
        </Stack>
      </SectionCard>

      {propios.length > 0 && <Alert severity="error">{t('handover.remove_owned')}</Alert>}

      <Stack direction="row" spacing={1} justifyContent="flex-end">
        {onCancel && <Button color="inherit" onClick={onCancel}>{t('common.cancel')}</Button>}
        <Button variant="contained" onClick={enviar} disabled={!puedeEnviar} startIcon={enviando ? <CircularProgress size={16} color="inherit" /> : undefined}>
          {t('handover.submit', { count: lista.length })}
        </Button>
      </Stack>
    </Stack>
  );
};
