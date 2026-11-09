import { useCallback, useEffect, useMemo, useState } from 'react';
import type { MouseEvent } from 'react';
import {
  Box, Button, Chip, Divider, IconButton, ListItemIcon, ListItemText, Menu, MenuItem, Stack, TableCell, TableRow,
  TextField, Tooltip, Typography,
} from '@mui/material';
import AddIcon from '@mui/icons-material/Add';
import BlockIcon from '@mui/icons-material/Block';
import CheckCircleOutlineIcon from '@mui/icons-material/CheckCircleOutline';
import DevicesOtherIcon from '@mui/icons-material/DevicesOther';
import EditIcon from '@mui/icons-material/Edit';
import GppBadIcon from '@mui/icons-material/GppBad';
import GppMaybeIcon from '@mui/icons-material/GppMaybe';
import LockOpenIcon from '@mui/icons-material/LockOpen';
import LockResetIcon from '@mui/icons-material/LockReset';
import MoreVertIcon from '@mui/icons-material/MoreVert';
import RefreshIcon from '@mui/icons-material/Refresh';
import { useSnackbar } from 'notistack';
import { useTranslation } from 'react-i18next';
import { SearchInput } from '../../components/SearchInput';
import { PageHeader, DataTableShell, StatusChip } from '../../components/ui';
import { CollapsibleToolbar } from '../../components/CollapsibleToolbar';
import { useConfirm } from '../../components/confirm-context';
import { useAuth } from '../../context/auth-context';
import { getApiErrorMessage } from '../../api/errors';
import type { UserRole } from '../../types/auth';
import type { ResumenSeguridad, UsuarioSistema } from '../../types/users';
import { formatDateTime, formatRelativeDate, formatTimeOrDateTime, parseApiDate } from '../../utils/dates';
import { ALL_ROLES, canManageRole, roleChipColor } from '../../utils/roles';
import { userService } from './userService';
import { UserDialog } from './UserDialog';
import { SecurityOverview } from './SecurityOverview';
import { ActiveSessionsPanel } from './ActiveSessionsPanel';
import { TemporaryPasswordDialog } from './TemporaryPasswordDialog';
import { MfaExemptionDialog } from './MfaExemptionDialog';

type MfaFilter = '' | 'enabled' | 'pending' | 'none';
type StatusFilter = '' | 'active' | 'inactive';

const fullName = (u: UsuarioSistema) => (u.persona
  ? [u.persona.PER_Primer_Nombre, u.persona.PER_Primer_Apellido].filter(Boolean).join(' ')
  : u.USU_Username);

const lockedUntil = (u: UsuarioSistema, now: Date): Date | null => {
  const until = parseApiDate(u.USU_Bloqueado_Hasta);
  return until && until > now ? until : null;
};

const mfaState = (u: UsuarioSistema): Exclude<MfaFilter, ''> | 'sso' => {
  if (u.USU_2FA_Habilitado) return 'enabled';
  if (u.USU_SSO_Habilitado) return 'sso';
  if (u.mfa_requerido) return 'pending';
  return 'none';
};

export const UsersPage = () => {
  const { t, i18n } = useTranslation();
  const confirm = useConfirm();
  const { enqueueSnackbar } = useSnackbar();
  const { user: currentUser } = useAuth();
  const locale = i18n.resolvedLanguage ?? i18n.language;
  const [usuarios, setUsuarios] = useState<UsuarioSistema[]>([]);
  const [summary, setSummary] = useState<ResumenSeguridad | null>(null);
  const [loading, setLoading] = useState(true);
  const [search, setSearch] = useState('');
  const [roleFilter, setRoleFilter] = useState<'' | UserRole>('');
  const [mfaFilter, setMfaFilter] = useState<MfaFilter>('');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('');
  const [dialogOpen, setDialogOpen] = useState(false);
  const [selectedUser, setSelectedUser] = useState<UsuarioSistema | null>(null);
  const [menu, setMenu] = useState<{ anchor: HTMLElement; row: UsuarioSistema } | null>(null);
  const [tempPassword, setTempPassword] = useState<{ value: string; name: string } | null>(null);
  const [exemptUser, setExemptUser] = useState<{ id: string; name: string } | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const [users, resumen] = await Promise.all([userService.getUsuarios(), userService.getResumenSeguridad()]);
      setUsuarios(users);
      setSummary(resumen);
    } catch {
      enqueueSnackbar(t('common.error_loading'), { variant: 'error' });
    } finally {
      setLoading(false);
    }
  }, [enqueueSnackbar, t]);

  useEffect(() => { void load(); }, [load]);

  const now = new Date();

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase();
    return usuarios.filter((u) => {
      if (term) {
        const haystack = [u.USU_Username, fullName(u), u.persona?.PER_Email_Corporativo ?? ''].join(' ').toLowerCase();
        if (!haystack.includes(term)) return false;
      }
      if (roleFilter && u.USU_Rol !== roleFilter) return false;
      if (statusFilter === 'active' && !u.USU_Estado) return false;
      if (statusFilter === 'inactive' && u.USU_Estado) return false;
      if (mfaFilter) {
        const state = mfaState(u);
        if (mfaFilter === 'none' ? !(state === 'none' || state === 'sso') : state !== mfaFilter) return false;
      }
      return true;
    });
  }, [usuarios, search, roleFilter, statusFilter, mfaFilter]);

  const activeFilters = [roleFilter, mfaFilter, statusFilter].filter(Boolean).length;

  const isSelf = (u: UsuarioSistema) => u.USU_Username === currentUser?.sub;
  const canAdminister = (u: UsuarioSistema) => !isSelf(u) && canManageRole(currentUser?.role);
  // Alcance de datos: todas las sedes o las asignadas (sin sedes = no ve datos).
  const scopeMissing = (u: UsuarioSistema) => !u.alcance_global_efectivo && !(u.sedes ?? []).length;
  const scopeLabel = (u: UsuarioSistema) => {
    if (u.alcance_global_efectivo) return t('scope.all_sedes');
    const names = (u.sedes ?? []).map((s) => s.SED_Nombre);
    return names.length ? names.join(', ') : t('scope.no_sedes');
  };

  /** Ejecuta una acción administrativa con confirmación opcional y recarga el listado. */
  const runAction = async (
    row: UsuarioSistema,
    action: () => Promise<void>,
    successKey: string,
    confirmOptions?: { message: string; confirmText: string; destructive?: boolean },
  ) => {
    setMenu(null);
    if (confirmOptions && !(await confirm(confirmOptions))) return;
    try {
      await action();
      enqueueSnackbar(t(successKey, { name: fullName(row) }), { variant: 'success' });
    } catch (error) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_generic')), { variant: 'error' });
    } finally {
      void load();
    }
  };

  const handleResetPassword = async (row: UsuarioSistema) => {
    setMenu(null);
    const name = fullName(row);
    if (!(await confirm({
      message: t('users.actions.reset_password_confirm', { name }),
      confirmText: t('users.actions.reset_password'),
    }))) return;
    try {
      const value = await userService.resetPassword(row.USU_Usuario);
      setTempPassword({ value, name });
    } catch (error) {
      enqueueSnackbar(getApiErrorMessage(error, t('common.error_generic')), { variant: 'error' });
    } finally {
      void load();
    }
  };

  const openMenu = (event: MouseEvent<HTMLElement>, row: UsuarioSistema) => setMenu({ anchor: event.currentTarget, row });

  const renderMfa = (u: UsuarioSistema) => {
    const state = mfaState(u);
    if (state === 'enabled') {
      return (
        <Chip
          size="small"
          color="success"
          variant="outlined"
          label={u.USU_2FA_Metodo === 'EMAIL' ? t('users.mfa.email') : t('users.mfa.totp')}
        />
      );
    }
    if (state === 'sso') {
      const provider = u.USU_SSO_Provider === 'microsoft' ? 'Microsoft 365' : u.USU_SSO_Provider === 'google' ? 'Google Workspace' : '';
      return (
        <Tooltip title={t('users.mfa.sso_hint')}>
          <Chip size="small" color="info" variant="outlined" label={provider ? `${t('users.mfa.sso')} · ${provider}` : t('users.mfa.sso')} />
        </Tooltip>
      );
    }
    const exemptUntil = parseApiDate(u.mfa_exento_hasta ?? null);
    if (state === 'pending' && exemptUntil) return (
        <Tooltip title={u.mfa_exento_motivo ?? ''}>
            <Chip size="small" color="info" variant="outlined"
              label={t('users.mfa_exemption.chip', { date: exemptUntil.toLocaleDateString(locale) })} />
        </Tooltip>
    );
    if (state === 'pending') return (
        <Tooltip title={t('users.filters.mfa_pending')}>
            <Chip size="small" color="warning" label={t('users.mfa.pending_required')} />
        </Tooltip>
    );
    return <Chip size="small" variant="outlined" label={t('users.mfa.none')} />;
  };

  const renderLastLogin = (u: UsuarioSistema) => {
    const date = parseApiDate(u.USU_Ultimo_Login);
    if (!date) return <Typography variant="body2" color="text.secondary">{t('users.table.never')}</Typography>;
    return (
      <Tooltip title={formatDateTime(date, locale)}>
        <Typography variant="body2" color="text.secondary" component="span">{formatRelativeDate(date, locale, now)}</Typography>
      </Tooltip>
    );
  };

  const renderFlags = (u: UsuarioSistema) => {
    const until = lockedUntil(u, now);
    const flags = [];
    if (until) {
      flags.push(
        <Chip key="locked" size="small" color="error" variant="outlined"
          label={t('users.flags.locked_until', { time: formatTimeOrDateTime(until, locale, now) })} />,
      );
    }
    if (u.USU_Debe_Cambiar_Password) {
      flags.push(<Chip key="pwd" size="small" color="warning" variant="outlined" label={t('users.flags.password_change')} />);
    }
    if (flags.length === 0) return <Typography variant="body2" color="text.disabled">—</Typography>;
    return <Stack direction="row" spacing={0.5} useFlexGap flexWrap="wrap">{flags}</Stack>;
  };

  const renderActions = (u: UsuarioSistema) => {
    if (!canAdminister(u)) return null;
    return (
      <IconButton
        size="small"
        onClick={(e) => openMenu(e, u)}
        aria-label={t('users.actions.menu', { name: fullName(u) })}
        aria-haspopup="menu"
      >
        <MoreVertIcon fontSize="small" />
      </IconButton>
    );
  };

  const menuRow = menu?.row;
  const menuName = menuRow ? fullName(menuRow) : '';

  return (
    <Box>
      <PageHeader
        title={t('users.title')}
        subtitle={t('users.subtitle')}
        badge={filtered.length}
        actions={(
          <>
            <Tooltip title={t('users.refresh')}>
              <span>
                <IconButton onClick={() => void load()} disabled={loading} aria-label={t('users.refresh')}>
                  <RefreshIcon />
                </IconButton>
              </span>
            </Tooltip>
            {currentUser?.role === 'SUPER_ADMIN' && (
              <Button variant="contained" startIcon={<AddIcon />} onClick={() => { setSelectedUser(null); setDialogOpen(true); }}>
                {t('users.new_btn')}
              </Button>
            )}
          </>
        )}
      />

      <SecurityOverview summary={summary} loading={loading} />

      <Box sx={{ mb: 2 }}>
        <ActiveSessionsPanel refreshKey={summary?.sesiones_activas} />
      </Box>

      <CollapsibleToolbar
        storageKey="users"
        search={<SearchInput value={search} onChange={setSearch} placeholder={t('users.search_placeholder')} />}
        summary={activeFilters > 0 && (
          <Chip size="small" color="primary" variant="outlined" label={t('common.active_filters_count', { count: activeFilters })} />
        )}
      >
        <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1.5, alignItems: 'center' }}>
          <TextField
            select
            size="small"
            label={t('users.filters.role')}
            value={roleFilter}
            onChange={(e) => setRoleFilter(e.target.value as '' | UserRole)}
            sx={{ minWidth: { xs: '100%', sm: 200 } }}
          >
            <MenuItem value="">{t('common.all')}</MenuItem>
            {ALL_ROLES.map((role) => <MenuItem key={role} value={role}>{t(`users.roles.${role}`)}</MenuItem>)}
          </TextField>
          <TextField
            select
            size="small"
            label={t('users.filters.mfa')}
            value={mfaFilter}
            onChange={(e) => setMfaFilter(e.target.value as MfaFilter)}
            sx={{ minWidth: { xs: '100%', sm: 200 } }}
          >
            <MenuItem value="">{t('common.all')}</MenuItem>
            <MenuItem value="enabled">{t('users.filters.mfa_enabled')}</MenuItem>
            <MenuItem value="pending">{t('users.filters.mfa_pending')}</MenuItem>
            <MenuItem value="none">{t('users.filters.mfa_none')}</MenuItem>
          </TextField>
          <TextField
            select
            size="small"
            label={t('users.filters.status')}
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value as StatusFilter)}
            sx={{ minWidth: { xs: '100%', sm: 160 } }}
          >
            <MenuItem value="">{t('common.all')}</MenuItem>
            <MenuItem value="active">{t('common.active')}</MenuItem>
            <MenuItem value="inactive">{t('common.inactive')}</MenuItem>
          </TextField>
        </Box>
      </CollapsibleToolbar>

      <DataTableShell
        columns={[
          { key: 'user', label: t('users.table.user'), sortValue: (u) => fullName(u) },
          { key: 'role', label: t('users.table.role'), sortValue: (u) => t(`users.roles.${u.USU_Rol}`, u.USU_Rol) },
          { key: 'status', label: t('users.table.status'), sortValue: (u) => (u.USU_Estado ? 0 : 1) },
          { key: 'mfa', label: t('users.table.mfa') },
          { key: 'last_login', label: t('users.table.last_login') },
          { key: 'flags', label: t('users.table.flags') },
          { key: 'actions', label: t('users.table.actions'), align: 'right' },
        ]}
        rows={filtered}
        loading={loading && usuarios.length === 0}
        emptyTitle={usuarios.length > 0 ? t('users.empty_filtered') : t('common.no_records')}
        renderRow={(u) => (
          <TableRow key={u.USU_Usuario} hover>
            <TableCell>
              <Stack direction="row" spacing={1} alignItems="center">
                <Typography variant="body2" fontWeight={700}>{fullName(u)}</Typography>
                {isSelf(u) && <Chip size="small" label={t('users.table.you')} sx={{ height: 20 }} />}
              </Stack>
              <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>
                {[u.USU_Username, u.persona?.PER_Email_Corporativo].filter(Boolean).join(' · ')}
              </Typography>
            </TableCell>
            <TableCell>
              <Chip size="small" label={t(`users.roles.${u.USU_Rol}`, u.USU_Rol)} color={roleChipColor(u.USU_Rol)} />
              <Typography variant="caption" color={scopeMissing(u) ? 'warning.main' : 'text.secondary'} sx={{ display: 'block', mt: 0.5 }}>
                {scopeLabel(u)}
              </Typography>
            </TableCell>
            <TableCell>
              <StatusChip
                label={u.USU_Estado ? t('common.active') : t('common.inactive')}
                variant={u.USU_Estado ? 'activo' : 'inactivo'}
              />
            </TableCell>
            <TableCell>{renderMfa(u)}</TableCell>
            <TableCell>{renderLastLogin(u)}</TableCell>
            <TableCell>{renderFlags(u)}</TableCell>
            <TableCell align="right">{renderActions(u)}</TableCell>
          </TableRow>
        )}
      />

      <Menu
        anchorEl={menu?.anchor}
        open={Boolean(menu)}
        onClose={() => setMenu(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
        transformOrigin={{ vertical: 'top', horizontal: 'right' }}
        slotProps={{ paper: { sx: { minWidth: 240 } } }}
      >
        {menuRow && [
          <MenuItem key="edit" dense onClick={() => { setMenu(null); setSelectedUser(menuRow); setDialogOpen(true); }}>
            <ListItemIcon><EditIcon fontSize="small" /></ListItemIcon>
            <ListItemText>{t('common.edit')}</ListItemText>
          </MenuItem>,
          <MenuItem key="reset-password" dense onClick={() => void handleResetPassword(menuRow)}>
            <ListItemIcon><LockResetIcon fontSize="small" /></ListItemIcon>
            <ListItemText>{t('users.actions.reset_password')}</ListItemText>
          </MenuItem>,
          menuRow.USU_2FA_Habilitado && (
            <MenuItem key="reset-mfa" dense onClick={() => void runAction(menuRow, () => userService.reset2fa(menuRow.USU_Usuario), 'users.actions.reset_mfa_done', {
              message: t('users.actions.reset_mfa_confirm', { name: menuName }),
              confirmText: t('users.actions.reset_mfa'),
              destructive: true,
            })}>
              <ListItemIcon><GppBadIcon fontSize="small" /></ListItemIcon>
              <ListItemText>{t('users.actions.reset_mfa')}</ListItemText>
            </MenuItem>
          ),
          currentUser?.role === 'SUPER_ADMIN' && !menuRow.USU_2FA_Habilitado && menuRow.mfa_requerido && (menuRow.mfa_exento_hasta ? (
            <MenuItem key="mfa-exemption-off" dense onClick={() => void runAction(menuRow, () => userService.revokeMfaExemption(menuRow.USU_Usuario), 'users.mfa_exemption.revoked', {
              message: t('users.mfa_exemption.revoke_confirm', { name: menuName }),
              confirmText: t('users.mfa_exemption.revoke'),
            })}>
              <ListItemIcon><GppMaybeIcon fontSize="small" /></ListItemIcon>
              <ListItemText>{t('users.mfa_exemption.revoke')}</ListItemText>
            </MenuItem>
          ) : (
            <MenuItem key="mfa-exemption" dense onClick={() => { setMenu(null); setExemptUser({ id: menuRow.USU_Usuario, name: menuName }); }}>
              <ListItemIcon><GppMaybeIcon fontSize="small" /></ListItemIcon>
              <ListItemText>{t('users.mfa_exemption.action')}</ListItemText>
            </MenuItem>
          )),
          lockedUntil(menuRow, now) && (
            <MenuItem key="unlock" dense onClick={() => void runAction(menuRow, () => userService.unlock(menuRow.USU_Usuario), 'users.actions.unlock_done')}>
              <ListItemIcon><LockOpenIcon fontSize="small" /></ListItemIcon>
              <ListItemText>{t('users.actions.unlock')}</ListItemText>
            </MenuItem>
          ),
          <MenuItem key="sessions" dense onClick={() => void runAction(menuRow, () => userService.revokeSessions(menuRow.USU_Usuario), 'users.actions.revoke_sessions_done', {
            message: t('users.actions.revoke_sessions_confirm', { name: menuName }),
            confirmText: t('users.actions.revoke_sessions'),
          })}>
            <ListItemIcon><DevicesOtherIcon fontSize="small" /></ListItemIcon>
            <ListItemText>{t('users.actions.revoke_sessions')}</ListItemText>
          </MenuItem>,
          <Divider key="divider" />,
          menuRow.USU_Estado ? (
            <MenuItem key="deactivate" dense sx={{ color: 'error.main' }} onClick={() => void runAction(menuRow, () => userService.setEstado(menuRow.USU_Usuario, false), 'users.actions.deactivate_done', {
              message: t('users.actions.deactivate_confirm', { name: menuName }),
              confirmText: t('users.actions.deactivate'),
              destructive: true,
            })}>
              <ListItemIcon sx={{ color: 'inherit' }}><BlockIcon fontSize="small" /></ListItemIcon>
              <ListItemText>{t('users.actions.deactivate')}</ListItemText>
            </MenuItem>
          ) : (
            <MenuItem key="activate" dense onClick={() => void runAction(menuRow, () => userService.setEstado(menuRow.USU_Usuario, true), 'users.actions.activate_done')}>
              <ListItemIcon><CheckCircleOutlineIcon fontSize="small" /></ListItemIcon>
              <ListItemText>{t('users.actions.activate')}</ListItemText>
            </MenuItem>
          ),
        ]}
      </Menu>

      <UserDialog
        open={dialogOpen}
        userToEdit={selectedUser}
        policy={summary?.politica}
        onClose={(refresh) => {
          setDialogOpen(false);
          setSelectedUser(null);
          if (refresh) void load();
        }}
      />

      <MfaExemptionDialog user={exemptUser} onClose={() => setExemptUser(null)} onSaved={() => void load()} />
      <TemporaryPasswordDialog
        password={tempPassword?.value ?? null}
        userName={tempPassword?.name ?? ''}
        onClose={() => setTempPassword(null)}
      />
    </Box>
  );
};
