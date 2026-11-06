import { useEffect, useMemo, useState } from 'react';
import { Alert, Autocomplete, Box, Button, Chip, CircularProgress, DialogActions, DialogContent, DialogTitle, FormControlLabel, List, ListItem, ListItemText, Stack, Switch, TextField, Typography } from '@mui/material';
import RestoreIcon from '@mui/icons-material/Restore';
import SaveIcon from '@mui/icons-material/Save';
import VisibilityIcon from '@mui/icons-material/Visibility';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';
import { getApiErrorMessage } from '../../api/errors';
import { SectionCard } from '../../components/ui';
import type { PersonaResumen } from '../../types/users';
import { personService } from '../persons/personService';
import { directoryService } from './directoryService';
import { isForbidden, isValidEmail, recipientOriginColor, recipientOriginLabel } from './directoryUtils';
import type { AdGroup, NotificationRule, NotificationRuleInput, RecipientPreview } from './types';
import { Dialog } from '../../components/ui/Dialog';

const GROUP_SEARCH_DEBOUNCE_MS = 350;

type SwitchField = 'activa' | 'notificar_afectado' | 'notificar_jefe' | 'copiar_admins';
const SWITCH_FIELDS: SwitchField[] = ['activa', 'notificar_afectado', 'notificar_jefe', 'copiar_admins'];

const fromRule = (r: NotificationRule): NotificationRuleInput => ({
  activa: r.activa,
  notificar_afectado: r.notificar_afectado,
  notificar_jefe: r.notificar_jefe,
  copiar_admins: r.copiar_admins,
  grupos_ad: [...r.grupos_ad],
  correos_extra: [...r.correos_extra],
});

const personLabel = (p: PersonaResumen) =>
  `${p.PER_Primer_Nombre} ${p.PER_Primer_Apellido} · ${p.PER_Email_Corporativo}`;

interface Props {
  rule: NotificationRule | null;
  onClose: () => void;
  onSaved: (rule: NotificationRule) => void;
  onReset: () => void;
}

export const RuleEditDialog = ({ rule, onClose, onSaved, onReset }: Props) => {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const open = !!rule;

  const [form, setForm] = useState<NotificationRuleInput | null>(null);
  const [saving, setSaving] = useState(false);
  const [resetting, setResetting] = useState(false);
  const [invalidEmails, setInvalidEmails] = useState<string[]>([]);

  // Búsqueda de grupos AD (debounced)
  const [groupQuery, setGroupQuery] = useState('');
  const [groupOptions, setGroupOptions] = useState<AdGroup[]>([]);
  const [groupsLoading, setGroupsLoading] = useState(false);

  // Vista previa
  const [persons, setPersons] = useState<PersonaResumen[]>([]);
  const [personsLoading, setPersonsLoading] = useState(false);
  const [previewPerson, setPreviewPerson] = useState<PersonaResumen | null>(null);
  const [preview, setPreview] = useState<RecipientPreview | null>(null);
  const [previewLoading, setPreviewLoading] = useState(false);

  const evento = rule?.evento ?? '';

  // Inicializa el formulario al abrir / cambiar de regla.
  useEffect(() => {
    if (!rule) return;
    setForm(fromRule(rule));
    setInvalidEmails([]);
  }, [rule]);

  // Limpia la vista previa sólo al cambiar de evento (no tras guardar).
  useEffect(() => {
    setPreview(null);
    setPreviewPerson(null);
    setGroupQuery('');
  }, [evento]);

  // Carga perezosa de personas para el selector de vista previa.
  useEffect(() => {
    if (!open || persons.length > 0) return;
    let cancelled = false;
    setPersonsLoading(true);
    personService
      .list()
      .then((data) => {
        if (!cancelled) setPersons(data.filter((p) => p.PER_Estado));
      })
      .catch(() => {
        /* El selector es opcional: la vista previa funciona sin persona. */
      })
      .finally(() => {
        if (!cancelled) setPersonsLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [open, persons.length]);

  useEffect(() => {
    if (!open) return;
    const q = groupQuery.trim();
    if (q.length < 2) {
      setGroupOptions([]);
      setGroupsLoading(false);
      return;
    }
    const controller = new AbortController();
    setGroupsLoading(true);
    const timer = window.setTimeout(() => {
      directoryService
        .searchGroups(q, controller.signal)
        .then((data) => setGroupOptions(data))
        .catch(() => {
          if (!controller.signal.aborted) setGroupOptions([]);
        })
        .finally(() => {
          if (!controller.signal.aborted) setGroupsLoading(false);
        });
    }, GROUP_SEARCH_DEBOUNCE_MS);
    return () => {
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [groupQuery, open]);

  const groupOptionNames = useMemo(() => groupOptions.map((g) => g.nombre), [groupOptions]);
  const groupDnByName = useMemo(() => new Map(groupOptions.map((g) => [g.nombre, g.dn])), [groupOptions]);

  const handleError = (err: unknown, fallback: string) =>
    enqueueSnackbar(isForbidden(err) ? t('directory.forbidden_action') : getApiErrorMessage(err, fallback), {
      variant: 'error',
    });

  const handleSave = async () => {
    if (!rule || !form) return;
    setSaving(true);
    try {
      const updated = await directoryService.updateRule(rule.evento, form);
      enqueueSnackbar(t('directory.rules.saved'), { variant: 'success' });
      setPreview(null);
      onSaved(updated);
    } catch (err) {
      handleError(err, t('common.error_save'));
    } finally {
      setSaving(false);
    }
  };

  const handleReset = async () => {
    if (!rule) return;
    setResetting(true);
    try {
      await directoryService.resetRule(rule.evento);
      enqueueSnackbar(t('directory.rules.restored'), { variant: 'success' });
      onReset();
    } catch (err) {
      handleError(err, t('common.error_generic'));
    } finally {
      setResetting(false);
    }
  };

  const handlePreview = async () => {
    if (!rule) return;
    setPreviewLoading(true);
    try {
      setPreview(await directoryService.previewRecipients(rule.evento, previewPerson?.PER_Persona));
    } catch (err) {
      setPreview(null);
      handleError(err, t('common.error_loading'));
    } finally {
      setPreviewLoading(false);
    }
  };

  const handleEmailsChange = (values: string[]) => {
    const cleaned = values.map((v) => v.trim()).filter(Boolean);
    const valid = Array.from(new Set(cleaned.filter(isValidEmail).map((v) => v.toLowerCase())));
    setInvalidEmails(cleaned.filter((v) => !isValidEmail(v)));
    setForm((f) => (f ? { ...f, correos_extra: valid } : f));
  };

  const dirty = useMemo(() => {
    if (!rule || !form) return false;
    return JSON.stringify(fromRule(rule)) !== JSON.stringify(form);
  }, [rule, form]);

  const busy = saving || resetting;

  return (
    <Dialog open={open} onClose={busy ? undefined : onClose} maxWidth="md" fullWidth>
      <DialogTitle>
        {t('directory.rules.edit_title', { event: t(`directory.events.${evento}`, evento) })}
      </DialogTitle>
      <DialogContent dividers>
        {form && (
          <Stack spacing={2}>
            <SectionCard title={t('directory.rules.recipients_section')}>
              <Stack spacing={1}>
                {SWITCH_FIELDS.map((field) => (
                  <FormControlLabel
                    key={field}
                    control={
                      <Switch
                        checked={form[field]}
                        disabled={field !== 'activa' && !form.activa}
                        onChange={(e) => setForm({ ...form, [field]: e.target.checked })}
                      />
                    }
                    label={
                      <Box>
                        <Typography variant="body2" fontWeight={500}>
                          {t(`directory.rules.fields.${field}`)}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          {t(`directory.rules.fields.${field}_hint`)}
                        </Typography>
                      </Box>
                    }
                  />
                ))}
              </Stack>
            </SectionCard>

            <SectionCard title={t('directory.rules.extra_section')}>
              <Stack spacing={2}>
                <Autocomplete
                  multiple
                  freeSolo
                  disabled={!form.activa}
                  options={groupOptionNames}
                  value={form.grupos_ad}
                  loading={groupsLoading}
                  filterOptions={(x) => x}
                  inputValue={groupQuery}
                  onInputChange={(_, v, reason) => {
                    if (reason !== 'reset') setGroupQuery(v);
                  }}
                  onChange={(_, values) => {
                    const cleaned = Array.from(new Set(values.map((v) => v.trim()).filter(Boolean)));
                    setForm({ ...form, grupos_ad: cleaned });
                    setGroupQuery('');
                  }}
                  noOptionsText={
                    groupQuery.trim().length < 2 ? t('directory.rules.groups_type_more') : t('directory.rules.groups_none')
                  }
                  renderOption={(props, option) => {
                    const { key, ...rest } = props as typeof props & { key: string };
                    return (
                      <li key={key} {...rest}>
                        <Box sx={{ minWidth: 0 }}>
                          <Typography variant="body2">{option}</Typography>
                          {groupDnByName.get(option) && (
                            <Typography variant="caption" color="text.secondary" sx={{ wordBreak: 'break-all' }}>
                              {groupDnByName.get(option)}
                            </Typography>
                          )}
                        </Box>
                      </li>
                    );
                  }}
                  renderInput={(params) => (
                    <TextField
                      {...params}
                      label={t('directory.rules.groups_label')}
                      placeholder={t('directory.rules.groups_placeholder')}
                      helperText={t('directory.rules.groups_hint')}
                      slotProps={{
                        input: {
                          ...params.InputProps,
                          endAdornment: (
                            <>
                              {groupsLoading && <CircularProgress size={16} />}
                              {params.InputProps.endAdornment}
                            </>
                          ),
                        },
                      }}
                    />
                  )}
                />

                <Autocomplete
                  multiple
                  freeSolo
                  disabled={!form.activa}
                  options={[] as string[]}
                  value={form.correos_extra}
                  onChange={(_, values) => handleEmailsChange(values)}
                  renderInput={(params) => (
                    <TextField
                      {...params}
                      type="email"
                      label={t('directory.rules.emails_label')}
                      placeholder={t('directory.rules.emails_placeholder')}
                      error={invalidEmails.length > 0}
                      helperText={
                        invalidEmails.length > 0
                          ? t('directory.rules.emails_invalid', { emails: invalidEmails.join(', ') })
                          : t('directory.rules.emails_hint')
                      }
                    />
                  )}
                />
              </Stack>
            </SectionCard>

            <SectionCard
              title={t('directory.preview.title')}
              subtitle={dirty ? t('directory.preview.unsaved_hint') : t('directory.preview.subtitle')}
            >
              <Stack spacing={2}>
                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} alignItems={{ sm: 'center' }}>
                  <Autocomplete
                    sx={{ flex: 1 }}
                    size="small"
                    options={persons}
                    loading={personsLoading}
                    value={previewPerson}
                    onChange={(_, v) => setPreviewPerson(v)}
                    getOptionLabel={personLabel}
                    isOptionEqualToValue={(a, b) => a.PER_Persona === b.PER_Persona}
                    renderInput={(params) => (
                      <TextField
                        {...params}
                        label={t('directory.preview.person_label')}
                        placeholder={t('directory.preview.person_placeholder')}
                      />
                    )}
                  />
                  <Button
                    variant="outlined"
                    startIcon={previewLoading ? <CircularProgress size={16} /> : <VisibilityIcon />}
                    disabled={previewLoading}
                    onClick={handlePreview}
                  >
                    {t('directory.preview.run')}
                  </Button>
                </Stack>

                {preview &&
                  (preview.detalle.length === 0 ? (
                    <Alert severity="warning">{t('directory.preview.none')}</Alert>
                  ) : (
                    <Box>
                      <Typography variant="caption" color="text.secondary">
                        {t('directory.preview.count', { count: preview.destinatarios.length })}
                      </Typography>
                      <List dense disablePadding>
                        {preview.detalle.map((d, i) => (
                          <ListItem
                            key={`${d.email}-${d.origen}-${i}`}
                            disableGutters
                            secondaryAction={
                              <Chip
                                size="small"
                                label={recipientOriginLabel(d.origen, t)}
                                color={recipientOriginColor(d.origen)}
                                variant="outlined"
                              />
                            }
                          >
                            <ListItemText primary={d.email} slotProps={{ primary: { variant: 'body2' } }} />
                          </ListItem>
                        ))}
                      </List>
                    </Box>
                  ))}
              </Stack>
            </SectionCard>
          </Stack>
        )}
      </DialogContent>
      <DialogActions sx={{ px: 3, py: 2 }}>
        <Button
          color="warning"
          startIcon={resetting ? <CircularProgress size={16} /> : <RestoreIcon />}
          disabled={busy || !rule?.personalizada}
          onClick={handleReset}
          sx={{ mr: 'auto' }}
        >
          {t('directory.rules.restore_defaults')}
        </Button>
        <Button onClick={onClose} disabled={busy}>
          {t('common.close')}
        </Button>
        <Button
          variant="contained"
          startIcon={saving ? <CircularProgress size={16} color="inherit" /> : <SaveIcon />}
          disabled={busy || !dirty}
          onClick={handleSave}
        >
          {t('common.save')}
        </Button>
      </DialogActions>
    </Dialog>
  );
};
