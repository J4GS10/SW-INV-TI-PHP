import { useCallback, useState } from 'react';
import { Alert, Box, CircularProgress, Stack } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { EmptyState } from '../../components/ui';
import { AttachmentsDialog } from '../attachments/AttachmentsDialog';
import { accountingService } from './accountingService';
import { AsOfField, CurrencyTotalsSummary, PendingNotice } from './valuation/ValuationParts';
import { ValuedAssetsTable } from './valuation/ValuedAssetsTable';
import { useValuation } from './valuation/useValuation';

/**
 * Cuánto vale la estación de trabajo de una persona: todo lo que tiene a su cargo, con los componentes
 * instalados en cada equipo, y el total por moneda.
 */
export const WorkstationValueSection = ({ personaId }: { personaId: string }) => {
  const { t } = useTranslation();
  const load = useCallback((asOf: string) => accountingService.workstation(personaId, asOf), [personaId]);
  const { asOf, setAsOf, data, loading, error } = useValuation(load);
  const [files, setFiles] = useState<{ id: string; code: string } | null>(null);

  if (loading && !data) return <Box display="flex" justifyContent="center" py={3}><CircularProgress/></Box>;

  return (
    <Stack spacing={2.5} sx={{ pt: 1 }}>{/* la ficha desplaza su contenido: sin este margen se recorta la etiqueta de la fecha */}
      <AsOfField value={asOf} onChange={setAsOf}/>
      {error && <Alert severity="error">{error}</Alert>}
      {data && data.items.length === 0 && <EmptyState compact title={t('accounting.workstation_empty')}/>}
      {data && data.items.length > 0 && (
        <>
          <PendingNotice pending={data.pending}/>
          <CurrencyTotalsSummary totals={data.totals} title={t('accounting.workstation_total')}/>
          <ValuedAssetsTable items={data.items} onOpenFiles={(a) => setFiles({ id: a.asset_id, code: a.code })}/>
        </>
      )}
      <AttachmentsDialog asset={files} onClose={() => setFiles(null)}/>
    </Stack>
  );
};
