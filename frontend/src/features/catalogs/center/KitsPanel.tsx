import { useCallback, useEffect, useState } from 'react';
import { Box, Button, Chip, DialogActions, DialogContent, DialogTitle, FormControlLabel, IconButton, MenuItem, Radio, RadioGroup, Stack, Switch, TableCell, TableRow, TextField, Tooltip, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import EditIcon from '@mui/icons-material/Edit';
import DeleteIcon from '@mui/icons-material/DeleteOutline';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import client from '../../../api/axios';
import { catalogoConfigService, type KitEstandar, type KitEstandarInput } from '../../../api/catalogoService';
import { getApiErrorMessage } from '../../../api/errors';
import { DataTableShell } from '../../../components/ui';
import { useConfirm } from '../../../components/confirm-context';
import { usePermissions } from '../../../hooks/usePermissions';
import { typeLabel } from '../../../utils/catalogLabels';
import { catalogService } from '../catalogService';
import type { TipoActivo } from '../../../types/catalogs';
import type { LicenciaDisponible } from '../../../types/software';
import { softwareService } from '../../software/softwareService';
import { Dialog } from '../../../components/ui/Dialog';

type Opcion = { id: number; nombre: string };

const vacio = (): KitEstandarInput => ({
  KIT_Nombre: '', CAR_Cargo: null, DEP_Departamento: null, KIT_Activo: true,
  items: [{ TAC_Tipo_Activo: null, KIT_Cantidad: 1, LIC_Licencia: null }],
});

/**
 * Kits de puesto: qué equipos recibe un cargo o un departamento al ingresar.
 * La entrega de puesto los propone antes que la deducción por frecuencia.
 */
export const KitsPanel = () => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const confirm = useConfirm();
  const { canAdminInventory } = usePermissions();
  const [kits, setKits] = useState<KitEstandar[]>([]);
  const [loading, setLoading] = useState(true);
  const [tipos, setTipos] = useState<TipoActivo[]>([]);
  // Licencias por persona que el kit puede incluir (p. ej. la cuenta de Microsoft 365).
  const [licencias, setLicencias] = useState<LicenciaDisponible[]>([]);
  const [cargos, setCargos] = useState<Opcion[]>([]);
  const [deptos, setDeptos] = useState<Opcion[]>([]);
  const [editando, setEditando] = useState<{ id: number | null; datos: KitEstandarInput } | null>(null);
  const [por, setPor] = useState<'cargo' | 'departamento'>('cargo');

  const cargar = useCallback(async () => {
    setLoading(true);
    try {
      setKits(await catalogoConfigService.kits());
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_loading')), { variant: 'error' });
    } finally {
      setLoading(false);
    }
  }, [enqueueSnackbar, t]);

  useEffect(() => {
    void cargar();
    catalogService.getTiposActivo().then(setTipos).catch(() => setTipos([]));
    softwareService.licenciasDisponibles('PERSONA', true).then(setLicencias).catch(() => setLicencias([]));
    client.get<{ CAR_Cargo: number; CAR_Nombre: string }[]>('/org/cargos')
      .then((r) => setCargos(r.data.map((c) => ({ id: c.CAR_Cargo, nombre: c.CAR_Nombre })))).catch(() => setCargos([]));
    client.get<{ DEP_Departamento: number; DEP_Nombre: string }[]>('/org/departamentos')
      .then((r) => setDeptos(r.data.map((d) => ({ id: d.DEP_Departamento, nombre: d.DEP_Nombre })))).catch(() => setDeptos([]));
  }, [cargar]);

  const abrir = (kit?: KitEstandar) => {
    setPor(kit?.DEP_Departamento ? 'departamento' : 'cargo');
    setEditando(kit
      ? { id: kit.KIT_Kit, datos: { KIT_Nombre: kit.KIT_Nombre, CAR_Cargo: kit.CAR_Cargo, DEP_Departamento: kit.DEP_Departamento,
          KIT_Activo: kit.KIT_Activo, items: kit.items.map(({ TAC_Tipo_Activo, KIT_Cantidad, LIC_Licencia }) => ({ TAC_Tipo_Activo, KIT_Cantidad, LIC_Licencia })) } }
      : { id: null, datos: vacio() });
  };

  const guardar = async () => {
    if (!editando) return;
    const datos = {
      ...editando.datos,
      CAR_Cargo: por === 'cargo' ? editando.datos.CAR_Cargo : null,
      DEP_Departamento: por === 'departamento' ? editando.datos.DEP_Departamento : null,
      items: editando.datos.items.filter((i) => i.TAC_Tipo_Activo || i.LIC_Licencia)
        .map((i) => (i.TAC_Tipo_Activo ? { ...i, LIC_Licencia: null } : i)),
    };
    try {
      if (editando.id) await catalogoConfigService.editarKit(editando.id, datos);
      else await catalogoConfigService.crearKit(datos);
      enqueueSnackbar(t('common.success_update'), { variant: 'success' });
      setEditando(null);
      await cargar();
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    }
  };

  const borrar = async (kit: KitEstandar) => {
    if (!(await confirm({ message: t('catalog_center.kit_delete_confirm', { name: kit.KIT_Nombre }), destructive: true,
      confirmText: t('common.delete') }))) return;
    try {
      await catalogoConfigService.borrarKit(kit.KIT_Kit);
      await cargar();
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_delete')), { variant: 'error' });
    }
  };

  const nombreTipo = (id: number | null) => typeLabel(t, tipos.find((x) => x.TAC_Tipo_Activo === id)?.TAC_Nombre);
  const aplica = (k: KitEstandar) => (k.CAR_Cargo
    ? t('catalog_center.kit_for_position', { name: cargos.find((c) => c.id === k.CAR_Cargo)?.nombre ?? '—' })
    : t('catalog_center.kit_for_department', { name: deptos.find((d) => d.id === k.DEP_Departamento)?.nombre ?? '—' }));

  const items = editando?.datos.items ?? [];
  const setItems = (nuevos: KitEstandarInput['items']) => setEditando((e) => (e ? { ...e, datos: { ...e.datos, items: nuevos } } : e));
  const valido = !!editando && editando.datos.KIT_Nombre.trim().length >= 2
    && (por === 'cargo' ? !!editando.datos.CAR_Cargo : !!editando.datos.DEP_Departamento)
    && items.some((i) => i.TAC_Tipo_Activo || i.LIC_Licencia);

  return (
    <Box>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} alignItems={{ sm: 'center' }} sx={{ mb: 2 }}>
        <Typography variant="body2" color="text.secondary" sx={{ flex: 1 }}>{t('catalog_center.kits_help')}</Typography>
        {canAdminInventory && (
          <Button variant="contained" startIcon={<AddIcon />} onClick={() => abrir()} sx={{ flexShrink: 0 }}>
            {t('catalog_center.kit_new')}
          </Button>
        )}
      </Stack>

      <DataTableShell
        columns={[
          { key: 'name', label: t('catalog_center.name') },
          { key: 'for', label: t('catalog_center.kit_applies') },
          { key: 'items', label: t('catalog_center.kit_items') },
          { key: 'actions', label: '', align: 'right' },
        ]}
        rows={kits}
        loading={loading}
        emptyTitle={t('catalog_center.kits_empty')}
        renderRow={(k) => (
          <TableRow key={k.KIT_Kit} sx={{ opacity: k.KIT_Activo ? 1 : 0.55 }}>
            <TableCell sx={{ fontWeight: 600 }}>{k.KIT_Nombre}</TableCell>
            <TableCell>{aplica(k)}</TableCell>
            <TableCell>
              <Stack direction="row" flexWrap="wrap" gap={0.5}>
                {k.items.filter((i) => i.TAC_Tipo_Activo).map((i) => (
                  <Chip key={i.KIT_Item} size="small" label={`${i.KIT_Cantidad} × ${nombreTipo(i.TAC_Tipo_Activo)}`} />
                ))}
                {k.items.filter((i) => i.LIC_Licencia).map((i) => (
                  <Chip key={i.KIT_Item} size="small" color="primary" variant="outlined"
                    label={licencias.find((l) => l.LIC_Licencia === i.LIC_Licencia)?.software ?? `#${i.LIC_Licencia}`} />
                ))}
              </Stack>
            </TableCell>
            <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
              {canAdminInventory && (
                <>
                  <Tooltip title={t('common.edit')}><IconButton size="small" onClick={() => abrir(k)}><EditIcon fontSize="small" /></IconButton></Tooltip>
                  <Tooltip title={t('common.delete')}><IconButton size="small" onClick={() => void borrar(k)}><DeleteIcon fontSize="small" /></IconButton></Tooltip>
                </>
              )}
            </TableCell>
          </TableRow>
        )}
      />

      <Dialog open={!!editando} onClose={() => setEditando(null)} maxWidth="sm" fullWidth>
        <DialogTitle>{editando?.id ? t('catalog_center.kit_edit') : t('catalog_center.kit_new')}</DialogTitle>
        <DialogContent>
          {editando && (
            <Stack spacing={2} sx={{ mt: 1 }}>
              <TextField label={t('catalog_center.name')} value={editando.datos.KIT_Nombre} required autoFocus
                inputProps={{ maxLength: 100 }}
                onChange={(e) => setEditando({ ...editando, datos: { ...editando.datos, KIT_Nombre: e.target.value } })} />
              <RadioGroup row value={por} onChange={(e) => setPor(e.target.value as 'cargo' | 'departamento')}>
                <FormControlLabel value="cargo" control={<Radio />} label={t('catalog_center.kit_by_position')} />
                <FormControlLabel value="departamento" control={<Radio />} label={t('catalog_center.kit_by_department')} />
              </RadioGroup>
              {por === 'cargo' ? (
                <TextField select required label={t('catalog_center.position')} value={editando.datos.CAR_Cargo ?? ''}
                  onChange={(e) => setEditando({ ...editando, datos: { ...editando.datos, CAR_Cargo: Number(e.target.value) } })}>
                  {cargos.map((c) => <MenuItem key={c.id} value={c.id}>{c.nombre}</MenuItem>)}
                </TextField>
              ) : (
                <TextField select required label={t('catalog_center.department')} value={editando.datos.DEP_Departamento ?? ''}
                  onChange={(e) => setEditando({ ...editando, datos: { ...editando.datos, DEP_Departamento: Number(e.target.value) } })}>
                  {deptos.map((d) => <MenuItem key={d.id} value={d.id}>{d.nombre}</MenuItem>)}
                </TextField>
              )}
              <Typography variant="subtitle2">{t('catalog_center.kit_items')}</Typography>
              {items.map((it, i) => it.LIC_Licencia !== null && !it.TAC_Tipo_Activo ? (
                <Stack key={i} direction="row" spacing={1} alignItems="center">
                  <TextField select size="small" fullWidth label={t('catalog_center.kit_license')} value={it.LIC_Licencia ?? ''}
                    onChange={(e) => setItems(items.map((x, j) => (j === i ? { ...x, LIC_Licencia: Number(e.target.value) } : x)))}>
                    {licencias.map((l) => <MenuItem key={l.LIC_Licencia} value={l.LIC_Licencia}>{l.software}</MenuItem>)}
                  </TextField>
                  <IconButton size="small" aria-label={t('common.delete')} onClick={() => setItems(items.filter((_x, j) => j !== i))}>
                    <DeleteIcon fontSize="small" />
                  </IconButton>
                </Stack>
              ) : (
                <Stack key={i} direction="row" spacing={1} alignItems="center">
                  <TextField select size="small" fullWidth label={t('catalog_center.asset_type')} value={it.TAC_Tipo_Activo ?? ''}
                    onChange={(e) => setItems(items.map((x, j) => (j === i ? { ...x, TAC_Tipo_Activo: Number(e.target.value) } : x)))}>
                    {tipos.map((ta) => <MenuItem key={ta.TAC_Tipo_Activo} value={ta.TAC_Tipo_Activo}>{typeLabel(t, ta.TAC_Nombre)}</MenuItem>)}
                  </TextField>
                  <TextField size="small" type="number" label={t('catalog_center.quantity')} value={it.KIT_Cantidad}
                    inputProps={{ min: 1, max: 50 }} sx={{ width: 110 }}
                    onChange={(e) => setItems(items.map((x, j) => (j === i ? { ...x, KIT_Cantidad: Math.max(1, Math.min(50, Number(e.target.value) || 1)) } : x)))} />
                  <IconButton size="small" aria-label={t('common.delete')} disabled={items.length === 1}
                    onClick={() => setItems(items.filter((_x, j) => j !== i))}>
                    <DeleteIcon fontSize="small" />
                  </IconButton>
                </Stack>
              ))}
              <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                <Button startIcon={<AddIcon />} onClick={() => setItems([...items, { TAC_Tipo_Activo: null, KIT_Cantidad: 1, LIC_Licencia: null }])}>
                  {t('catalog_center.kit_add_item')}
                </Button>
                <Button startIcon={<AddIcon />} disabled={licencias.length === 0}
                  onClick={() => setItems([...items, { TAC_Tipo_Activo: null, KIT_Cantidad: 1, LIC_Licencia: 0 }])}>
                  {t('catalog_center.kit_add_license')}
                </Button>
              </Stack>
              <FormControlLabel control={<Switch checked={editando.datos.KIT_Activo}
                onChange={(e) => setEditando({ ...editando, datos: { ...editando.datos, KIT_Activo: e.target.checked } })} />}
                label={t('catalog_center.active')} />
            </Stack>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setEditando(null)}>{t('common.cancel')}</Button>
          <Button variant="contained" onClick={() => void guardar()} disabled={!valido}>{t('common.save')}</Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};
