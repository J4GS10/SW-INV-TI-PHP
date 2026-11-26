import { useEffect, useState } from 'react';
import { assetService, type InstalacionDetalle } from '../assets/assetService';

/** Una licencia instalada en uno de los equipos que se devuelven. */
export type SoftwareInstalado = InstalacionDetalle & { codigo: string };

interface Estado { clave: string; items: SoftwareInstalado[]; error: boolean }

/**
 * Licencias instaladas hoy en los equipos que se van a devolver: se muestran para que quien opera decida
 * qué se libera. Mientras carga (o si falla) no se debe devolver, porque no se podría preguntar.
 */
export const useInstalledSoftware = (activos: { ACT_Activo: string; ACT_Codigo_Interno: string }[], open: boolean) => {
  // Equipos y códigos en una clave estable: la lista cambia de identidad en cada render.
  const equipos = open ? activos.map((a) => `${a.ACT_Activo}\t${a.ACT_Codigo_Interno}`).sort().join('\n') : '';
  const [estado, setEstado] = useState<Estado>({ clave: '', items: [], error: false });
  const [intento, setIntento] = useState(0);
  const clave = `${equipos}#${intento}`;

  useEffect(() => {
    if (!equipos) return;
    let vivo = true;
    const lista = equipos.split('\n').map((l) => l.split('\t'));
    Promise.all(lista.map(([id, codigo]) => assetService.getInstalaciones(id, true)
      .then((l) => l.filter((i) => i.INS_Estado).map((i) => ({ ...i, codigo })))))
      .then((grupos) => { if (vivo) setEstado({ clave: `${equipos}#${intento}`, items: grupos.flat(), error: false }); })
      .catch(() => { if (vivo) setEstado({ clave: `${equipos}#${intento}`, items: [], error: true }); });
    return () => { vivo = false; };
  }, [equipos, intento]);

  const listo = !!equipos && estado.clave === clave;
  return {
    items: listo ? estado.items : [],
    loading: !!equipos && !listo,
    error: listo && estado.error,
    retry: () => setIntento((n) => n + 1),
  };
};
