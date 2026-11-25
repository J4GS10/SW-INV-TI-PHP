import { Fragment } from 'react';
import { Box, IconButton, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Tooltip, Typography } from '@mui/material';
import AttachFileIcon from '@mui/icons-material/AttachFile';
import SubdirectoryArrowRightIcon from '@mui/icons-material/SubdirectoryArrowRight';
import { useTranslation } from 'react-i18next';
import { formatMoney } from '../../../utils/currency';
import { formatDate } from '../../../utils/dates';
import type { ValuedAsset } from '../accountingService';
import { ValuationStatusChip } from './ValuationParts';

interface Props {
  items: ValuedAsset[];
  /** Abre los archivos (foto, factura…) de un activo o componente; sin él no se muestra el botón. */
  onOpenFiles?: (asset: ValuedAsset) => void;
}

const NUM = { whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums' } as const;

/**
 * Activos con su valor, cada componente bajo el equipo donde está instalado. La fila de un equipo con
 * componentes muestra además el total del conjunto (equipo + lo instalado). Los importes van alineados
 * a la derecha con cifras de ancho fijo; en pantallas angostas la tabla se desplaza, nunca se corta.
 */
export const ValuedAssetsTable = ({ items, onOpenFiles }: Props) => {
  const { t, i18n } = useTranslation();
  const money = (value: string | null, currency: string) => (value === null ? '—' : formatMoney(value, currency, i18n.language));

  const rows = (asset: ValuedAsset, depth: number): React.ReactNode => {
    const descripcion = [asset.type, asset.product].filter(Boolean).join(' · ');
    return (
      <Fragment key={asset.asset_id}>
        <TableRow hover>
          <TableCell sx={{ pl: 2 + depth * 3, minWidth: 220 }}>
            <Box sx={{ display: 'flex', alignItems: 'flex-start', gap: 0.75 }}>
              {depth > 0 && <SubdirectoryArrowRightIcon fontSize="small" color="disabled" sx={{ mt: 0.25 }}/>}
              <Stack spacing={0.5} sx={{ minWidth: 0 }}>
                <Typography variant="body2" fontWeight={depth === 0 ? 700 : 600}>{asset.code}</Typography>
                <Tooltip title={descripcion} placement="bottom-start">
                  <Typography variant="caption" color="text.secondary" noWrap sx={{ display: 'block', maxWidth: 200 }}>{descripcion}</Typography>
                </Tooltip>
                <Box><ValuationStatusChip status={asset.status}/></Box>
              </Stack>
            </Box>
          </TableCell>
          <TableCell sx={{ minWidth: 150 }}>
            <Typography variant="body2">{asset.category?.name ?? '—'}</Typography>
            {asset.category?.annual_rate && <Typography variant="caption" color="text.secondary">{t('accounting.rate_per_year', { rate: asset.category.annual_rate })}</Typography>}
          </TableCell>
          <TableCell align="right" sx={NUM}>
            {money(asset.acquisition_cost, asset.currency)}
            <Typography variant="caption" color="text.secondary" component="div">{formatDate(asset.acquired_on, i18n.language)}</Typography>
          </TableCell>
          <TableCell align="right" sx={NUM}>
            {money(asset.accumulated_depreciation, asset.currency)}
            {asset.months_elapsed > 0 && <Typography variant="caption" color="text.secondary" component="div">{t('accounting.months', { count: asset.months_elapsed })}</Typography>}
          </TableCell>
          <TableCell align="right" sx={NUM}><Typography variant="body2" fontWeight={700}>{money(asset.book_value, asset.currency)}</Typography></TableCell>
          {onOpenFiles && (
            <TableCell align="right" padding="checkbox">
              <Tooltip title={t('accounting.files')}>
                <IconButton size="small" aria-label={t('accounting.files_of', { code: asset.code })} onClick={() => onOpenFiles(asset)}><AttachFileIcon fontSize="small"/></IconButton>
              </Tooltip>
            </TableCell>
          )}
        </TableRow>
        {asset.components.map((component) => rows(component, depth + 1))}
        {asset.components.length > 0 && asset.totals.map((total) => (
          <TableRow key={`${asset.asset_id}-${total.currency}`} sx={{ bgcolor: 'action.hover' }}>
            <TableCell sx={{ pl: 2 + depth * 3 }} colSpan={2}>
              <Typography variant="caption" fontWeight={700}>{t('accounting.equipment_total', { code: asset.code })}</Typography>
            </TableCell>
            <TableCell align="right" sx={NUM}><Typography variant="caption" fontWeight={700}>{formatMoney(total.acquisition_cost, total.currency, i18n.language)}</Typography></TableCell>
            <TableCell align="right" sx={NUM}><Typography variant="caption" fontWeight={700}>{formatMoney(total.accumulated_depreciation, total.currency, i18n.language)}</Typography></TableCell>
            <TableCell align="right" sx={NUM}><Typography variant="body2" fontWeight={800}>{formatMoney(total.book_value, total.currency, i18n.language)}</Typography></TableCell>
            {onOpenFiles && <TableCell/>}
          </TableRow>
        ))}
      </Fragment>
    );
  };

  return (
    <TableContainer sx={{ overflowX: 'auto', border: 1, borderColor: 'divider', borderRadius: 2 }}>
      <Table size="small">
        <TableHead>
          <TableRow sx={{ '& th': { whiteSpace: 'nowrap' } }}>
            <TableCell>{t('accounting.asset')}</TableCell>
            <TableCell>{t('accounting.category')}</TableCell>
            <TableCell align="right">{t('accounting.cost_and_date')}</TableCell>
            <TableCell align="right">{t('accounting.accumulated_short')}</TableCell>
            <TableCell align="right">{t('accounting.book_value')}</TableCell>
            {onOpenFiles && <TableCell padding="checkbox"/>}
          </TableRow>
        </TableHead>
        <TableBody>{items.map((asset) => rows(asset, 0))}</TableBody>
      </Table>
    </TableContainer>
  );
};
