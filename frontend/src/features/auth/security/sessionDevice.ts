export const isMobile = (device: string | null) => !!device && /Android|iOS/.test(device);

/** El backend describe el dispositivo como "Navegador · Sistema"; se traducen las partes genéricas. */
export const deviceLabel = (device: string | null, t: (key: string) => string) => {
  if (!device) return t('sessions.unknown_device');
  return device.split(' · ')
    .map((part) => (part === 'Otro' ? t('sessions.other') : part === 'Desconocido' ? t('sessions.unknown') : part))
    .join(' · ');
};
