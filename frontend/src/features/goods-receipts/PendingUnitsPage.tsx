import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Box, Button, Checkbox, Chip, Collapse, MenuItem, Stack, TextField, Typography } from '@mui/material';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import QrCode2Icon from '@mui/icons-material/QrCode2';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { EmptyState, PageHeader, SectionCard } from '../../components/ui';
import { getApiErrorCode, getApiErrorMessage } from '../../api/errors';
import { guardarArchivo } from '../../api/productividadService';
import { useUnsavedChanges } from '../../hooks/useUnsavedChanges';
import { assetService } from '../assets/assetService';
import { goodsReceiptService } from './goodsReceiptService';
import type { PendingUnit, UnitSpecification } from './goodsReceiptService';
import { groupPendingUnits, missingFields, purchasingTextDiffers, specProblem, type PendingGroup } from './pendingUnits';

interface UnitDraft {
  serial: string;
  hostname: string;
  specs: Record<number, string>;
  /** Data this unit does not share with its group. */
  own: number[];
  serialTaken: boolean;
  hostTaken: boolean;
}

const FIELD_GRID = { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(min(100%, 240px), 1fr))', gap: 1.5, alignItems: 'start' };

/**
 * Bandeja «Por completar»: lo que comparten las unidades de un mismo producto se captura una vez y
 * cada unidad solo pide su serie. Con el lector, Enter guarda si está completa y pasa a la siguiente.
 */
export const PendingUnitsPage = () => {
  const { t, i18n } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const [units, setUnits] = useState<PendingUnit[]>([]);
  const [drafts, setDrafts] = useState<Record<string, UnitDraft>>({});
  const [loading, setLoading] = useState(true);
  const [done, setDone] = useState<{ id: string; code: string }[]>([]);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [optionalOpen, setOptionalOpen] = useState<Set<string>>(new Set());
  const [unitOpen, setUnitOpen] = useState<Set<string>>(new Set());
  const [saving, setSaving] = useState(false);
  const inputs = useRef<Record<string, HTMLInputElement | null>>({});
  const savingUnits = useRef<Set<string>>(new Set());

  const groups = useMemo(() => groupPendingUnits(units), [units]);
  const ordered = useMemo(() => groups.flatMap((g) => g.units), [groups]);

  const show = useCallback((data: PendingUnit[]) => {
    setUnits(data);
    setDrafts((prev) => Object.fromEntries(data.map((u) => [u.asset_id, prev[u.asset_id] ?? {
      serial: '', hostname: '', serialTaken: false, hostTaken: false, own: [],
      specs: Object.fromEntries(u.specifications.map((s) => [s.type_id, s.value ?? ''])),
    }])));
  }, []);

  useEffect(() => {
    goodsReceiptService.pendingUnits().then(show)
      .catch((error: unknown) => enqueueSnackbar(getApiErrorMessage(error, t('common.error_loading')), { variant: 'error' }))
      .finally(() => setLoading(false));
  }, [show, enqueueSnackbar, t]);

  const dirty = Object.values(drafts).some((d) => d.serial.trim() || d.hostname.trim());
  useUnsavedChanges(dirty, t('common.unsaved_leave'));

  const patch = (id: string, value: Partial<UnitDraft>) => setDrafts((all) => ({ ...all, [id]: { ...all[id], ...value } }));
  const setCommon = (group: PendingGroup, typeId: number, value: string) => setDrafts((all) => {
    const next = { ...all };
    for (const { asset_id: id } of group.units) {
      if (next[id] && !next[id].own.includes(typeId)) next[id] = { ...next[id], specs: { ...next[id].specs, [typeId]: value } };
    }
    return next;
  });
  const setOwn = (id: string, typeId: number, value: string) => setDrafts((all) => ({
    ...all,
    [id]: { ...all[id], specs: { ...all[id].specs, [typeId]: value }, own: all[id].own.includes(typeId) ? all[id].own : [...all[id].own, typeId] },
  }));
  const commonValue = (group: PendingGroup, typeId: number): string => {
    const shared = group.units.map((u) => drafts[u.asset_id]).find((d) => d && !d.own.includes(typeId));
    return shared?.specs[typeId] ?? '';
  };
  const toggle = (setter: typeof setOptionalOpen, key: string) => setter((all) => {
    const next = new Set(all);
    if (next.has(key)) next.delete(key); else next.add(key);
    return next;
  });

  const checkSerial = async (id: string) => {
    const serial = drafts[id]?.serial.trim();
    if (serial) patch(id, { serialTaken: await goodsReceiptService.serialExists(serial, id).catch(() => false) });
  };
  const checkHost = async (id: string) => {
    const host = drafts[id]?.hostname.trim();
    if (host) patch(id, { hostTaken: await goodsReceiptService.hostnameExists(host, id).catch(() => false) });
  };
  const nextAfter = (id: string) => {
    const index = ordered.findIndex((u) => u.asset_id === id);
    inputs.current[ordered[index + 1]?.asset_id ?? '']?.focus();
  };
  const ready = (unit: PendingUnit, draft?: UnitDraft) => !!draft && !draft.serialTaken && !draft.hostTaken
    && missingFields(unit, draft.serial, draft.specs, '').length === 0
    && unit.specifications.every((s) => !specProblem(s, draft.specs[s.type_id] ?? ''));

  const persist = async (unit: PendingUnit): Promise<boolean> => {
    const draft = drafts[unit.asset_id];
    if (savingUnits.current.has(unit.asset_id)) return false;
    savingUnits.current.add(unit.asset_id);
    try {
      const result = await goodsReceiptService.completeUnit(unit.asset_id, { serial: draft.serial.trim(), hostname: draft.hostname.trim() || undefined,
        specifications: Object.entries(draft.specs).filter(([, v]) => v.trim()).map(([type, value]) => ({ type_id: Number(type), value })) });
      enqueueSnackbar(t('goods_receipt.unit_saved', { code: result.code }), { variant: 'success' });
      setDone((all) => [...all, { id: unit.asset_id, code: result.code }]);
      setSelected((all) => new Set(all).add(unit.asset_id));
      return true;
    } catch (error: unknown) {
      const code = getApiErrorCode(error);
      if (code === 'SERIAL_NUMBER_ALREADY_EXISTS') patch(unit.asset_id, { serialTaken: true });
      if (code === 'HOSTNAME_ALREADY_EXISTS') patch(unit.asset_id, { hostTaken: true });
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_save')), { variant: 'error' });
      return false;
    } finally {
      savingUnits.current.delete(unit.asset_id);
    }
  };
  const refresh = async () => show(await goodsReceiptService.pendingUnits());
  const save = async (unit: PendingUnit) => {
    if (await persist(unit)) {
      nextAfter(unit.asset_id);
      await refresh();
    }
  };
  const saveReady = async (group: PendingGroup) => {
    setSaving(true);
    try {
      for (const unit of group.units.filter((u) => ready(u, drafts[u.asset_id]))) {
        await persist(unit);
      }
      await refresh();
    } finally {
      setSaving(false);
    }
  };
  const printLabels = async () => {
    try {
      guardarArchivo(await assetService.downloadLabels({ activos_ids: [...selected] }, i18n.language.slice(0, 2)), `etiquetas-${selected.size}.pdf`);
    } catch (error: unknown) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_generic')), { variant: 'error' });
    }
  };

  const specField = (spec: UnitSpecification, value: string, onChange: (next: string) => void) => {
    const problem = specProblem(spec, value);
    const options = spec.options?.length ? spec.options : spec.data_type === 'BOOLEAN' ? [t('goods_receipt.yes'), t('goods_receipt.no')] : null;
    return (<TextField key={spec.type_id} size="small" required={spec.required} select={!!options} label={spec.unit ? `${spec.name} (${spec.unit})` : spec.name} value={value}
        onChange={(e) => onChange(e.target.value)} error={!!problem}
        helperText={problem ? t(`goods_receipt.errors.${problem}`, { min: spec.min, max: spec.max }) : spec.data_type === 'NUMBER' ? t('goods_receipt.spec_number_help') : undefined}>
        {options?.map((o) => <MenuItem key={o} value={o}>{o}</MenuItem>)}
      </TextField>);
  };

  return (<Box sx={{ maxWidth: 1100, mx: 'auto' }}>
      <PageHeader title={t('goods_receipt.to_complete')} subtitle={t('goods_receipt.to_complete_help')} badge={units.length}
        actions={selected.size > 0 ? <Button variant="contained" startIcon={<QrCode2Icon />} onClick={() => { void printLabels(); }}>{t('goods_receipt.print_labels', { count: selected.size })}</Button> : undefined}/>
      {done.length > 0 && (<SectionCard title={t('goods_receipt.completed_now')}>
          <Stack direction="row" flexWrap="wrap" gap={1}>
            {done.map((d) => (<Chip key={d.id} variant={selected.has(d.id) ? 'filled' : 'outlined'} label={d.code} icon={<Checkbox size="small" checked={selected.has(d.id)} sx={{ p: 0 }}/>}
                onClick={() => setSelected((all) => { const next = new Set(all); if (next.has(d.id)) next.delete(d.id); else next.add(d.id); return next; })}/>))}
          </Stack>
        </SectionCard>)}
      {!loading && units.length === 0 && <EmptyState title={t('goods_receipt.nothing_to_complete')} description={t('goods_receipt.nothing_to_complete_help')}/>}
      <Stack spacing={2} sx={{ mt: 2 }}>
        {groups.map((group) => {
          const first = group.units[0];
          if (!group.units.every((u) => drafts[u.asset_id])) return null;
          const required = first.specifications.filter((s) => s.required);
          const optional = first.specifications.filter((s) => !s.required);
          const several = group.units.length > 1;
          const readyCount = group.units.filter((u) => ready(u, drafts[u.asset_id])).length;
          const commonMissing = several ? required.filter((s) => !commonValue(group, s.type_id).trim()).map((s) => s.name) : [];
          const subtitle = [purchasingTextDiffers(first) ? t('goods_receipt.per_purchasing', { text: first.item_text }) : '', first.receipt_code, first.site].filter(Boolean).join(' · ');
          return (<SectionCard key={group.key} title={several ? t('goods_receipt.group_title', { product: first.product, count: group.units.length }) : `${first.code} · ${first.product}`} subtitle={subtitle}>
              {first.specifications.length > 0 && (<Box sx={{ mb: 2 }}>
                  {several && <Typography variant="subtitle2">{t('goods_receipt.common_data')}</Typography>}
                  {several && <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>{t('goods_receipt.common_data_help')}</Typography>}
                  <Box sx={FIELD_GRID}>
                    {required.map((s) => specField(s, commonValue(group, s.type_id), (v) => setCommon(group, s.type_id, v)))}
                  </Box>
                  {commonMissing.length > 0 && <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>{t('goods_receipt.missing', { fields: commonMissing.join(', ') })}</Typography>}
                  {optional.length > 0 && (<>
                      <Button size="small" sx={{ mt: 1 }} aria-expanded={optionalOpen.has(group.key)} onClick={() => toggle(setOptionalOpen, group.key)}
                        endIcon={<ExpandMoreIcon sx={{ transform: optionalOpen.has(group.key) ? 'rotate(180deg)' : 'none' }}/>}>
                        {t('goods_receipt.optional_data', { count: optional.length })}
                      </Button>
                      <Collapse in={optionalOpen.has(group.key)} unmountOnExit>
                        <Box sx={{ ...FIELD_GRID, mt: 1 }}>
                          {optional.map((s) => specField(s, commonValue(group, s.type_id), (v) => setCommon(group, s.type_id, v)))}
                        </Box>
                      </Collapse>
                    </>)}
                </Box>)}
              <Stack spacing={1.5}>
                {group.units.map((unit) => {
                  const d = drafts[unit.asset_id];
                  const missing = missingFields(unit, d.serial, d.specs, t('goods_receipt.serial')).filter((name) => !several || !commonMissing.includes(name));
                  const open = unitOpen.has(unit.asset_id);
                  return (<Fragment key={unit.asset_id}>
                      <Box sx={{ ...FIELD_GRID, gridTemplateColumns: { xs: '1fr', md: `${several ? '110px ' : ''}1fr 1fr auto` }, borderTop: several ? 1 : 0, borderColor: 'divider', pt: several ? 1.5 : 0 }}>
                        {several && <Typography variant="body2" sx={{ fontWeight: 700, pt: { md: 1 } }}>{unit.code}</Typography>}
                        <TextField required size="small" label={t('goods_receipt.serial')} value={d.serial} inputRef={(el: HTMLInputElement | null) => { inputs.current[unit.asset_id] = el; }}
                          onChange={(e) => patch(unit.asset_id, { serial: e.target.value, serialTaken: false })} onBlur={() => { void checkSerial(unit.asset_id); }}
                          onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); if (ready(unit, d)) void save(unit); else nextAfter(unit.asset_id); } }}
                          error={d.serialTaken} helperText={d.serialTaken ? t('goods_receipt.errors.serial_taken') : t('goods_receipt.serial_help')} inputProps={{ maxLength: 100 }}/>
                        <TextField size="small" label={t('goods_receipt.hostname')} value={d.hostname} onChange={(e) => patch(unit.asset_id, { hostname: e.target.value, hostTaken: false })}
                          onBlur={() => { void checkHost(unit.asset_id); }} error={d.hostTaken} helperText={d.hostTaken ? t('goods_receipt.errors.hostname_taken') : undefined} inputProps={{ maxLength: 100 }}/>
                        <Button variant={several ? 'outlined' : 'contained'} sx={{ justifySelf: 'start' }} disabled={saving || !ready(unit, d)} onClick={() => { void save(unit); }}>{t('common.save')}</Button>
                      </Box>
                      <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
                        {missing.length > 0 && <Typography variant="caption" color="text.secondary">{t('goods_receipt.missing', { fields: missing.join(', ') })}</Typography>}
                        {several && first.specifications.length > 0 && <Button size="small" aria-expanded={open} onClick={() => toggle(setUnitOpen, unit.asset_id)}>{t('goods_receipt.unit_details')}</Button>}
                      </Stack>
                      {several && (<Collapse in={open} unmountOnExit>
                          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>{t('goods_receipt.unit_details_help')}</Typography>
                          <Box sx={FIELD_GRID}>
                            {unit.specifications.map((s) => specField(s, d.specs[s.type_id] ?? '', (v) => setOwn(unit.asset_id, s.type_id, v)))}
                          </Box>
                        </Collapse>)}
                    </Fragment>);
                })}
              </Stack>
              {several && (<Box sx={{ mt: 2 }}>
                  <Button variant="contained" disabled={saving || readyCount === 0} onClick={() => { void saveReady(group); }}>{t('goods_receipt.save_ready', { count: readyCount })}</Button>
                </Box>)}
            </SectionCard>);
        })}
      </Stack>
      {units.length > 0 && <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 2 }}>{t('goods_receipt.scanner_hint')}</Typography>}
    </Box>);
};
