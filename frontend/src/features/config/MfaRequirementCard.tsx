import { useEffect, useState } from 'react';
import { FormControlLabel, Skeleton, Switch } from '@mui/material';
import ShieldIcon from '@mui/icons-material/Shield';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { SectionCard } from '../../components/ui';
import { configService } from './configService';
import { useLiveTick } from '../../hooks/useLiveRefresh';

/** Interruptor del MFA obligatorio: se guarda al instante y queda en la bitácora. */
export const MfaRequirementCard = () => {
    const { t } = useTranslation();
    const { enqueueSnackbar } = useSnackbar();
    const [required, setRequired] = useState<boolean | null>(null);
    const [saving, setSaving] = useState(false);

    const liveTick = useLiveTick();
    useEffect(() => {
        let active = true;
        configService.getMfaRequirement()
            .then((value) => { if (active) setRequired(value); })
            .catch(() => enqueueSnackbar(t('common.error_loading'), { variant: 'error' }));
        return () => { active = false; };
    }, [enqueueSnackbar, t, liveTick]);

    const change = async (next: boolean) => {
        setSaving(true);
        try {
            setRequired(await configService.setMfaRequirement(next));
            enqueueSnackbar(t(next ? 'config.mfa_enabled' : 'config.mfa_disabled'), { variant: 'success' });
        }
        catch (error: unknown) {
            enqueueSnackbar(getApiErrorMessage(error, t('common.error_save')), { variant: 'error' });
        }
        finally {
            setSaving(false);
        }
    };

    return (<SectionCard icon={<ShieldIcon />} title={t('config.mfa_title')} subtitle={t('config.mfa_help')}>
            {required === null
            ? <Skeleton width={260} height={38} />
            : <FormControlLabel label={t('config.mfa_switch')} control={<Switch checked={required} disabled={saving} onChange={(_, next) => { void change(next); }}/>}/>}
        </SectionCard>);
};
