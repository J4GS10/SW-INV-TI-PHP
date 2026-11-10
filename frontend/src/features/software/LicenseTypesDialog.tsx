import { useCallback, useEffect, useState } from 'react';
import { Box, Button, CircularProgress, DialogActions, DialogContent, DialogTitle, IconButton, List, ListItem, ListItemButton, ListItemText, Stack, TextField, Tooltip, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import EditIcon from '@mui/icons-material/Edit';
import { useSnackbar } from 'notistack';
import { useTranslation } from 'react-i18next';
import { useConfirm } from '../../components/confirm-context';
import { getApiErrorMessage } from '../../api/errors';
import { TipoLicencia } from '../../types/software';
import { softwareService } from './softwareService';
import { Dialog } from '../../components/ui/Dialog';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';

interface Props { open: boolean; onClose: () => void; }

export const LicenseTypesDialog = ({ open, onClose }: Props) => {
  const { t } = useTranslation();
  const confirm = useConfirm();
  const { enqueueSnackbar } = useSnackbar();
  const [types, setTypes] = useState<TipoLicencia[]>([]);
  const [selected, setSelected] = useState<TipoLicencia | null>(null);
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const load = useCallback(async () => {
    setLoading(true);
    try { setTypes(await softwareService.getTiposLicencia()); }
    catch (error) { enqueueSnackbar(getApiErrorMessage(error, t('common.error_loading')), { variant: 'error' }); }
    finally { setLoading(false); }
  }, [enqueueSnackbar, t]);
  useLiveRefresh(load);
  useEffect(() => { if (open) void load(); }, [load, open]);
  const startCreate = () => { setSelected(null); setName(''); setDescription(''); };
  const startEdit = (type: TipoLicencia) => { setSelected(type); setName(type.TLI_Nombre); setDescription(type.TLI_Descripcion ?? ''); };
  const save = async () => {
    if (!name.trim()) return;
    setSaving(true);
    try {
      const payload = { TLI_Nombre: name.trim(), TLI_Descripcion: description.trim() || undefined };
      if (selected) await softwareService.updateTipoLicencia(selected.TLI_Tipo_Licencia, payload);
      else await softwareService.createTipoLicencia(payload);
      enqueueSnackbar(t(selected ? 'common.success_update' : 'common.success_create'), { variant: 'success' });
      startCreate(); await load();
    } catch (error) { enqueueSnackbar(getApiErrorMessage(error, t('common.error_save')), { variant: 'error' }); }
    finally { setSaving(false); }
  };
  const remove = async (type: TipoLicencia) => {
    if (!(await confirm({ message: t('common.confirm_delete'), destructive: true, confirmText: t('common.delete') }))) return;
    try {
      await softwareService.deleteTipoLicencia(type.TLI_Tipo_Licencia);
      if (selected?.TLI_Tipo_Licencia === type.TLI_Tipo_Licencia) startCreate();
      await load();
    } catch (error) { enqueueSnackbar(getApiErrorMessage(error, t('common.error_delete')), { variant: 'error' }); }
  };
  return <Dialog open={open} onClose={onClose} fullWidth maxWidth="md">
    <DialogTitle>{t('app.license_types_title')}</DialogTitle>
    <DialogContent dividers>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 2.5 }}>{t('app.license_types_subtitle')}</Typography>
      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', md: 'minmax(0, 1fr) minmax(280px, .9fr)' }, gap: 3 }}>
        <Box><Button startIcon={<AddIcon />} onClick={startCreate} variant="outlined" sx={{ mb: 1.25 }}>{t('app.license_types_new')}</Button>
          {loading ? <Box sx={{ display: 'grid', placeItems: 'center', minHeight: 180 }}><CircularProgress /></Box> : <List disablePadding sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 2, overflow: 'hidden' }}>
            {types.map((type) => <ListItem key={type.TLI_Tipo_Licencia} disablePadding secondaryAction={<Stack direction="row" spacing={.5}>
              <Tooltip title={t('common.edit') as string}><IconButton size="small" onClick={() => startEdit(type)}><EditIcon fontSize="small" /></IconButton></Tooltip>
              <Tooltip title={t('common.delete') as string}><IconButton size="small" color="error" onClick={() => void remove(type)}><DeleteOutlineIcon fontSize="small" /></IconButton></Tooltip>
            </Stack>}><ListItemButton selected={selected?.TLI_Tipo_Licencia === type.TLI_Tipo_Licencia} onClick={() => startEdit(type)}><ListItemText primary={type.TLI_Nombre} secondary={type.TLI_Descripcion || t('app.license_types_empty')} /></ListItemButton></ListItem>)}
            {!loading && types.length === 0 && <ListItem><ListItemText primary={t('app.license_types_empty')} /></ListItem>}
          </List>}
        </Box>
        <Box sx={{ p: 2.25, border: '1px solid', borderColor: 'divider', borderRadius: 2, bgcolor: 'action.hover' }}>
          <Typography variant="h6" sx={{ mb: 2 }}>{selected ? t('common.edit') : t('app.license_types_new')}</Typography>
          <Stack spacing={2}><TextField autoFocus fullWidth required label={t('common.name')} value={name} onChange={(event) => setName(event.target.value)} />
            <TextField fullWidth multiline minRows={4} label={t('app.license_types_description')} value={description} onChange={(event) => setDescription(event.target.value)} />
            <Button variant="contained" onClick={() => void save()} disabled={saving || !name.trim()}>{saving ? t('common.saving') : t('common.save')}</Button>
          </Stack>
        </Box>
      </Box>
    </DialogContent>
    <DialogActions><Button onClick={onClose}>{t('common.close')}</Button></DialogActions>
  </Dialog>;
};
