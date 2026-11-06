import { useState } from 'react';
import { Box, Card, CardActionArea, CardContent, Chip, Grid, Typography } from '@mui/material';
import type { SvgIconComponent } from '@mui/icons-material';
import AddBoxIcon from '@mui/icons-material/AddBox';
import ApprovalIcon from '@mui/icons-material/Approval';
import AssignmentIndIcon from '@mui/icons-material/AssignmentInd';
import BadgeIcon from '@mui/icons-material/Badge';
import BuildIcon from '@mui/icons-material/Build';
import CalendarMonthIcon from '@mui/icons-material/CalendarMonth';
import ComputerIcon from '@mui/icons-material/Computer';
import FactCheckIcon from '@mui/icons-material/FactCheck';
import Inventory2Icon from '@mui/icons-material/Inventory2';
import PolicyIcon from '@mui/icons-material/Policy';
import EditNoteIcon from '@mui/icons-material/EditNote';
import ReceiptLongIcon from '@mui/icons-material/ReceiptLong';
import TimelineIcon from '@mui/icons-material/Timeline';
import { Link as RouterLink, Navigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { PageHeader } from '../../components/ui';
import { useAuth } from '../../context/auth-context';
import type { UserRole } from '../../types/auth';
import type { ItemAtencion } from '../../types/kardex';
import { AttentionInbox } from './AttentionInbox';

interface Task {
  key: string;
  to: string;
  icon: SvgIconComponent;
  /** Pending item the server counts for this task. */
  pending?: string;
}

const NEW_RECEIPT: Task = { key: 'new_receipt', to: '/ingresos/nuevo', icon: AddBoxIcon };
const RECEIPTS: Task = { key: 'receipts', to: '/ingresos', icon: ReceiptLongIcon };
const CONFIRM: Task = { key: 'confirm', to: '/ingresos?estado=REGISTERED', icon: FactCheckIcon, pending: 'INGRESO_POR_CONFIRMAR' };
const COMPLETE: Task = { key: 'complete', to: '/ingresos/por-completar', icon: EditNoteIcon, pending: 'ACTIVO_POR_COMPLETAR' };
const ASSIGN: Task = { key: 'assign', to: '/operaciones/movimientos', icon: AssignmentIndIcon };
const REPAIR: Task = { key: 'repair', to: '/mantenimiento', icon: BuildIcon };
const FIND: Task = { key: 'find', to: '/activos', icon: ComputerIcon };
const STOCK: Task = { key: 'stock', to: '/compras/stock', icon: Inventory2Icon };
const MONTH_END: Task = { key: 'month_end', to: '/contabilidad/cierre', icon: CalendarMonthIcon };
const APPROVE: Task = { key: 'approve', to: '/aprobaciones', icon: ApprovalIcon, pending: 'APROBACION_PENDIENTE' };
const PEOPLE: Task = { key: 'people', to: '/empleados', icon: BadgeIcon };
const AUDIT: Task = { key: 'audit', to: '/auditoria', icon: PolicyIcon };
const TRACE: Task = { key: 'trace', to: '/trazabilidad', icon: TimelineIcon };

const TASKS_BY_ROLE: Partial<Record<UserRole, Task[]>> = {
  COMPRAS: [NEW_RECEIPT, RECEIPTS, STOCK, MONTH_END],
  TECNICO: [CONFIRM, COMPLETE, ASSIGN, REPAIR, FIND],
  ADMIN_TI: [APPROVE, CONFIRM, ASSIGN, FIND, PEOPLE, STOCK],
  AUDITOR: [AUDIT, TRACE, STOCK, FIND],
  CONSULTA: [FIND, RECEIPTS],
};

/** Roles that receive no pending work from the server. */
const WITHOUT_PENDING: UserRole[] = ['CONSULTA'];

/** First screen after signing in: what is pending today and the usual tasks of the role, one click away. */
export const TaskHomePage = () => {
  const { t } = useTranslation();
  const { user } = useAuth();
  const role = user?.role;
  const tasks = (role && TASKS_BY_ROLE[role]) || [];
  const [pending, setPending] = useState<ItemAtencion[]>([]);
  // Administran el sistema: su trabajo está en Usuarios y accesos.
  if (role === 'SUPER_ADMIN' || role === 'ADMIN_SEGURIDAD') return <Navigate to="/usuarios" replace/>;

  return (<Box>
      <PageHeader title={t('tasks.title')} subtitle={t('tasks.subtitle')}/>
      {role && !WITHOUT_PENDING.includes(role) && <AttentionInbox onLoaded={setPending}/>}
      <Grid container spacing={2}>
        {tasks.map((task) => (<Grid key={task.key} size={{ xs: 12, sm: 6, md: 4 }}>
            <Card variant="outlined" sx={{ height: '100%' }}>
              <CardActionArea component={RouterLink} to={task.to} sx={{ height: '100%' }}>
                <CardContent sx={{ display: 'flex', gap: 1.5, alignItems: 'flex-start' }}>
                  <task.icon color="primary"/>
                  <Box sx={{ flex: 1, minWidth: 0 }}>
                    <Typography variant="subtitle1" component="span" sx={{ fontWeight: 700, display: 'block' }}>{t(`tasks.items.${task.key}.title`)}</Typography>
                    <Typography variant="body2" color="text.secondary">{t(`tasks.items.${task.key}.help`)}</Typography>
                  </Box>
                  {pending.find((p) => p.clave === task.pending && p.total > 0) && <Chip size="small" color="warning" label={pending.find((p) => p.clave === task.pending)?.total}/>}
                </CardContent>
              </CardActionArea>
            </Card>
          </Grid>))}
      </Grid>
    </Box>);
};
