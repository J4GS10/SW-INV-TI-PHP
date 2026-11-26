import { useCallback, useEffect, useState } from 'react';
import { Link as RouterLink, useParams } from 'react-router-dom';
import { Alert, Autocomplete, Box, Button, Chip, DialogActions, DialogContent, DialogTitle, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { PageHeader, SectionCard } from '../../components/ui';
import { getApiErrorMessage } from '../../api/errors';
import { usePermissions } from '../../hooks/usePermissions';
import { assetService } from '../assets/assetService';
import { consumableService } from '../consumables/consumableService';
import { softwareService } from '../software/softwareService';
import { goodsReceiptService, statusColor } from './goodsReceiptService';
import type { ClassifiedLine, GoodsReceipt, GoodsReceiptLine, LineType } from './goodsReceiptService';
import { Dialog } from '../../components/ui/Dialog';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';
import { useCatalogLabel } from '../../hooks/useCatalogo';
import { attachmentService, type Adjunto } from '../attachments/attachmentService';
import AttachFileIcon from '@mui/icons-material/AttachFile';
import DownloadIcon from '@mui/icons-material/Download';
import VisibilityIcon from '@mui/icons-material/VisibilityOutlined';
import { useFilePreview } from '../../components/file-preview/file-preview-context';

/** Clasificar una línea: los campos se acomodan al ancho en vez de quedar en una sola fila que no cabe. */
const CLASSIFY_GRID = {
  display: 'grid', gap: 1.5, mt: 1.5, alignItems: 'start',
  gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, minmax(0, 1fr))', lg: '110px 170px repeat(2, minmax(0, 1fr)) auto' },
} as const;

interface Draft {
  received: string;
  line_type: LineType | '';
  asset_type_id: number | null;
  model_id: number | null;
  consumable_id: number | null;
  consumable_name: string;
  unit: string;
  software_id: number | null;
  license_type_id: number | null;
}
interface Option { id: number; label: string; typeId?: number | null }

const LINE_TYPES: LineType[] = ['ASSET', 'CONSUMABLE', 'LICENSE', 'OTHER'];

export const GoodsReceiptDetailPage = () => {
  const { id } = useParams<{ id: string }>();
  const receiptId = Number(id);
  const { t, i18n } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const { showBlob, preview } = useFilePreview();
  const { canOperate, canPurchase, canSeeCosts, canOpenPurchaseDocs } = usePermissions();
  // Lo que adjuntó Compras: todos ven qué es; abrirlo solo quien puede ver precios.
  const [files, setFiles] = useState<Adjunto[]>([]);
  const categoryLabel = useCatalogLabel('tipos-evidencia');
  useEffect(() => {
    goodsReceiptService.attachments(receiptId).then(setFiles).catch(() => setFiles([]));
  }, [receiptId]);
  const [receipt, setReceipt] = useState<GoodsReceipt | null>(null);
  const [drafts, setDrafts] = useState<Record<number, Draft>>({});
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [types, setTypes] = useState<Option[]>([]);
  const [models, setModels] = useState<Option[]>([]);
  const [consumables, setConsumables] = useState<Option[]>([]);
  const [software, setSoftware] = useState<Option[]>([]);
  const [licenseTypes, setLicenseTypes] = useState<Option[]>([]);
  const [newModel, setNewModel] = useState<{ line: number; typeId: number; brand: string; model: string } | null>(null);
  const [cancelReason, setCancelReason] = useState<string | null>(null);
  const [keys, setKeys] = useState<Record<number, string>>({});
  /** Hay cambios del técnico sin guardar: la recarga en vivo no los pisa. */
  const [editing, setEditing] = useState(false);

  const load = useCallback(async () => {
    const data = await goodsReceiptService.get(receiptId);
    setReceipt(data);
    setEditing(false);
    const initial: Record<number, Draft> = {};
    for (const line of data.lines ?? []) {
      initial[line.id] = { received: String(line.received_quantity ?? line.quantity), line_type: line.line_type ?? '', asset_type_id: line.asset_type_id, model_id: line.model_id, consumable_id: line.consumable_id,
        consumable_name: line.consumable_name ?? '', unit: 'unidad', software_id: line.software_id, license_type_id: null };
    }
    setDrafts(initial);
    return data;
  }, [receiptId]);
  useLiveRefresh(load, { enabled: !editing });

  const canConfirm = !!receipt && receipt.status === 'REGISTERED' && canOperate && !receipt.mine;

  useEffect(() => {
    load().then(async (data) => {
      // Lo clasificado antes con el mismo texto se sugiere de nuevo: menos clics.
      if (data.status !== 'REGISTERED' || !canOperate) return;
      for (const line of data.lines ?? []) {
        const hint = await goodsReceiptService.suggestion(line.item_text).catch(() => null);
        if (hint) {
          setDrafts((all) => ({ ...all, [line.id]: { ...all[line.id], line_type: hint.line_type, asset_type_id: hint.asset_type_id, model_id: hint.model_id, consumable_id: hint.consumable_id,
            consumable_name: hint.consumable_name ?? '', unit: hint.unit ?? 'unidad', software_id: hint.software_id, license_type_id: hint.license_type_id } }));
        }
      }
    }).catch((error: unknown) => enqueueSnackbar(getApiErrorMessage(error, t('common.error_loading')), { variant: 'error' }));
  }, [load, canOperate, enqueueSnackbar, t]);

  useEffect(() => {
    if (!canOperate) return;
    // Cada catálogo carga por su cuenta: si uno falla, los demás siguen disponibles.
    const avisar = () => enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
    assetService.getTipos().then((tipos) => setTypes(tipos.map((x) => ({ id: x.TAC_Tipo_Activo, label: x.TAC_Nombre })))).catch(avisar);
    assetService.getModelosFlat().then((modelos) => setModels(modelos.map((x) => ({ id: x.MOD_Modelo, label: `${x.MAR_Nombre} ${x.MOD_Nombre}`, typeId: x.TAC_Tipo_Activo })))).catch(avisar);
    consumableService.list().then((cons) => setConsumables(cons.map((x) => ({ id: x.CON_Consumible, label: x.CON_Nombre })))).catch(avisar);
    softwareService.getSoftware().then((soft) => setSoftware(soft.map((x) => ({ id: x.SOF_Software, label: x.SOF_Nombre })))).catch(avisar);
    softwareService.getTiposLicencia().then((tipos) => setLicenseTypes(tipos.map((x) => ({ id: x.TLI_Tipo_Licencia, label: x.TLI_Nombre })))).catch(avisar);
  }, [canOperate, enqueueSnackbar, t]);

  const update = (line: number, patch: Partial<Draft>) => {
    setEditing(true);
    setDrafts((all) => ({ ...all, [line]: { ...all[line], ...patch } }));
  };
  // Varias líneas iguales: se clasifica una y se copia a las que falten, sin tocar lo recibido.
  const copyClassification = (from: number) => {
    setEditing(true);
    setDrafts((all) => {
      const source = all[from];
      return Object.fromEntries(Object.entries(all).map(([id, draft]) => [id, Number(id) === from || draft.line_type ? draft : { ...source, received: draft.received }]));
    });
  };

  const lineComplete = (draft?: Draft) => {
    if (!draft || !/^\d+$/.test(draft.received)) return false;
    switch (draft.line_type) {
      case 'ASSET': return !!draft.asset_type_id && !!draft.model_id;
      case 'CONSUMABLE': return !!draft.consumable_id || draft.consumable_name.trim() !== '';
      case 'LICENSE': return !!draft.software_id;
      case 'OTHER': return true;
      default: return false;
    }
  };
  const lines = receipt?.lines ?? [];
  const hasDifference = lines.some((line) => Number(drafts[line.id]?.received) !== line.quantity);
  const ready = lines.length > 0 && lines.every((line) => lineComplete(drafts[line.id])) && (!hasDifference || reason.trim() !== '');

  const confirm = async () => {
    setSaving(true);
    try {
      const payload: ClassifiedLine[] = lines.map((line) => {
        const d = drafts[line.id];
        return { id: line.id, received_quantity: Number(d.received), line_type: d.line_type as LineType, asset_type_id: d.asset_type_id ?? undefined, model_id: d.model_id ?? undefined,
          consumable_id: d.consumable_id ?? undefined, consumable_name: d.consumable_name.trim() || undefined, unit: d.unit, software_id: d.software_id ?? undefined, license_type_id: d.license_type_id ?? undefined };
      });
      const result = await goodsReceiptService.confirm(receiptId, payload, hasDifference ? reason.trim() : undefined);
      enqueueSnackbar(t(result.status === 'PENDING_APPROVAL' ? 'goods_receipt.waiting_approval' : 'goods_receipt.confirmed'), { variant: result.status === 'PENDING_APPROVAL' ? 'info' : 'success' });
      await load();
    } catch (error: unknown) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_save')), { variant: 'error' });
    } finally {
      setSaving(false);
    }
  };
  const saveModel = async () => {
    if (!newModel) return;
    try {
      const created = await goodsReceiptService.createModel(newModel.typeId, newModel.brand, newModel.model);
      setModels((all) => (all.some((m) => m.id === created.model_id) ? all : [...all, { id: created.model_id, label: `${newModel.brand} ${newModel.model}`, typeId: newModel.typeId }]));
      update(newModel.line, { model_id: created.model_id });
      enqueueSnackbar(t(created.created ? 'goods_receipt.model_created' : 'goods_receipt.model_existed'), { variant: 'success' });
      setNewModel(null);
    } catch (error: unknown) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_save')), { variant: 'error' });
    }
  };
  const downloadAct = async () => {
    try {
      showBlob(await goodsReceiptService.act(receiptId, i18n.language.slice(0, 2)), `acta-recibido-${receipt?.code}.pdf`);
    } catch (error: unknown) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_generic')), { variant: 'error' });
    }
  };
  const cancel = async () => {
    try {
      await goodsReceiptService.cancel(receiptId, cancelReason ?? '');
      setCancelReason(null);
      enqueueSnackbar(t('goods_receipt.cancelled'), { variant: 'success' });
      await load();
    } catch (error: unknown) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_save')), { variant: 'error' });
    }
  };
  const saveKeys = async (line: GoodsReceiptLine) => {
    try {
      await goodsReceiptService.completeKeys(line.id, (keys[line.id] ?? '').split('\n').map((k) => k.trim()).filter(Boolean));
      enqueueSnackbar(t('goods_receipt.keys_saved'), { variant: 'success' });
      await load();
    } catch (error: unknown) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_save')), { variant: 'error' });
    }
  };

  if (!receipt) return null;
  const entered = receipt.status === 'CONFIRMED' || receipt.status === 'COMPLETED';
  const registeredBy = (receipt.events ?? []).find((e) => e.event === 'REGISTERED') ?? null;

  return (<Box sx={{ maxWidth: 1200, mx: 'auto' }}>
      <PageHeader title={`${t('goods_receipt.code')} ${receipt.code}`} subtitle={`${receipt.supplier} · ${receipt.document_number} · ${receipt.received_date} · ${receipt.site}`}
        actions={<Stack direction="row" spacing={1} alignItems="center">
            <Chip color={statusColor(receipt.status)} label={t(`goods_receipt.states.${receipt.status}`)}/>
            {entered && <Button variant="outlined" onClick={() => { void downloadAct(); }}>{t('goods_receipt.download_act')}</Button>}
            {receipt.status === 'CONFIRMED' && canOperate && <Button component={RouterLink} to="/ingresos/por-completar" variant="contained">{t('goods_receipt.to_complete')}</Button>}
            {receipt.status === 'REGISTERED' && canPurchase && <Button color="inherit" onClick={() => setCancelReason('')}>{t('goods_receipt.cancel')}</Button>}
          </Stack>}/>
      {receipt.status === 'REGISTERED' && !canOperate && <Alert severity="info" sx={{ mb: 2 }}>{t('goods_receipt.waiting_it')}</Alert>}
      {receipt.status === 'REGISTERED' && canOperate && !canConfirm && <Alert severity="info" sx={{ mb: 2 }}>{t('goods_receipt.other_must_confirm')}</Alert>}
      {receipt.status === 'PENDING_APPROVAL' && <Alert severity="warning" sx={{ mb: 2 }}>{t('goods_receipt.pending_approval_help')}: {receipt.difference_reason}</Alert>}

      {/* Lo que registró Compras, para que TI confirme sabiendo qué llegó y con qué documento. */}
      <SectionCard title={t('goods_receipt.purchase_section')} subtitle={t('goods_receipt.purchase_section_help')}>
        <Box component="dl" sx={{ m: 0, display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, minmax(0, 1fr))', md: 'repeat(3, minmax(0, 1fr))' },
          '& dt': { typography: 'caption', color: 'text.secondary' }, '& dd': { m: 0, typography: 'body2', overflowWrap: 'anywhere' } }}>
          {([
            ['goods_receipt.supplier', receipt.supplier],
            ['goods_receipt.document', receipt.document_number],
            ['goods_receipt.received_date', receipt.received_date],
            ['goods_receipt.site', receipt.site],
            ['goods_receipt.currency', receipt.currency],
            ['goods_receipt.registered_by', registeredBy ? `${registeredBy.user ?? '—'} · ${new Date(registeredBy.at).toLocaleString(i18n.language)}` : '—'],
          ] as const).map(([label, value]) => (<Box key={label}><dt>{t(label)}</dt><dd>{value || '—'}</dd></Box>))}
        </Box>
        <Box sx={{ mt: 2 }}>
          <Typography variant="caption" color="text.secondary" component="p">{t('goods_receipt.notes')}</Typography>
          <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{receipt.notes || t('goods_receipt.no_notes')}</Typography>
        </Box>
        <Box sx={{ mt: 2 }}>
          <Typography variant="caption" color="text.secondary" component="p">{t('goods_receipt.files')}</Typography>
          {files.length === 0 ? <Typography variant="body2">{t('goods_receipt.no_files')}</Typography> : (
            <Stack spacing={1} sx={{ mt: 0.5 }}>
              {files.map((f, i) => (<Stack key={f.ADJ_Adjunto} direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
                  <AttachFileIcon fontSize="small" color="action"/>
                  <Typography variant="body2" sx={{ overflowWrap: 'anywhere' }}>{f.ADJ_Nombre_Original}</Typography>
                  <Typography variant="caption" color="text.secondary">
                    {[categoryLabel(f.ADJ_Categoria), `${Math.max(1, Math.round(f.ADJ_Tamano_Bytes / 1024))} KB`, new Date(f.ADJ_Fecha_Subida).toLocaleDateString(i18n.language)].join(' · ')}
                  </Typography>
                  {canOpenPurchaseDocs && <Button size="small" variant="outlined" startIcon={<VisibilityIcon/>} onClick={() => preview(files.map((x) => ({
                    name: x.ADJ_Nombre_Original, mime: x.ADJ_Tipo_MIME, subtitle: categoryLabel(x.ADJ_Categoria),
                    load: () => attachmentService.blob(x.ADJ_Adjunto) })), i)}
                    aria-label={t('file_preview.view_of', { name: f.ADJ_Nombre_Original })}>{t('file_preview.view')}</Button>}
                  {canOpenPurchaseDocs && <Button size="small" startIcon={<DownloadIcon/>} onClick={() => { void attachmentService.download(f); }}
                    aria-label={t('goods_receipt.download_file', { name: f.ADJ_Nombre_Original })}>{t('goods_receipt.download')}</Button>}
                </Stack>))}
              {!canOpenPurchaseDocs && <Typography variant="caption" color="text.secondary">{t('goods_receipt.files_restricted')}</Typography>}
            </Stack>)}
        </Box>
      </SectionCard>

      <Box sx={{ mt: 3 }} />
      <SectionCard title={t('goods_receipt.lines_section')} subtitle={canConfirm ? t('goods_receipt.classify_help') : undefined}>
        <Stack spacing={2}>
          {lines.map((line) => {
            const d = drafts[line.id];
            if (!d) return null;
            return (<Box key={line.id} sx={{ borderBottom: 1, borderColor: 'divider', pb: 2, minWidth: 0 }}>
                <Typography fontWeight={600} sx={{ overflowWrap: 'anywhere' }}>{line.number}. {line.item_text}</Typography>
                <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap sx={{ mt: 0.75 }}>
                  <Chip size="small" variant="outlined" label={`${t('goods_receipt.declared')}: ${line.quantity}`}/>
                  {!canConfirm && line.received_quantity !== null && <Chip size="small" variant="outlined" color={line.received_quantity !== line.quantity ? 'warning' : 'default'} label={`${t('goods_receipt.received')}: ${line.received_quantity}`}/>}
                  {canSeeCosts && line.unit_cost && Number(line.unit_cost) > 0 && <Chip size="small" variant="outlined" label={`${line.unit_cost} ${receipt.currency}`}/>}
                  {!!line.warranty_months && <Chip size="small" variant="outlined" label={`${t('goods_receipt.warranty_months')}: ${line.warranty_months}`}/>}
                  {!canConfirm && line.line_type && <Chip size="small" label={t(`goods_receipt.line_types.${line.line_type}`)}/>}
                  {!canConfirm && (line.product || line.asset_type) && <Chip size="small" color="primary" variant="outlined" label={[line.asset_type, line.product].filter(Boolean).join(' · ')}/>}
                  {line.units_total > 0 && <Chip size="small" color={line.units_done === line.units_total ? 'success' : 'default'} label={t('goods_receipt.units_done', { done: line.units_done, total: line.units_total })}/>}
                </Stack>
                {canConfirm && (<Box sx={CLASSIFY_GRID}>
                    <TextField fullWidth size="small" label={t('goods_receipt.received')} value={d.received} onChange={(e) => update(line.id, { received: e.target.value })}
                      error={!/^\d+$/.test(d.received)} helperText={Number(d.received) !== line.quantity ? t('goods_receipt.difference') : undefined} color={Number(d.received) !== line.quantity ? 'warning' : undefined}/>
                    <TextField fullWidth select size="small" label={t('goods_receipt.line_type')} value={d.line_type} onChange={(e) => update(line.id, { line_type: e.target.value as LineType })}>
                      {LINE_TYPES.map((type) => <MenuItem key={type} value={type}>{t(`goods_receipt.line_types.${type}`)}</MenuItem>)}
                    </TextField>
                    {d.line_type === 'ASSET' && (<>
                        <Autocomplete fullWidth size="small" options={types} value={types.find((o) => o.id === d.asset_type_id) ?? null} onChange={(_, o) => update(line.id, { asset_type_id: o?.id ?? null, model_id: null })}
                          renderInput={(p) => <TextField {...p} label={t('goods_receipt.asset_type')}/>}/>
                        <Autocomplete fullWidth size="small" options={models.filter((m) => !d.asset_type_id || !m.typeId || m.typeId === d.asset_type_id)} value={models.find((o) => o.id === d.model_id) ?? null} onChange={(_, o) => update(line.id, { model_id: o?.id ?? null })}
                          noOptionsText={t('goods_receipt.no_model')} renderInput={(p) => <TextField {...p} label={t('goods_receipt.model')}/>}/>
                        <Button size="small" sx={{ alignSelf: 'center', whiteSpace: 'nowrap' }} disabled={!d.asset_type_id} onClick={() => setNewModel({ line: line.id, typeId: d.asset_type_id as number, brand: '', model: '' })}>{t('goods_receipt.new_model')}</Button>
                      </>)}
                    {d.line_type === 'CONSUMABLE' && (<Autocomplete fullWidth sx={{ gridColumn: { lg: 'span 2' } }} size="small" freeSolo options={consumables} value={consumables.find((o) => o.id === d.consumable_id)?.label ?? d.consumable_name}
                        getOptionLabel={(o) => (typeof o === 'string' ? o : o.label)} onChange={(_, o) => (typeof o === 'object' && o ? update(line.id, { consumable_id: o.id, consumable_name: '' }) : undefined)}
                        onInputChange={(_, text, why) => { if (why === 'input') update(line.id, { consumable_id: null, consumable_name: text }); }}
                        renderInput={(p) => <TextField {...p} label={t('goods_receipt.consumable')} helperText={t('goods_receipt.consumable_help')}/>}/>)}
                    {d.line_type === 'LICENSE' && (<>
                        <Autocomplete fullWidth size="small" options={software} value={software.find((o) => o.id === d.software_id) ?? null} onChange={(_, o) => update(line.id, { software_id: o?.id ?? null })} renderInput={(p) => <TextField {...p} label={t('goods_receipt.software')}/>}/>
                        <Autocomplete fullWidth size="small" options={licenseTypes} value={licenseTypes.find((o) => o.id === d.license_type_id) ?? null} onChange={(_, o) => update(line.id, { license_type_id: o?.id ?? null })} renderInput={(p) => <TextField {...p} label={t('goods_receipt.license_type')}/>}/>
                      </>)}
                  </Box>)}
                {canConfirm && d.line_type && (receipt.lines?.length ?? 0) > 1 && (<Button size="small" sx={{ mt: 0.5 }} onClick={() => copyClassification(line.id)}>{t('goods_receipt.copy_to_all')}</Button>)}
                {entered && canOperate && line.line_type === 'LICENSE' && !line.keys_completed && (<Stack direction={{ xs: 'column', md: 'row' }} spacing={1} sx={{ mt: 1 }} alignItems="flex-start">
                    <TextField fullWidth multiline minRows={2} size="small" label={t('goods_receipt.license_keys')} helperText={t('goods_receipt.license_keys_help')} value={keys[line.id] ?? ''} onChange={(e) => { setEditing(true); setKeys((all) => ({ ...all, [line.id]: e.target.value })); }}/>
                    <Button variant="outlined" onClick={() => { void saveKeys(line); }}>{t('goods_receipt.save_keys')}</Button>
                  </Stack>)}
              </Box>);
          })}
          {canConfirm && (<>
              {hasDifference && <TextField required fullWidth multiline minRows={2} label={t('goods_receipt.difference_reason')} helperText={t('goods_receipt.difference_help')} value={reason} onChange={(e) => { setEditing(true); setReason(e.target.value); }} inputProps={{ maxLength: 500 }}/>}
              <Box display="flex" justifyContent="flex-end"><Button variant="contained" disabled={!ready || saving} onClick={() => { void confirm(); }}>{hasDifference ? t('goods_receipt.send_for_approval') : t('goods_receipt.confirm')}</Button></Box>
            </>)}
        </Stack>
      </SectionCard>

      <Box sx={{ mt: 3 }}>
        <SectionCard title={t('goods_receipt.history')}>
          <Stack spacing={0.5}>
            {(receipt.events ?? []).map((event, i) => (<Typography key={i} variant="body2">{new Date(event.at).toLocaleString(i18n.language)} · <b>{t(`goods_receipt.events.${event.event}`, event.event)}</b>{event.user ? ` · ${event.user}` : ''}</Typography>))}
          </Stack>
        </SectionCard>
      </Box>

      <Dialog open={!!newModel} onClose={() => setNewModel(null)}>
        <DialogTitle>{t('goods_receipt.new_model')}</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1, minWidth: 320 }}>
            <TextField autoFocus required label={t('goods_receipt.brand')} value={newModel?.brand ?? ''} onChange={(e) => setNewModel((m) => (m ? { ...m, brand: e.target.value } : m))}/>
            <TextField required label={t('goods_receipt.model')} value={newModel?.model ?? ''} onChange={(e) => setNewModel((m) => (m ? { ...m, model: e.target.value } : m))} helperText={t('goods_receipt.new_model_help')}/>
          </Stack>
        </DialogContent>
        <DialogActions><Button onClick={() => setNewModel(null)}>{t('common.cancel')}</Button><Button variant="contained" disabled={!newModel?.brand.trim() || !newModel?.model.trim()} onClick={() => { void saveModel(); }}>{t('common.save')}</Button></DialogActions>
      </Dialog>
      <Dialog open={cancelReason !== null} onClose={() => setCancelReason(null)}>
        <DialogTitle>{t('goods_receipt.cancel')}</DialogTitle>
        <DialogContent><TextField autoFocus required fullWidth multiline minRows={2} sx={{ mt: 1, minWidth: 320 }} label={t('goods_receipt.cancel_reason')} value={cancelReason ?? ''} onChange={(e) => setCancelReason(e.target.value)}/></DialogContent>
        <DialogActions><Button onClick={() => setCancelReason(null)}>{t('common.close')}</Button><Button color="error" variant="contained" disabled={!cancelReason?.trim()} onClick={() => { void cancel(); }}>{t('goods_receipt.cancel')}</Button></DialogActions>
      </Dialog>
    </Box>);
};
