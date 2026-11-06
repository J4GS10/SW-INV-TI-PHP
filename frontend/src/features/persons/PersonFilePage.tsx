import { useCallback, useEffect, useState } from 'react';
import { Box, Button, Checkbox, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, FormControlLabel, IconButton, Link, Stack, Tab, Table, TableBody, TableCell, TableHead, TableRow, Tabs, Tooltip, Typography } from '@mui/material';
import AssignmentIndIcon from '@mui/icons-material/AssignmentInd';
import AssignmentReturnIcon from '@mui/icons-material/AssignmentReturn';
import DescriptionIcon from '@mui/icons-material/Description';
import KeyIcon from '@mui/icons-material/Key';
import LinkOffIcon from '@mui/icons-material/LinkOff';
import LogoutIcon from '@mui/icons-material/Logout';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { Link as RouterLink, useNavigate, useParams } from 'react-router-dom';
import client from '../../api/axios';
import { getApiErrorMessage } from '../../api/errors';
import { guardarArchivo } from '../../api/productividadService';
import { PageHeader, SectionCard } from '../../components/ui';
import { useWorkspace } from '../../context/workspace-context';
import { usePermissions } from '../../hooks/usePermissions';
import { formatDate } from '../../utils/dates';
import { typeLabel } from '../../utils/catalogLabels';
import { normalizeLanguage } from '../../types/language';
import type { Activo } from '../../types/inventory';
import type { LicenciaDisponible } from '../../types/software';
import { movementService } from '../operations/movementService';
import { softwareService } from '../software/softwareService';
import { OffboardingDialog } from './OffboardingDialog';
import { personService } from './personService';
import type { PersonaResumen } from '../../types/users';
import { Dialog } from '../../components/ui/Dialog';

interface Ficha {
  persona: {
    PER_Persona: string; nombre: string; PER_Email_Corporativo: string; PER_Telefono: string | null; PER_Estado: boolean;
    departamento: string | null; cargo: string | null; jefe: string | null; PER_Jefe: string | null; sede: string | null;
  };
  equipos: Array<{ MOV_Movimiento: string; ACT_Activo: string; codigo: string; hostname: string | null; tipo: string | null;
    area: string | null; desde: string; devolucion_prevista: string | null }>;
  licencias: Array<{ INS_Instalacion: number; LIC_Licencia: number; software: string; cuenta: string | null; desde: string; vence: string | null }>;
  consumibles: Array<{ MOC_Movimiento: number; nombre: string; cantidad: number; unidad: string; fecha: string; motivo: string | null }>;
  historial: Array<{ KDX_Evento: number; fecha: string; tipo: string; referencia: string | null; motivo: string | null; sentido: string }>;
}

type Pestana = 'equipos' | 'licencias' | 'consumibles' | 'historial';

/**
 * Ficha 360 de la persona como página (/empleados/:id): sus datos, equipos en
 * custodia, licencias y cuentas, consumibles recibidos e historial, con las
 * acciones del día a día (entregar puesto, devolver todo, asignar licencia,
 * acta consolidada y salida del empleado).
 */
export const PersonFilePage = () => {
  const { personaId } = useParams<{ personaId: string }>();
  const { t, i18n } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const navigate = useNavigate();
  const { abrirEntrega, abrirDevolucion, agregarReciente } = useWorkspace();
  const { canOperate, canAdminInventory } = usePermissions();
  const [ficha, setFicha] = useState<Ficha | null>(null);
  const [persona, setPersona] = useState<PersonaResumen | null>(null);
  const [pestana, setPestana] = useState<Pestana>('equipos');
  const [salida, setSalida] = useState(false);
  const [asignarLic, setAsignarLic] = useState(false);
  const [disponibles, setDisponibles] = useState<LicenciaDisponible[]>([]);
  const [elegidas, setElegidas] = useState<number[]>([]);

  const cargar = useCallback(async () => {
    if (!personaId) return;
    try {
      const [f, p] = await Promise.all([
        client.get<Ficha>(`/productividad/personas/${personaId}/ficha`).then((r) => r.data),
        personService.get(personaId),
      ]);
      setFicha(f);
      setPersona(p);
      agregarReciente({ tipo: 'persona', id: personaId, titulo: f.persona.nombre, subtitulo: f.persona.PER_Email_Corporativo,
        ruta: `/empleados/${personaId}` });
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_loading')), { variant: 'error' });
      navigate('/empleados', { replace: true });
    }
  }, [personaId, agregarReciente, enqueueSnackbar, t, navigate]);

  useEffect(() => { void Promise.resolve().then(cargar); }, [cargar]);

  if (!ficha || !persona) {
    return <Box display="flex" justifyContent="center" py={6}><CircularProgress /></Box>;
  }
  const p = ficha.persona;
  const activos = ficha.equipos.map((e) => ({ ACT_Activo: e.ACT_Activo, ACT_Codigo_Interno: e.codigo }) as Activo);

  const actaConsolidada = async () => {
    try {
      const blob = await movementService.downloadActaLote(ficha.equipos.map((e) => e.MOV_Movimiento), 'pdf', 'entrega',
        normalizeLanguage(i18n.resolvedLanguage));
      guardarArchivo(blob, `acta-${p.nombre}.pdf`);
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_generic')), { variant: 'error' });
    }
  };

  const abrirAsignarLicencia = async () => {
    setElegidas([]);
    setAsignarLic(true);
    setDisponibles(await softwareService.licenciasDisponibles('PERSONA').catch(() => []));
  };

  const asignarLicencias = async () => {
    try {
      for (const lic of elegidas) {
        await softwareService.createInstalacion({ LIC_Licencia: lic, PER_Persona: p.PER_Persona });
      }
      enqueueSnackbar(t('person_file.licenses_assigned', { count: elegidas.length }), { variant: 'success' });
      setAsignarLic(false);
      await cargar();
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
      await cargar();
    }
  };

  const liberar = async (lic: number) => {
    try {
      await softwareService.desinstalarSoftware({ LIC_Licencia: lic, PER_Persona: p.PER_Persona });
      enqueueSnackbar(t('person_file.license_released'), { variant: 'success' });
      await cargar();
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    }
  };

  const conteo = { equipos: ficha.equipos.length, licencias: ficha.licencias.length, consumibles: ficha.consumibles.length,
    historial: ficha.historial.length };

  return (
    <Box>
      <PageHeader
        title={p.nombre}
        subtitle={[p.cargo, p.departamento, p.sede].filter(Boolean).join(' · ') || p.PER_Email_Corporativo}
        crumb={p.nombre}
        actions={p.PER_Estado && canOperate ? (
          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
            <Button variant="contained" startIcon={<AssignmentIndIcon />}
              onClick={() => abrirEntrega({ persona, onDone: () => void cargar() })}>
              {t('handover.button')}
            </Button>
            {ficha.equipos.length > 0 && (
              <Button variant="outlined" startIcon={<AssignmentReturnIcon />}
                onClick={() => abrirDevolucion({ activos, onDone: () => void cargar() })}>
                {t('person_file.return_all')}
              </Button>
            )}
            {canAdminInventory && (
              <Button variant="outlined" startIcon={<KeyIcon />} onClick={() => void abrirAsignarLicencia()}>
                {t('person_file.assign_license')}
              </Button>
            )}
            {ficha.equipos.length > 0 && (
              <Button variant="outlined" startIcon={<DescriptionIcon />} onClick={() => void actaConsolidada()}>
                {t('person_file.consolidated_record')}
              </Button>
            )}
            {canAdminInventory && (
              <Button variant="outlined" color="warning" startIcon={<LogoutIcon />} onClick={() => setSalida(true)}>
                {t('persons.offboarding.from_detail')}
              </Button>
            )}
          </Stack>
        ) : undefined}
      />

      <SectionCard title={t('persons.sections.general')}>
        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' } }}>
          {[
            [t('person_file.email'), p.PER_Email_Corporativo],
            [t('person_file.phone'), p.PER_Telefono],
            [t('persons.ad_manager'), p.jefe],
            [t('person_file.status'), p.PER_Estado ? t('common.active') : t('persons.inactive')],
          ].map(([etiqueta, valor]) => (
            <Box key={etiqueta}>
              <Typography variant="caption" color="text.secondary">{etiqueta}</Typography>
              <Typography variant="body2" sx={{ fontWeight: 600, wordBreak: 'break-word' }}>
                {etiqueta === t('persons.ad_manager') && p.PER_Jefe
                  ? <Link component={RouterLink} underline="hover" to={`/empleados/${p.PER_Jefe}`}>{valor}</Link> : (valor || '—')}
              </Typography>
            </Box>
          ))}
        </Box>
      </SectionCard>

      <Box sx={{ mt: 2 }}>
        <Tabs value={pestana} onChange={(_e, v) => setPestana(v)} variant="scrollable" allowScrollButtonsMobile sx={{ mb: 1 }}>
          {(['equipos', 'licencias', 'consumibles', 'historial'] as Pestana[]).map((k) => (
            <Tab key={k} value={k} label={`${t(`person_file.tabs.${k}`)} (${conteo[k]})`} />
          ))}
        </Tabs>
        <SectionCard title={t(`person_file.tabs.${pestana}`)}>
          <Box sx={{ overflowX: 'auto' }}>
            {pestana === 'equipos' && (ficha.equipos.length === 0
              ? <Typography variant="body2" color="text.secondary">{t('persons.no_assets')}</Typography>
              : (
                <Table size="small">
                  <TableHead><TableRow>
                    <TableCell>{t('person_file.code')}</TableCell><TableCell>{t('person_file.type')}</TableCell>
                    <TableCell>{t('person_file.area')}</TableCell><TableCell>{t('person_file.since')}</TableCell>
                  </TableRow></TableHead>
                  <TableBody>
                    {ficha.equipos.map((e) => (
                      <TableRow key={e.MOV_Movimiento} hover>
                        <TableCell><Link component={RouterLink} underline="hover" to={`/activos/${encodeURIComponent(e.codigo)}`}>{e.codigo}</Link>
                          {e.hostname && <Typography variant="caption" display="block" color="text.secondary">{e.hostname}</Typography>}</TableCell>
                        <TableCell>{typeLabel(t, e.tipo)}</TableCell>
                        <TableCell>{e.area ?? '—'}</TableCell>
                        <TableCell>{formatDate(e.desde, i18n.language)}
                          {e.devolucion_prevista && <Chip size="small" sx={{ ml: 1 }} label={t('person_file.due', { date: formatDate(e.devolucion_prevista, i18n.language) })} />}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              ))}
            {pestana === 'licencias' && (ficha.licencias.length === 0
              ? <Typography variant="body2" color="text.secondary">{t('person_file.no_licenses')}</Typography>
              : (
                <Table size="small">
                  <TableHead><TableRow>
                    <TableCell>{t('person_file.software')}</TableCell><TableCell>{t('person_file.account')}</TableCell>
                    <TableCell>{t('person_file.since')}</TableCell><TableCell />
                  </TableRow></TableHead>
                  <TableBody>
                    {ficha.licencias.map((l) => (
                      <TableRow key={l.INS_Instalacion}>
                        <TableCell>{l.software}</TableCell>
                        <TableCell>{l.cuenta ?? '—'}</TableCell>
                        <TableCell>{formatDate(l.desde, i18n.language)}</TableCell>
                        <TableCell align="right">
                          {canOperate && (
                            <Tooltip title={t('person_file.release_license')}>
                              <IconButton size="small" aria-label={t('person_file.release_license')} onClick={() => void liberar(l.LIC_Licencia)}>
                                <LinkOffIcon fontSize="small" />
                              </IconButton>
                            </Tooltip>
                          )}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              ))}
            {pestana === 'consumibles' && (ficha.consumibles.length === 0
              ? <Typography variant="body2" color="text.secondary">{t('person_file.no_consumables')}</Typography>
              : (
                <Table size="small">
                  <TableHead><TableRow>
                    <TableCell>{t('person_file.item')}</TableCell><TableCell align="right">{t('person_file.quantity')}</TableCell>
                    <TableCell>{t('person_file.date')}</TableCell>
                  </TableRow></TableHead>
                  <TableBody>
                    {ficha.consumibles.map((c) => (
                      <TableRow key={c.MOC_Movimiento}>
                        <TableCell>{c.nombre}</TableCell>
                        <TableCell align="right">{c.cantidad} {c.unidad}</TableCell>
                        <TableCell>{formatDate(c.fecha, i18n.language)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              ))}
            {pestana === 'historial' && (ficha.historial.length === 0
              ? <Typography variant="body2" color="text.secondary">{t('person_file.no_history')}</Typography>
              : (
                <Table size="small">
                  <TableHead><TableRow>
                    <TableCell>{t('person_file.date')}</TableCell><TableCell>{t('person_file.event')}</TableCell>
                    <TableCell>{t('person_file.reference')}</TableCell><TableCell>{t('person_file.reason')}</TableCell>
                  </TableRow></TableHead>
                  <TableBody>
                    {ficha.historial.map((k) => (
                      <TableRow key={k.KDX_Evento}>
                        <TableCell sx={{ whiteSpace: 'nowrap' }}>{formatDate(k.fecha, i18n.language)}</TableCell>
                        <TableCell>{t(`kardex.tipos.${k.tipo}`, { defaultValue: k.tipo })}</TableCell>
                        <TableCell>{k.referencia ?? '—'}</TableCell>
                        <TableCell>{k.motivo ?? '—'}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              ))}
          </Box>
        </SectionCard>
      </Box>

      <OffboardingDialog open={salida} persona={persona} onClose={() => setSalida(false)} onCompleted={() => void cargar()} />

      <Dialog open={asignarLic} onClose={() => setAsignarLic(false)} maxWidth="xs" fullWidth>
        <DialogTitle>{t('person_file.assign_license')}</DialogTitle>
        <DialogContent>
          {disponibles.length === 0
            ? <Typography variant="body2" color="text.secondary">{t('handover.licenses_none')}</Typography>
            : disponibles.map((l) => (
              <FormControlLabel key={l.LIC_Licencia} sx={{ display: 'flex' }}
                control={<Checkbox checked={elegidas.includes(l.LIC_Licencia)}
                  onChange={(e) => setElegidas((prev) => e.target.checked ? [...prev, l.LIC_Licencia] : prev.filter((x) => x !== l.LIC_Licencia))} />}
                label={t('handover.license_option', { name: l.software, count: l.libres ?? 0 })} />
            ))}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setAsignarLic(false)}>{t('common.cancel')}</Button>
          <Button variant="contained" disabled={elegidas.length === 0} onClick={() => void asignarLicencias()}>
            {t('person_file.assign')}
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};
