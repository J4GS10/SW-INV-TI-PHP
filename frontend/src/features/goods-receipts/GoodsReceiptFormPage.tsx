import { useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Autocomplete, Box, Button, IconButton, MenuItem, Stack, TextField, Tooltip, Typography } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline';
import UploadFileIcon from '@mui/icons-material/UploadFile';
import { useTranslation } from 'react-i18next';
import { useUnsavedChanges } from '../../hooks/useUnsavedChanges';
import { useSnackbar } from 'notistack';
import { PageHeader, SectionCard, SedeField } from '../../components/ui';
import { getApiErrorCode, getApiErrorMessage } from '../../api/errors';
import { useSedes } from '../../hooks/useSedes';
import { procurementService } from '../procurement/procurementService';
import type { Proveedor } from '../../types/procurement';
import { goodsReceiptService, receiptRules } from './goodsReceiptService';
import { DateField } from '../../components/ui/DateField';

interface LineDraft {
  key: number;
  item_text: string;
  quantity: string;
  unit_cost: string;
  warranty_months: string;
}

const CURRENCIES = ['GTQ', 'USD', 'CHF'];
let nextKey = 1;
const emptyLine = (): LineDraft => ({ key: nextKey++, item_text: '', quantity: '1', unit_cost: '', warranty_months: '' });

/** Compras solo dice qué llegó y cuánto: TI lo clasifica después. Fecha, sede y último proveedor vienen precargados. */
export const GoodsReceiptFormPage = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { enqueueSnackbar } = useSnackbar();
  const { sedes, loading: loadingSedes } = useSedes();
  const [suppliers, setSuppliers] = useState<Proveedor[]>([]);
  const [supplierId, setSupplierId] = useState<number | null>(null);
  const [supplierText, setSupplierText] = useState('');
  const [documentNumber, setDocumentNumber] = useState('');
  const [documentTaken, setDocumentTaken] = useState(false);
  const [receivedDate, setReceivedDate] = useState(receiptRules.today());
  const [site, setSite] = useState<number | ''>('');
  const [currency, setCurrency] = useState('GTQ');
  const [notes, setNotes] = useState('');
  const [lines, setLines] = useState<LineDraft[]>([emptyLine()]);
  const [file, setFile] = useState<File | null>(null);
  const [saving, setSaving] = useState(false);
  const dirty = !saving && (!!documentNumber.trim() || !!notes.trim() || !!file || lines.some((l) => !!l.item_text.trim()));
  useUnsavedChanges(dirty, t('common.unsaved_leave'));
  const idempotencyKey = useRef(crypto.randomUUID());

  useEffect(() => {
    procurementService.listProveedores(true).then(setSuppliers).catch(() => setSuppliers([]));
    goodsReceiptService.formContext().then((context) => {
      setSupplierId(context.supplier_id);
      setCurrency(context.currency);
      setReceivedDate(context.received_date);
      if (context.site_id) setSite(context.site_id);
    }).catch(() => undefined);
  }, []);

  const createSupplier = async () => {
    try {
      const created = await procurementService.createProveedor({ PRV_Nombre: supplierText.trim() });
      setSuppliers((prev) => [...prev, created].sort((a, b) => a.PRV_Nombre.localeCompare(b.PRV_Nombre)));
      setSupplierId(created.PRV_Proveedor);
      enqueueSnackbar(t('goods_receipt.supplier_created'), { variant: 'success' });
    } catch (err) {
      enqueueSnackbar(getApiErrorMessage(err, t('common.error_save')), { variant: 'error' });
    }
  };

  const lineError = (line: LineDraft) => ({
    item_text: line.item_text.trim() ? null : 'item_required',
    quantity: receiptRules.quantityError(line.quantity),
    unit_cost: receiptRules.costError(line.unit_cost),
    warranty_months: receiptRules.warrantyError(line.warranty_months),
  });
  const dateError = receiptRules.dateError(receivedDate);
  const canSave = useMemo(() => !!supplierId && documentNumber.trim() !== '' && !documentTaken && !dateError && lines.length > 0
    && lines.every((line) => Object.values(lineError(line)).every((e) => e === null)), [supplierId, documentNumber, documentTaken, dateError, lines]);

  const checkDocument = async () => {
    if (!supplierId || !documentNumber.trim()) return;
    setDocumentTaken(await goodsReceiptService.documentExists(supplierId, documentNumber.trim()).catch(() => false));
  };
  const change = (key: number, field: keyof LineDraft, value: string) =>
    setLines((all) => all.map((line) => (line.key === key ? { ...line, [field]: value } : line)));
  const copy = (line: LineDraft) => setLines((all) => [...all, { ...line, key: nextKey++ }]);

  const save = async () => {
    setSaving(true);
    try {
      const saved = await goodsReceiptService.register({
        supplier_id: supplierId as number, document_number: documentNumber.trim(), received_date: receivedDate, site_id: site === '' ? null : site, currency, notes: notes.trim() || undefined,
        lines: lines.map((line) => ({ item_text: line.item_text.trim(), quantity: Number(line.quantity), unit_cost: line.unit_cost, warranty_months: line.warranty_months === '' ? null : Number(line.warranty_months) })),
      }, idempotencyKey.current);
      if (file) {
        try {
          await goodsReceiptService.attach(saved.id, file);
        } catch (error: unknown) {
          enqueueSnackbar(getApiErrorMessage(error, t('goods_receipt.attach_failed')), { variant: 'warning' });
        }
      }
      enqueueSnackbar(t('goods_receipt.registered', { code: saved.code }), { variant: 'success' });
      navigate(`/ingresos/${saved.id}`);
    } catch (error: unknown) {
      if (getApiErrorCode(error) === 'GOODS_RECEIPT_DOCUMENT_ALREADY_REGISTERED') setDocumentTaken(true);
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_save')), { variant: 'error' });
    } finally {
      setSaving(false);
    }
  };

  const message = (code: string | null) => (code ? t(`goods_receipt.errors.${code}`) : undefined);

  return (<Box sx={{ maxWidth: 1100, mx: 'auto' }}>
      <PageHeader title={t('goods_receipt.new')} subtitle={t('goods_receipt.new_help')}/>
      <Stack spacing={3}>
        <SectionCard title={t('goods_receipt.document_section')}>
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
            <Autocomplete sx={{ flex: 2 }} options={suppliers} value={suppliers.find((s) => s.PRV_Proveedor === supplierId) ?? null}
              getOptionLabel={(s) => s.PRV_Nombre} isOptionEqualToValue={(a, b) => a.PRV_Proveedor === b.PRV_Proveedor}
              onChange={(_, s) => { setSupplierId(s?.PRV_Proveedor ?? null); setDocumentTaken(false); }}
              onInputChange={(_, text) => setSupplierText(text)}
              noOptionsText={supplierText.trim().length >= 2
                ? <Button size="small" onMouseDown={(e) => e.preventDefault()} onClick={() => { void createSupplier(); }}>{t('goods_receipt.create_supplier', { name: supplierText.trim() })}</Button>
                : t('goods_receipt.no_supplier')}
              renderInput={(params) => <TextField {...params} required label={t('goods_receipt.supplier')} error={!supplierId && documentNumber !== ''} helperText={!supplierId && documentNumber !== '' ? t('goods_receipt.errors.supplier_required') : undefined}/>}/>
            <TextField sx={{ flex: 1 }} required label={t('goods_receipt.document')} value={documentNumber} placeholder="F-001234"
              onChange={(e) => { setDocumentNumber(e.target.value); setDocumentTaken(false); }} onBlur={() => { void checkDocument(); }}
              error={documentTaken} helperText={documentTaken ? t('goods_receipt.errors.document_taken') : t('goods_receipt.document_help')} inputProps={{ maxLength: 60 }}/>
            <DateField sx={{ flex: 1 }} required label={t('goods_receipt.received_date')} value={receivedDate} onChange={(e) => setReceivedDate(e.target.value)}
              error={!!dateError} helperText={message(dateError)} slotProps={{ inputLabel: { shrink: true }, htmlInput: { max: receiptRules.today() } }}/>
          </Stack>
          <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ mt: 2 }}>
            <Box sx={{ flex: 1 }}><SedeField value={site} onChange={setSite} sedes={sedes ?? []} loading={loadingSedes}/></Box>
            <TextField sx={{ flex: 1 }} select label={t('goods_receipt.currency')} value={currency} onChange={(e) => setCurrency(e.target.value)}>
              {CURRENCIES.map((c) => <MenuItem key={c} value={c}>{c}</MenuItem>)}
            </TextField>
            <Button sx={{ flex: 1 }} component="label" variant="outlined" startIcon={<UploadFileIcon />}>
              {file ? file.name : t('goods_receipt.attach')}
              <input hidden type="file" accept="image/*,application/pdf" onChange={(e) => setFile(e.target.files?.[0] ?? null)}/>
            </Button>
          </Stack>
        </SectionCard>

        <SectionCard title={t('goods_receipt.lines_section')} subtitle={t('goods_receipt.lines_help')}>
          <Stack spacing={1.5}>
            {lines.map((line, index) => {
              const errors = lineError(line);
              const touched = line.item_text !== '' || line.unit_cost !== '';
              return (<Stack key={line.key} direction={{ xs: 'column', md: 'row' }} spacing={1} alignItems="flex-start">
                  <TextField sx={{ flex: 3 }} size="small" required autoFocus={index === lines.length - 1 && index > 0} label={t('goods_receipt.item')} placeholder={t('goods_receipt.item_placeholder')} value={line.item_text} onChange={(e) => change(line.key, 'item_text', e.target.value)} inputProps={{ maxLength: 255 }}
                    error={touched && !!errors.item_text} helperText={touched ? message(errors.item_text) : undefined}/>
                  <TextField sx={{ flex: 1 }} size="small" required label={t('goods_receipt.quantity')} value={line.quantity} onChange={(e) => change(line.key, 'quantity', e.target.value)} inputProps={{ inputMode: 'numeric' }}
                    error={!!errors.quantity} helperText={message(errors.quantity)}/>
                  <TextField sx={{ flex: 1 }} size="small" required label={t('goods_receipt.unit_cost')} placeholder="1250.00" value={line.unit_cost} onChange={(e) => change(line.key, 'unit_cost', e.target.value)} inputProps={{ inputMode: 'decimal' }}
                    error={touched && !!errors.unit_cost} helperText={touched ? message(errors.unit_cost) : undefined}/>
                  <TextField sx={{ flex: 1 }} size="small" label={t('goods_receipt.warranty_months')} value={line.warranty_months} onChange={(e) => change(line.key, 'warranty_months', e.target.value)} inputProps={{ inputMode: 'numeric' }}
                    error={!!errors.warranty_months} helperText={message(errors.warranty_months)}/>
                  <Tooltip title={t('goods_receipt.copy_line')}><IconButton aria-label={t('goods_receipt.copy_line')} onClick={() => copy(line)}><ContentCopyIcon fontSize="small"/></IconButton></Tooltip>
                  <Tooltip title={t('goods_receipt.remove_line')}><span><IconButton aria-label={t('goods_receipt.remove_line')} disabled={lines.length === 1} onClick={() => setLines((all) => all.filter((l) => l.key !== line.key))}><DeleteOutlineIcon fontSize="small"/></IconButton></span></Tooltip>
                </Stack>);
            })}
            <Box><Button startIcon={<AddIcon />} onClick={() => setLines((all) => [...all, emptyLine()])}>{t('goods_receipt.add_line')}</Button></Box>
          </Stack>
          <TextField fullWidth multiline minRows={2} sx={{ mt: 2 }} label={t('goods_receipt.notes')} value={notes} onChange={(e) => setNotes(e.target.value)} inputProps={{ maxLength: 500 }}/>
        </SectionCard>

        <Stack direction="row" justifyContent="flex-end" alignItems="center" spacing={2}>
          {!canSave && <Typography variant="caption" color="text.secondary">{t('goods_receipt.complete_required')}</Typography>}
          <Button variant="text" onClick={() => navigate('/ingresos')}>{t('common.cancel')}</Button>
          <Button variant="contained" disabled={!canSave || saving} onClick={() => { void save(); }}>{t('goods_receipt.save')}</Button>
        </Stack>
      </Stack>
    </Box>);
};
