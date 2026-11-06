import { useRef, useState } from 'react';
import { Button, CircularProgress } from '@mui/material';
import PhotoCameraIcon from '@mui/icons-material/PhotoCamera';
import { useTranslation } from 'react-i18next';
import { useSnackbar } from 'notistack';

interface Props {
  /** Lee el QR de la foto; false si no tiene ninguno legible. */
  leerFoto: (archivo: Blob) => Promise<boolean>;
  disabled?: boolean;
}

/**
 * Lectura de QR desde una foto: abre la cámara del sistema en el celular (o la galería) y
 * funciona aunque el navegador no permita la cámara en vivo (sitio sin HTTPS de confianza,
 * navegadores internos de otras apps).
 */
export function BotonFotoQr({ leerFoto, disabled }: Props) {
  const { t } = useTranslation();
  const { enqueueSnackbar } = useSnackbar();
  const inputRef = useRef<HTMLInputElement | null>(null);
  const [leyendo, setLeyendo] = useState(false);

  const alElegir = async (archivo: File | undefined) => {
    if (!archivo) return;
    setLeyendo(true);
    try {
      if (!(await leerFoto(archivo))) enqueueSnackbar(t('scanner.photo_none'), { variant: 'warning' });
    } catch {
      enqueueSnackbar(t('scanner.photo_error'), { variant: 'error' });
    } finally {
      setLeyendo(false);
      if (inputRef.current) inputRef.current.value = '';
    }
  };

  return (
    <>
      <input ref={inputRef} type="file" accept="image/*" capture="environment" hidden
        onChange={(e) => void alElegir(e.target.files?.[0])} />
      <Button variant="outlined" startIcon={leyendo ? <CircularProgress size={16} /> : <PhotoCameraIcon />}
        disabled={disabled || leyendo} onClick={() => inputRef.current?.click()}>
        {t('scanner.photo')}
      </Button>
    </>
  );
}
