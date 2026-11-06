import { createContext, useContext } from 'react';

export interface ConfirmOptions {
  title?: string;
  message: string;
  confirmText?: string;
  cancelText?: string;
  /** Acción destructiva (eliminar, anular): botón en color de error. */
  destructive?: boolean;
}

export interface ConfirmContextValue {
  /** Devuelve una promesa que resuelve a `true` si el usuario confirma. */
  confirm: (opts: ConfirmOptions) => Promise<boolean>;
}

export const ConfirmContext = createContext<ConfirmContextValue | null>(null);

/** Diálogo de confirmación del sistema (reemplaza a window.confirm). */
export const useConfirm = () => {
  const ctx = useContext(ConfirmContext);
  if (!ctx) throw new Error('useConfirm debe usarse dentro de ConfirmProvider');
  return ctx.confirm;
};
