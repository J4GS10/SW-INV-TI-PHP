import { createContext, useContext, useEffect } from 'react';

export interface OperatorCodeContextValue {
  /** Mientras una pantalla de escaneo está abierta, también a TI y técnicos se les pide su código al guardar. */
  enterScannerMode: () => () => void;
}

export const OperatorCodeContext = createContext<OperatorCodeContextValue | null>(null);

/** La pantalla que lo usa pide el código de operador antes de cada cambio (asignar, devolver, transferir…). */
export const useScannerMode = (): void => {
  const ctx = useContext(OperatorCodeContext);
  useEffect(() => (ctx ? ctx.enterScannerMode() : undefined), [ctx]);
};
