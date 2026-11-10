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

const STATUSES: GoodsReceiptStatus[] = ['REGISTERED', 'PENDING_APPROVAL', 'CONFIRMED', 'COMPLETED', 'CANCELLED'];

export const GoodsReceiptsPage = () => {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const { enqueueSnackbar } = useSnackbar();
  const { canPurchase, canOperate } = usePermissions();
  const [params] = useSearchParams();
  const [status, setStatus] = useState<GoodsReceiptStatus | ''>((params.get('estado') as GoodsReceiptStatus | null) ?? '');
  const [rows, setRows] = useState<GoodsReceipt[]>([]);
  const [loading, setLoading] = useState(true);

  const liveTick = useLiveTick();
  useEffect(() => {
    let active = true;
    goodsReceiptService.list(status || undefined)
      .then((data) => { if (active) setRows(data); })
      .catch((error: unknown) => enqueueSnackbar(getApiErrorMessage(error, t('common.error_loading')), { variant: 'error' }))
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [status, enqueueSnackbar, t, liveTick]);

  return (<Box>
      <PageHeader title={t('goods_receipt.title')} subtitle={t('goods_receipt.subtitle')} badge={rows.length}
        actions={<Stack direction="row" spacing={1}>
            {canOperate && <Button variant="outlined" component={RouterLink} to="/ingresos/por-completar" startIcon={<PlaylistAddCheckIcon />}>{t('goods_receipt.to_complete')}</Button>}
            {canPurchase && <Button variant="contained" component={RouterLink} to="/ingresos/nuevo" startIcon={<AddIcon />}>{t('goods_receipt.new')}</Button>}
          </Stack>}/>
      <TextField select size="small" label={t('goods_receipt.status')} value={status} sx={{ mb: 2, minWidth: 220 }}
        onChange={(e) => { setLoading(true); setStatus(e.target.value as GoodsReceiptStatus | ''); }}>
        <MenuItem value="">{t('goods_receipt.all')}</MenuItem>
        {STATUSES.map((s) => <MenuItem key={s} value={s}>{t(`goods_receipt.states.${s}`)}</MenuItem>)}
      </TextField>
      <DataTableShell
        columns={[
          { key: 'code', label: t('goods_receipt.code'), sortValue: (r) => r.code },
          { key: 'supplier', label: t('goods_receipt.supplier'), sortValue: (r) => r.supplier },
          { key: 'document', label: t('goods_receipt.document'), sortValue: (r) => r.document_number },
          { key: 'date', label: t('goods_receipt.received_date'), sortValue: (r) => r.received_date },
          { key: 'site', label: t('goods_receipt.site'), sortValue: (r) => r.site },
          { key: 'status', label: t('goods_receipt.status'), sortValue: (r) => t(`goods_receipt.states.${r.status}`) },
        ]}
        rows={rows}
        loading={loading}
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
