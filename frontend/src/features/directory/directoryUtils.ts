import { isAxiosError } from 'axios';
import type { TFunction } from 'i18next';

export const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export const isValidEmail = (value: string): boolean => EMAIL_RE.test(value.trim());

/** true si el backend respondió 403 (rol sin permiso para el endpoint). */
export const isForbidden = (error: unknown): boolean =>
  isAxiosError(error) && error.response?.status === 403;

/** Etiqueta traducida del origen de un destinatario ('grupo:<nombre>' incluye el nombre del grupo). */
export const recipientOriginLabel = (origen: string, t: TFunction): string => {
  if (origen.startsWith('grupo:')) {
    return t('directory.origin.group', { name: origen.slice('grupo:'.length) });
  }
  return t(`directory.origin.${origen}`, origen);
};

export const recipientOriginColor = (
  origen: string,
): 'primary' | 'secondary' | 'info' | 'warning' | 'default' => {
  if (origen.startsWith('grupo:')) return 'info';
  switch (origen) {
    case 'afectado':
      return 'primary';
    case 'jefe':
      return 'secondary';
    case 'admins':
      return 'warning';
    default:
      return 'default';
  }
};
