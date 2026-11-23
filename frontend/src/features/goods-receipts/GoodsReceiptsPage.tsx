import { useEffect, useState } from 'react';
import { Link as RouterLink, useNavigate, useSearchParams } from 'react-router-dom';
import { Box, Button, Chip, MenuItem, Stack, TableCell, TableRow, TextField } from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import PlaylistAddCheckIcon from '@mui/icons-material/PlaylistAddCheck';
import { useTranslation } from 'react-i18next';
import { formatDate } from '../../utils/dates';
import { rowLink } from '../../components/ui/rowLink';
import { useSnackbar } from 'notistack';
import { DataTableShell, PageHeader } from '../../components/ui';
import { getApiErrorMessage } from '../../api/errors';
import { usePermissions } from '../../hooks/usePermissions';
import { goodsReceiptService, statusColor } from './goodsReceiptService';
import type { GoodsReceipt, GoodsReceiptStatus } from './goodsReceiptService';
import { useLiveTick } from '../../hooks/useLiveRefresh';
import { useServerPaging } from '../../hooks/useServerPaging';
import { useDebouncedValue } from '../../hooks/useDebouncedValue';
import { SearchInput } from '../../components/SearchInput';

const STATUSES: GoodsReceiptStatus[] = ['REGISTERED', 'PENDING_APPROVAL', 'CONFIRMED', 'COMPLETED', 'CANCELLED'];

export const GoodsReceiptsPage = () => {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const { enqueueSnackbar } = useSnackbar();
  const { canPurchase, canOperate } = usePermissions();
  const [params] = useSearchParams();
  const [status, setStatus] = useState<GoodsReceiptStatus | ''>((params.get('estado') as GoodsReceiptStatus | null) ?? '');
  const [rows, setRows] = useState<GoodsReceipt[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [text, setText] = useState('');
  const search = useDebouncedValue(text.trim());
  const { pedido, tabla } = useServerPaging(`${search}|${status}`);

  const liveTick = useLiveTick();
  useEffect(() => {
    let active = true;
    goodsReceiptService.list({ ...pedido, status: status || undefined, q: search || undefined })
      .then((page) => { if (active) { setRows(page.items); setTotal(page.total); } })
      .catch((error: unknown) => enqueueSnackbar(getApiErrorMessage(error, t('common.error_loading')), { variant: 'error' }))
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [pedido, status, search, enqueueSnackbar, t, liveTick]);

  return (<Box>
      <PageHeader title={t('goods_receipt.title')} subtitle={t('goods_receipt.subtitle')} badge={total}
        actions={<Stack direction="row" spacing={1}>
            {canOperate && <Button variant="outlined" component={RouterLink} to="/ingresos/por-completar" startIcon={<PlaylistAddCheckIcon />}>{t('goods_receipt.to_complete')}</Button>}
            {canPurchase && <Button variant="contained" component={RouterLink} to="/ingresos/nuevo" startIcon={<AddIcon />}>{t('goods_receipt.new')}</Button>}
          </Stack>}/>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ mb: 2 }}>
        <Box sx={{ flex: 1, maxWidth: { sm: 420 } }}><SearchInput value={text} onChange={setText}/></Box>
        <TextField select size="small" label={t('goods_receipt.status')} value={status} sx={{ minWidth: 220 }}
          onChange={(e) => { setLoading(true); setStatus(e.target.value as GoodsReceiptStatus | ''); }}>
          <MenuItem value="">{t('goods_receipt.all')}</MenuItem>
          {STATUSES.map((s) => <MenuItem key={s} value={s}>{t(`goods_receipt.states.${s}`)}</MenuItem>)}
        </TextField>
      </Stack>
      <DataTableShell
        columns={[
          { key: 'code', label: t('goods_receipt.code'), serverSortable: true },
          { key: 'supplier', label: t('goods_receipt.supplier'), serverSortable: true },
          { key: 'document', label: t('goods_receipt.document'), serverSortable: true },
          { key: 'date', label: t('goods_receipt.received_date'), serverSortable: true },
          { key: 'site', label: t('goods_receipt.site'), serverSortable: true },
          { key: 'status', label: t('goods_receipt.status'), serverSortable: true },
        ]}
        rows={rows}
        loading={loading}
        total={total}
        {...tabla}
        emptyTitle={t('goods_receipt.empty_title')}
        emptyDescription={t('goods_receipt.empty_help')}
        renderRow={(r) => (<TableRow key={r.id} hover sx={{ cursor: 'pointer' }} {...rowLink(() => navigate(`/ingresos/${r.id}`))}>
            <TableCell>{r.code}</TableCell>
            <TableCell>{r.supplier}</TableCell>
            <TableCell>{r.document_number}</TableCell>
            <TableCell>{formatDate(r.received_date, i18n.language)}</TableCell>
            <TableCell>{r.site}</TableCell>
            <TableCell><Chip size="small" color={statusColor(r.status)} label={t(`goods_receipt.states.${r.status}`)}/></TableCell>
          </TableRow>)}
      />
    </Box>);
};
