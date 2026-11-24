import { useEffect, useState } from 'react';
import { Chip, FormControlLabel, Skeleton, Stack, Switch, Typography } from '@mui/material';
import ShieldIcon from '@mui/icons-material/Shield';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { SectionCard } from '../../components/ui';
import { configService } from './configService';
import { useLiveTick } from '../../hooks/useLiveRefresh';

/**
 * MFA obligatorio: un interruptor que se guarda al instante y queda en la bitácora. Si el servidor no permite
 * apagarlo (MFA_CAN_BE_DISABLED=false) y ya está activo, se muestra fijo: no se ofrece lo que no se puede hacer.
 */
export const MfaRequirementCard = () => {
    const { t } = useTranslation();
    const { enqueueSnackbar } = useSnackbar();
    const [estado, setEstado] = useState<{ required: boolean; can_be_disabled: boolean } | null>(null);
    const [saving, setSaving] = useState(false);

    const liveTick = useLiveTick();
    useEffect(() => {
        let active = true;
        configService.getMfaRequirement()
            .then((value) => { if (active) setEstado(value); })
            .catch(() => enqueueSnackbar(t('common.error_loading'), { variant: 'error' }));
        return () => { active = false; };
    }, [enqueueSnackbar, t, liveTick]);

    const change = async (next: boolean) => {
        setSaving(true);
        try {
            const required = await configService.setMfaRequirement(next);
            setEstado((actual) => (actual ? { ...actual, required } : actual));
            enqueueSnackbar(t(next ? 'config.mfa_enabled' : 'config.mfa_disabled'), { variant: 'success' });
        }
        catch (error: unknown) {
            enqueueSnackbar(getApiErrorMessage(error, t('common.error_save')), { variant: 'error' });
        }
        finally {
            setSaving(false);
        }
    };

    const fijo = estado !== null && estado.required && !estado.can_be_disabled;
    return (<SectionCard icon={<ShieldIcon />} title={t('config.mfa_title')} subtitle={t('config.mfa_help')}>
            {estado === null
            ? <Skeleton width={260} height={38} />
            : fijo
                ? (<Stack direction="row" spacing={1.5} alignItems="center" flexWrap="wrap" useFlexGap>
                    <Chip color="success" label={t('config.mfa_always_on')}/>
                    <Typography variant="body2" color="text.secondary">{t('config.mfa_always_on_help')}</Typography>
                  </Stack>)
                : <FormControlLabel label={t('config.mfa_switch')} control={<Switch checked={estado.required} disabled={saving} onChange={(_, next) => { void change(next); }}/>}/>}
        </SectionCard>);
};
