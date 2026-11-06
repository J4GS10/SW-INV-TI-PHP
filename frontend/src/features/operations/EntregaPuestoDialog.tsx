import { useState } from 'react';
import { Button, DialogActions, DialogContent, DialogTitle, IconButton } from '@mui/material';
import CloseIcon from '@mui/icons-material/Close';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import type { ResultadoLote } from '../../api/productividadService';
import type { Activo } from '../../types/inventory';
import type { PersonaResumen } from '../../types/users';
import { EntregaPuestoForm } from './EntregaPuestoForm';
import { nombrePersona } from '../../utils/personas';
import { EntregaResultado } from './EntregaResultado';
import { Dialog } from '../../components/ui/Dialog';

interface Props {
  open: boolean;
  activosIniciales?: Activo[];
  personaInicial?: PersonaResumen | null;
  onClose: (changed: boolean) => void;
}

export const EntregaPuestoDialog = ({ open, activosIniciales, personaInicial, onClose }: Props) => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [hecho, setHecho] = useState<{ res: ResultadoLote; persona: PersonaResumen } | null>(null);
  const [clave, setClave] = useState(0);

  const cerrar = () => {
    const changed = !!hecho;
    setHecho(null);
    onClose(changed);
  };

  return (
    <Dialog open={open} onClose={cerrar} maxWidth="md" fullWidth>
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', fontWeight: 700 }}>
        <span style={{ flex: 1 }}>{hecho ? t('operations.done.title') : t('handover.title')}</span>
        <IconButton onClick={cerrar} aria-label={t('common.close')}><CloseIcon /></IconButton>
      </DialogTitle>
      <DialogContent dividers>
        {hecho ? (
          <EntregaResultado
            resultado={hecho.res}
            tipoActa="entrega"
            mensaje={t('handover.done', { count: hecho.res.total, person: nombrePersona(hecho.persona) })}
            onDeshecho={cerrar}
          />
        ) : open && (
          <EntregaPuestoForm
            key={clave}
            activosIniciales={activosIniciales}
            personaInicial={personaInicial}
            onCancel={cerrar}
            onCompleted={(res, persona) => setHecho({ res, persona })}
          />
        )}
      </DialogContent>
      {hecho && (
        <DialogActions sx={{ px: 3, py: 2, flexWrap: 'wrap', gap: 1 }}>
          <Button onClick={() => { const id = hecho.persona.PER_Persona; cerrar(); navigate(`/empleados/${id}`); }}>
            {t('handover.view_person')}
          </Button>
          <Button onClick={() => { setHecho(null); setClave((k) => k + 1); }}>{t('handover.another')}</Button>
          <Button variant="contained" onClick={cerrar}>{t('common.close')}</Button>
        </DialogActions>
      )}
    </Dialog>
  );
};
