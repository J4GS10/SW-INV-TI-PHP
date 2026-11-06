import { useCallback, useEffect, useState } from 'react';
import { Box, Card, CardContent, Chip, CircularProgress, Stack, Typography, CardActionArea } from '@mui/material';
import { PageHeader, EmptyState } from '../../components/ui';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import PersonIcon from '@mui/icons-material/Person';
import ComputerIcon from '@mui/icons-material/Computer';
import BusinessIcon from '@mui/icons-material/Business';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { DepartamentoResumen, departmentService } from './departmentService';
import { DepartmentDetailDialog } from './DepartmentDetailDialog';
export const DepartmentsPage = () => {
    const { t } = useTranslation();
    const { enqueueSnackbar } = useSnackbar();
    const [data, setData] = useState<DepartamentoResumen[]>([]);
    const [loading, setLoading] = useState(true);
    const [detailId, setDetailId] = useState<number | null>(null);
    const load = useCallback(async () => {
        setLoading(true);
        try {
            setData(await departmentService.list());
        }
        catch {
            enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
        }
        finally {
            setLoading(false);
        }
    }, [enqueueSnackbar, t]);
    useEffect(() => { load(); }, [load]);
    return (<Box>
      <PageHeader
        title={t('departments.title')}
        subtitle={t('departments.subtitle')}
        badge={data.length}
      />

      {loading ? (<Box display="flex" justifyContent="center" py={6}><CircularProgress /></Box>) : data.length === 0 ? (<EmptyState title={t('common.no_records')} compact />) : (<Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 2 }}>
          {data.map((d) => (<Card key={d.DEP_Departamento} sx={{
                    flex: '1 1 320px',
                    maxWidth: 420,
                    '&:hover': { borderColor: 'primary.main' },
                }}>
              <CardActionArea onClick={() => setDetailId(d.DEP_Departamento)}>
                <CardContent>
                  <Stack direction="row" justifyContent="space-between" alignItems="flex-start" mb={2}>
                    <Box>
                      <Stack direction="row" spacing={1} alignItems="center">
                        <BusinessIcon color="primary"/>
                        <Typography variant="subtitle1" fontWeight={700}>
                          {d.DEP_Nombre}
                        </Typography>
                      </Stack>
                      {d.DEP_Codigo_Costos && (<Typography variant="caption" color="text.secondary">
                          {d.DEP_Codigo_Costos}
                        </Typography>)}
                    </Box>
                    <ChevronRightIcon fontSize="small" sx={{ color: 'text.secondary' }} aria-hidden/>
                  </Stack>

                  <Stack direction="row" spacing={1.5} mb={2}>
                    <Chip icon={<PersonIcon />} label={t('departments.persons', { count: d.personas_activas })} color="primary" size="small"/>
                    <Chip icon={<ComputerIcon />} label={t('departments.assets', { count: d.activos_asignados })} color="success" size="small"/>
                  </Stack>

                  {Object.keys(d.por_tipo).length > 0 && (<Box>
                      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>
                        {t('departments.by_type')}
                      </Typography>
                      <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap>
                        {Object.entries(d.por_tipo).map(([tipo, count]) => (<Chip key={tipo} label={`${tipo}: ${count}`} size="small" variant="outlined" sx={{ fontSize: '0.7rem' }}/>))}
                      </Stack>
                    </Box>)}
                </CardContent>
              </CardActionArea>
            </Card>))}
        </Box>)}

      <DepartmentDetailDialog open={detailId !== null} departamentoId={detailId} onClose={() => setDetailId(null)}/>
    </Box>);
};
