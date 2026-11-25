import { useCallback, useState } from 'react';
import { Alert, Box, CircularProgress, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { formatMoney } from '../../utils/currency';
import { formatDate } from '../../utils/dates';
import { AttachmentsDialog } from '../attachments/AttachmentsDialog';
import { accountingService } from './accountingService';
import { AsOfField, CurrencyTotalsSummary, PendingNotice } from './valuation/ValuationParts';
import { ValuedAssetsTable } from './valuation/ValuedAssetsTable';
import { useValuation } from './valuation/useValuation';

/**
 * Valor contable de un equipo: lo que costó, su depreciación y valor en libros, cada componente instalado
 * con su propio valor, el total del conjunto y la tabla de depreciación año por año.
 */
export const AssetValuationSection = ({ activoId }: { activoId: string }) => {
  const { t, i18n } = useTranslation();
  const load = useCallback((asOf: string) => accountingService.asset(activoId, asOf), [activoId]);
  const { asOf, setAsOf, data, loading, error } = useValuation(load);
  const [files, setFiles] = useState<{ id: string; code: string } | null>(null);

  if (loading && !data) return <Box display="flex" justifyContent="center" py={3}><CircularProgress/></Box>;

  return (
    <Stack spacing={2.5}>
      <AsOfField value={asOf} onChange={setAsOf}/>
      {error && <Alert severity="error">{error}</Alert>}
      {data && (
        <>
          <PendingNotice pending={data.pending}/>
          <CurrencyTotalsSummary totals={data.totals} title={t('accounting.equipment_value')}/>
          {data.asset.status === 'DEPRECIATING' && data.asset.monthly_depreciation && (
            <Typography variant="body2" color="text.secondary">
              {t('accounting.schedule_hint', {
                monthly: formatMoney(data.asset.monthly_depreciation, data.asset.currency, i18n.language),
                months: data.asset.useful_life_months ?? 0,
                from: formatDate(data.asset.depreciation_starts_on, i18n.language),
                to: formatDate(data.asset.fully_depreciated_on, i18n.language),
              })}
            </Typography>
          )}
          <ValuedAssetsTable items={[data.asset]} onOpenFiles={(a) => setFiles({ id: a.asset_id, code: a.code })}/>
          {data.schedule.length > 0 && (
            <Box>
              <Typography variant="subtitle2" fontWeight={700} gutterBottom>{t('accounting.schedule_title')}</Typography>
              <TableContainer>
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell>{t('accounting.year')}</TableCell>
                      <TableCell align="right">{t('accounting.months_label')}</TableCell>
                      <TableCell align="right">{t('accounting.depreciation')}</TableCell>
                      <TableCell align="right">{t('accounting.accumulated')}</TableCell>
                      <TableCell align="right">{t('accounting.book_value')}</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {data.schedule.map((row) => (
                      <TableRow key={row.year}>
                        <TableCell>{row.year}</TableCell>
                        <TableCell align="right">{row.months}</TableCell>
                        <TableCell align="right">{formatMoney(row.depreciation, data.asset.currency, i18n.language)}</TableCell>
                        <TableCell align="right">{formatMoney(row.accumulated, data.asset.currency, i18n.language)}</TableCell>
                        <TableCell align="right">{formatMoney(row.book_value, data.asset.currency, i18n.language)}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableContainer>
            </Box>
          )}
        </>
      )}
      <AttachmentsDialog asset={files} onClose={() => setFiles(null)}/>
    </Stack>
  );
};
