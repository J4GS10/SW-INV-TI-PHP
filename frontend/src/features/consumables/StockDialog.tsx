import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { Alert, DialogTitle, DialogContent, DialogActions, Autocomplete, Box, Button, TextField, CircularProgress, Typography, Stack, ToggleButton, ToggleButtonGroup } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { MoneyField, SectionCard } from '../../components/ui';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { consumableService } from './consumableService';
import { Consumible, MovimientoConsumible, StockMovimientoCreate } from '../../types/consumables';
import { personService } from '../persons/personService';
import { PersonaResumen } from '../../types/users';
import { createSearchFilter } from '../../utils/autocomplete';
import { DEFAULT_CURRENCY } from '../../utils/currency';
import { usePermissions } from '../../hooks/usePermissions';
import { assetService } from '../assets/assetService';
import type { Activo } from '../../types/inventory';
import { Dialog } from '../../components/ui/Dialog';
export type StockModo = 'entrada' | 'salida' | 'ajuste';
interface Props {
    open: boolean;
    modo: StockModo;
    consumible: Consumible | null;
    /** `reponer`: el usuario pidió generar una orden de compra para reponer. */
    onClose: (refresh?: boolean, reponer?: { consumible: Consumible; sugerido: number }) => void;
}
interface StockFormValues {
    MOC_Cantidad: string | number;
    MOC_Motivo: string;
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

/**
 * Entrada (con costo y moneda), salida (a una persona) o ajuste por conteo
 * físico. Si una salida deja el stock en el mínimo o por debajo, el diálogo lo
 * dice y ofrece generar la orden de compra de reposición.
 */
export const StockDialog = ({ open, modo, consumible, onClose }: Props) => {
    const { t } = useTranslation();
    const { enqueueSnackbar } = useSnackbar();
    const { canAdminInventory, canSeeCosts } = usePermissions();
    const { register, handleSubmit, reset, formState: { errors } } = useForm<StockFormValues>({ mode: 'onChange' });
    const [loading, setLoading] = useState(false);
    const [personas, setPersonas] = useState<PersonaResumen[]>([]);
    const [persona, setPersona] = useState('');
    // Salida a una persona o instalada en un equipo (tóner en una impresora).
    const [destino, setDestino] = useState<'persona' | 'equipo'>('persona');
    const [equipos, setEquipos] = useState<Activo[]>([]);
    const [equipo, setEquipo] = useState<Activo | null>(null);
    const [buscarEquipo, setBuscarEquipo] = useState('');
    const [compatibles, setCompatibles] = useState<number[]>([]);
    const [precio, setPrecio] = useState('');
    const [moneda, setMoneda] = useState<string>(DEFAULT_CURRENCY);
    const [bajoMinimo, setBajoMinimo] = useState<MovimientoConsumible | null>(null);
    useEffect(() => {
        if (!open)
            return;
        reset({ MOC_Cantidad: modo === 'ajuste' ? consumible?.CON_Stock_Actual ?? 0 : 1, MOC_Motivo: '' });
        setPersona('');
        setEquipo(null);
        setDestino('persona');
        setBuscarEquipo('');
        setCompatibles([]);
        if (modo === 'salida' && consumible) {
            consumableService.compatibles(consumible.CON_Consumible)
                .then((c) => { setCompatibles(c.map((x) => x.MOD_Modelo)); if (c.length) setDestino('equipo'); })
                .catch(() => setCompatibles([]));
        }
        setPrecio(consumible?.CON_Costo_Unitario != null ? String(consumible.CON_Costo_Unitario) : '');
        setMoneda(consumible?.CON_Moneda ?? DEFAULT_CURRENCY);
        setBajoMinimo(null);
        if (modo === 'salida') {
            personService.list().then((p) => setPersonas(p.filter((x) => x.PER_Estado !== false))).catch(() => setPersonas([]));
        }
    }, [open, reset, modo, consumible]);
    useEffect(() => {
        if (!open || modo !== 'salida' || destino !== 'equipo')
            return;
        const id = window.setTimeout(() => {
            assetService.searchActivos({ q: buscarEquipo.trim() || undefined, per_page: 30 })
                .then((r) => setEquipos(r.items)).catch(() => setEquipos([]));
        }, 250);
        return () => window.clearTimeout(id);
    }, [open, modo, destino, buscarEquipo]);
    // Primero los equipos de modelos compatibles.
    const equiposOrdenados = [...equipos].sort((a, b) => Number(compatibles.includes(b.MOD_Modelo)) - Number(compatibles.includes(a.MOD_Modelo)));
    const onSubmit = async (data: StockFormValues) => {
        if (!consumible)
            return;
        setLoading(true);
        try {
            if (modo === 'ajuste') {
                const r = await consumableService.ajuste(consumible.CON_Consumible, {
                    CON_Stock_Real: Number(data.MOC_Cantidad), MOC_Motivo: data.MOC_Motivo.trim(),
                });
                // Todo ajuste (suba o baje) queda pendiente de aprobación dotro administrador de TI.
                const pendiente = 'APR_Aprobacion' in r;
                enqueueSnackbar(t(pendiente ? 'consumables.adjust_requested' : 'consumables.adjust_done'),
                    { variant: pendiente ? 'info' : 'success' });
                onClose(true);
                return;
            }
            const payload: StockMovimientoCreate = {
                MOC_Cantidad: Number(data.MOC_Cantidad),
                MOC_Motivo: data.MOC_Motivo || undefined,
            };
            if (modo === 'salida' && destino === 'persona' && persona)
                payload.PER_Persona = persona;
            if (modo === 'salida' && destino === 'equipo' && equipo)
                payload.ACT_Activo = equipo.ACT_Activo;
            if (modo === 'entrada' && precio !== '') {
                payload.precio_unitario = precio;
                payload.moneda = moneda;
            }
            const mov = modo === 'entrada'
                ? await consumableService.entrada(consumible.CON_Consumible, payload)
                : await consumableService.salida(consumible.CON_Consumible, payload);
            enqueueSnackbar(t('consumables.stock_updated'), { variant: 'success' });
            if (mov.bajo_minimo) {
                // Diálogo: se queda abierto para ofrecer la reposición.
                setBajoMinimo(mov);
                return;
            }
            onClose(true);
        }
        catch (err: unknown) {
            enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    };
    const title = modo === 'entrada'
        ? t('consumables.entry_title')
        : modo === 'salida' ? t('consumables.exit_title') : t('consumables.adjust_title');

    if (bajoMinimo && consumible) {
        const minimo = bajoMinimo.CON_Stock_Minimo ?? consumible.CON_Stock_Minimo;
        // Sugerencia: volver al doble del mínimo.
        const sugerido = Math.max(minimo * 2 - bajoMinimo.MOC_Stock_Resultante, 1);
        return (<Dialog open={open} onClose={() => onClose(true)} maxWidth="xs" fullWidth>
          <DialogTitle>{t('consumables.low_title')}</DialogTitle>
          <DialogContent>
            <Alert severity="warning">
              {t('consumables.low_message', { name: consumible.CON_Nombre, stock: bajoMinimo.MOC_Stock_Resultante, min: minimo, unit: consumible.CON_Unidad })}
            </Alert>
            {!canAdminInventory && (<Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>{t('consumables.low_notify_admin')}</Typography>)}
          </DialogContent>
          <DialogActions>
            <Button color="inherit" onClick={() => onClose(true)}>{t('consumables.low_later')}</Button>
            {canAdminInventory && (<Button variant="contained" onClick={() => onClose(true, { consumible, sugerido })}>
                {t('consumables.low_create_order', { count: sugerido })}
              </Button>)}
          </DialogActions>
        </Dialog>);
    }

    return (<Dialog open={open} onClose={() => onClose()} maxWidth="xs" fullWidth>
      <DialogTitle>{title}</DialogTitle>
      <form onSubmit={handleSubmit(onSubmit)} noValidate>
        <DialogContent>
          <SectionCard title={t('consumables.sections.movement')} subtitle={consumible ? `${consumible.CON_Nombre} — ${t('consumables.current_stock')}: ${consumible.CON_Stock_Actual} ${consumible.CON_Unidad}` : undefined}>
          {modo === 'ajuste' && (<Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>{t('consumables.adjust_help')}</Typography>)}
          <TextField autoFocus fullWidth margin="dense" type="number"
            label={modo === 'ajuste' ? t('consumables.counted_stock') : t('consumables.quantity')}
            {...register('MOC_Cantidad', { required: true, min: modo === 'ajuste' ? 0 : 1 })}
            error={!!errors.MOC_Cantidad} helperText={errors.MOC_Cantidad ? t('consumables.quantity_invalid') : undefined}/>
          {modo === 'entrada' && canSeeCosts && (<MoneyField fullWidth margin="dense" label={t('consumables.unit_cost')} value={precio} onChange={setPrecio}
              currency={moneda} onCurrencyChange={setMoneda} helperText={t('consumables.unit_cost_help')}/>)}
          {modo === 'salida' && (<ToggleButtonGroup exclusive size="small" value={destino} onChange={(_, v) => v && setDestino(v)} sx={{ mt: 1 }}>
              <ToggleButton value="persona">{t('consumables.to_person')}</ToggleButton>
              <ToggleButton value="equipo">{t('consumables.to_asset')}</ToggleButton>
            </ToggleButtonGroup>)}
          {modo === 'salida' && destino === 'equipo' && (<Autocomplete fullWidth options={equiposOrdenados} value={equipo}
              onChange={(_, v) => setEquipo(v)} onInputChange={(_, v, motivo) => { if (motivo === 'input') setBuscarEquipo(v); }}
              filterOptions={(o) => o} getOptionLabel={(a) => `${a.ACT_Codigo_Interno}${a.ACT_Hostname ? ` · ${a.ACT_Hostname}` : ''}`}
              isOptionEqualToValue={(a, b) => a.ACT_Activo === b.ACT_Activo} noOptionsText={t('common.type_to_search')}
              renderOption={(props, a) => (<Box component="li" {...props} key={a.ACT_Activo}>
                  <Typography variant="body2" sx={{ fontWeight: 700, mr: 1 }}>{a.ACT_Codigo_Interno}</Typography>
                  {compatibles.includes(a.MOD_Modelo) && <Typography variant="caption" color="success.main">{t('consumables.compatible')}</Typography>}
                </Box>)}
              renderInput={(params) => (<TextField {...params} margin="dense" label={t('consumables.target_asset')}
                helperText={equipo && compatibles.length > 0 && !compatibles.includes(equipo.MOD_Modelo) ? t('consumables.not_compatible') : undefined}/>)}/>)}
          {modo === 'salida' && destino === 'persona' && (<Autocomplete fullWidth options={personas} value={personas.find((person) => person.PER_Persona === persona) ?? null} onChange={(_, person) => setPersona(person?.PER_Persona ?? '')} getOptionLabel={personLabel} filterOptions={personFilter} autoHighlight openOnFocus={false} isOptionEqualToValue={(option, selected) => option.PER_Persona === selected.PER_Persona} noOptionsText={t('common.type_to_search')} renderOption={(props, person) => (<Box component="li" {...props} key={person.PER_Persona}>
                  <Stack spacing={0.25} sx={{ minWidth: 0 }}>
                    <Typography variant="body2" sx={{ fontWeight: 700 }}>
                      {personLabel(person)}
                    </Typography>
                    <Typography variant="caption" color="text.secondary" sx={{ overflowWrap: 'anywhere' }}>
                      {person.PER_Email_Corporativo}
                    </Typography>
                  </Stack>
                </Box>)} renderInput={(params) => (<TextField {...params} margin="dense" label={t('consumables.recipient')}/>)}/>)}
          <TextField fullWidth margin="dense" label={t('consumables.reason')} required={modo === 'ajuste'}
            {...register('MOC_Motivo', modo === 'ajuste' ? { validate: (v) => v.trim().length >= 5 } : {})}
            error={!!errors.MOC_Motivo} helperText={modo === 'ajuste' ? t('consumables.adjust_reason_help') : undefined}/>
          </SectionCard>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => onClose()} color="inherit">{t('common.cancel')}</Button>
          <Button type="submit" variant="contained" disabled={loading} color={modo === 'salida' ? 'warning' : 'primary'}>
            {loading ? <CircularProgress size={24}/> : t('common.save')}
          </Button>
        </DialogActions>
      </form>
    </Dialog>);
};
