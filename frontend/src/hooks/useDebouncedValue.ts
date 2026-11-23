import { useEffect, useState } from 'react';

/** El valor, pero solo cuando dejó de cambiar durante `ms`: una búsqueda no consulta al servidor en cada tecla. */
export const useDebouncedValue = <T,>(valor: T, ms = 400): T => {
  const [estable, setEstable] = useState(valor);
  useEffect(() => {
    const espera = setTimeout(() => setEstable(valor), ms);
    return () => clearTimeout(espera);
  }, [valor, ms]);

  return estable;
};
