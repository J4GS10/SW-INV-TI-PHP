import { useCallback, useEffect, useState } from 'react';
import { Alert, Box, Chip, CircularProgress, DialogContent, DialogTitle, IconButton, Stack, Typography, Accordion, AccordionDetails, AccordionSummary, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Paper } from '@mui/material';
import CloseIcon from '@mui/icons-material/Close';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import PersonIcon from '@mui/icons-material/Person';
import ComputerIcon from '@mui/icons-material/Computer';
import { useTranslation } from 'react-i18next';
import { EmptyState } from '../../components/ui';
import { SectionCard } from '../../components/ui';
import { DepartamentoDetalle, departmentService } from './departmentService';
import { Dialog } from '../../components/ui/Dialog';
import { useLiveRefresh } from '../../hooks/useLiveRefresh';
interface Props {
    open: boolean;
    departamentoId: number | null;
    onClose: () => void;
}
export const DepartmentDetailDialog = ({ open, departamentoId, onClose }: Props) => {
    const { t } = useTranslation();
    const [data, setData] = useState<DepartamentoDetalle | null>(null);
    const [loading, setLoading] = useState(false);
    const load = useCallback(async () => {
        if (!open || !departamentoId)
            return;
        setLoading(true);
        try {
            setData(await departmentService.detail(departamentoId));
        }
        catch {
            setData(null);
        }
        finally {
            setLoading(false);
        }
    }, [departamentoId, open]);
    useEffect(() => { load(); }, [load]);
    useLiveRefresh(load);
    return (<Dialog open={open} onClose={onClose} maxWidth="md" fullWidth>
      <DialogTitle sx={{ display: 'flex', justifyContent: 'space-between' }}>
        <Box>
          <Typography variant="h6">
            {data?.DEP_Nombre ?? t('departments.detail_title')}
          </Typography>
          {data?.DEP_Codigo_Costos && (<Typography variant="caption" color="text.secondary">
              {data.DEP_Codigo_Costos}
            </Typography>)}
        </Box>
        <IconButton onClick={onClose}><CloseIcon /></IconButton>
      </DialogTitle>
      <DialogContent dividers>
        {loading ? (<Box display="flex" justifyContent="center" py={4}><CircularProgress /></Box>) : !data ? (<Alert severity="error">{t('common.error_loading')}</Alert>) : (<Stack spacing={2}>
            <SectionCard title={t('departments.sections.summary')}>
            <Stack direction="row" spacing={2}>
              <Chip icon={<PersonIcon />} label={t('departments.persons', { count: data.totales.personas })} color="primary"/>
              <Chip icon={<ComputerIcon />} label={t('departments.assets', { count: data.totales.activos })} color="success"/>
            </Stack>

            {data.DEP_Descripcion && (<Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>
                {data.DEP_Descripcion}
              </Typography>)}
            </SectionCard>

            <SectionCard title={t('departments.persons_with_assets')} icon={<PersonIcon fontSize="small"/>}>

            {data.personas.length === 0 ? (<EmptyState compact title={t('departments.no_persons')}/>) : data.personas.map((p) => (<Accordion key={p.PER_Persona} sx={{
                    bgcolor: 'action.hover',
                    '&:before': { display: 'none' },
                    borderRadius: 1,
                    mb: 1,
                }}>
                <AccordionSummary expandIcon={<ExpandMoreIcon />}>
                  <Stack direction="row" spacing={2} alignItems="center" flex={1}>
                    <PersonIcon fontSize="small" color="primary"/>
                    <Box flex={1}>
                      <Typography fontWeight={500}>{p.nombre_completo}</Typography>
                      <Typography variant="caption" color="text.secondary">
                        {p.PER_Email_Corporativo}
                      </Typography>
                    </Box>
                    <Chip size="small" label={t('departments.assets', { count: p.activos_asignados.length })} color={p.activos_asignados.length > 0 ? 'success' : 'default'}/>
                  </Stack>
                </AccordionSummary>
                <AccordionDetails>
                  {p.activos_asignados.length === 0 ? (<Typography variant="body2" color="text.secondary" fontStyle="italic">
                      {t('departments.no_assets_person')}
                    </Typography>) : (<TableContainer component={Paper} sx={{ bgcolor: 'transparent', boxShadow: 'none' }}>
                      <Table size="small">
                        <TableHead>
                          <TableRow>
                            <TableCell>{t('assets.table.code')}</TableCell>
                            <TableCell>{t('assets.table.type')}</TableCell>
                            <TableCell>{t('assets.dialog.brand')}</TableCell>
                            <TableCell>{t('assets.dialog.model')}</TableCell>
                            <TableCell>{t('assets.table.host')}</TableCell>
                            <TableCell>{t('departments.location')}</TableCell>
                          </TableRow>
                        </TableHead>
                        <TableBody>
                          {p.activos_asignados.map((m) => (<TableRow key={m.MOV_Movimiento}>
                              <TableCell sx={{ fontWeight: 600 }}>{m.activo?.ACT_Codigo_Interno}</TableCell>
                              <TableCell>{m.activo?.tipo ?? '—'}</TableCell>
                              <TableCell>{m.activo?.marca ?? '—'}</TableCell>
                              <TableCell>{m.activo?.modelo ?? '—'}</TableCell>
                              <TableCell>{m.activo?.ACT_Hostname ?? '—'}</TableCell>
                              <TableCell>{m.area ?? '—'}</TableCell>
                            </TableRow>))}
                        </TableBody>
                      </Table>
                    </TableContainer>)}
                </AccordionDetails>
              </Accordion>))}
            </SectionCard>
          </Stack>)}
      </DialogContent>
    </Dialog>);
};
