import { useEffect, useState } from 'react';
import { Box, Button, DialogActions, DialogContent, DialogTitle, Link, MobileStepper, Typography } from '@mui/material';
import HelpOutlineIcon from '@mui/icons-material/HelpOutline';
import { useTranslation } from 'react-i18next';
import { usePreferencias } from '../../context/preferencias-context';
import { Dialog } from './Dialog';

export type ModuloAyuda = 'assets' | 'movements' | 'software' | 'catalogs' | 'persons' | 'maintenance';
const PASOS = 4;

/**
 * Ayuda contextual: «¿Cómo se hace?» abre un recorrido breve (4 pasos) del
 * módulo. La primera vez que el usuario entra se muestra sola; queda
 * recordada por usuario en el servidor (sirve en cualquier equipo) y se puede
 * apagar para todos los módulos con «No mostrar más guías».
 */
export const PageHelp = ({ modulo }: { modulo: ModuloAyuda }) => {
  const { t } = useTranslation();
  const { mostrarGuia, marcarGuiaVista, desactivarGuias } = usePreferencias();
  const automatica = mostrarGuia(modulo);
  const [open, setOpen] = useState(false);
  const [paso, setPaso] = useState(0);

  useEffect(() => {
    if (automatica) {
      const id = window.setTimeout(() => setOpen(true), 600);
      return () => window.clearTimeout(id);
    }
    return undefined;
  }, [automatica]);

  const cerrar = () => { marcarGuiaVista(modulo); setOpen(false); setPaso(0); };
  const noMostrarMas = () => { desactivarGuias(true); setOpen(false); setPaso(0); };

  return (
    <>
      {/* minHeight 24 px: objetivo táctil mínimo (WCAG 2.5.8). */}
      <Link component="button" type="button" variant="body2" underline="hover" onClick={() => setOpen(true)}
        sx={{ display: 'inline-flex', alignItems: 'center', gap: 0.5, minHeight: 24 }}>
        <HelpOutlineIcon sx={{ fontSize: 16 }} aria-hidden />
        {t('help.how_to')}
      </Link>
      <Dialog open={open} onClose={cerrar} maxWidth="xs" fullWidth aria-labelledby={`ayuda-${modulo}`}>
        <DialogTitle id={`ayuda-${modulo}`}>{t(`help.${modulo}.title`)}</DialogTitle>
        <DialogContent>
          <Box sx={{ minHeight: 96 }} aria-live="polite">
            <Typography variant="subtitle2" gutterBottom>{t(`help.${modulo}.step${paso + 1}_title`)}</Typography>
            <Typography variant="body2" color="text.secondary">{t(`help.${modulo}.step${paso + 1}`)}</Typography>
          </Box>
          <MobileStepper variant="dots" steps={PASOS} position="static" activeStep={paso} sx={{ bgcolor: 'transparent', px: 0 }}
            aria-label={t('help.step_of', { step: paso + 1, total: PASOS })}
            backButton={<Button size="small" disabled={paso === 0} onClick={() => setPaso((p) => p - 1)}>{t('help.back')}</Button>}
            nextButton={paso < PASOS - 1
              ? <Button size="small" onClick={() => setPaso((p) => p + 1)}>{t('help.next')}</Button>
              : <Button size="small" variant="contained" onClick={cerrar}>{t('help.done')}</Button>} />
        </DialogContent>
        <DialogActions sx={{ justifyContent: 'space-between' }}>
          <Button onClick={noMostrarMas} color="inherit" size="small">{t('help.never_show')}</Button>
          <Button onClick={cerrar} color="inherit">{t('help.skip')}</Button>
        </DialogActions>
      </Dialog>
    </>
  );
};
