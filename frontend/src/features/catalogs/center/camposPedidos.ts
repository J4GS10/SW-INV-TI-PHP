import type { CatalogoDef } from '../../../api/catalogoService';

/** «MAC_Pide_Usuario» + «MAC_Etiqueta_Usuario»: un dato que el registro pide, con el nombre que ve quien registra. */
export interface CampoPedido {
  /** «usuario», «secreto», «serial»… (clave de texto en `catalog_center.requested.<clave>`). */
  clave: string;
  pide: string;
  etiqueta: string | null;
}

/** Empareja cada interruptor «…_Pide_X» con su texto «…_Etiqueta_X» (el «pide detalle» de un motivo no cuenta). */
export function camposPedidos(def: CatalogoDef): CampoPedido[] {
  return def.extras.filter((e) => e.tipo === 'bool' && /_Pide_/.test(e.columna) && !/_Pide_Detalle$/.test(e.columna)).map((e) => {
    const clave = e.columna.split('_Pide_')[1].toLowerCase();
    const etiqueta = def.extras.find((x) => x.tipo === 'texto' && x.columna === e.columna.replace('_Pide_', '_Etiqueta_'));
    return { clave, pide: e.columna, etiqueta: etiqueta?.columna ?? null };
  });
}

/** Columnas que el diálogo administra (los interruptores y sus etiquetas): no se muestran en la fila. */
export const columnasPedidas = (def: CatalogoDef): Set<string> =>
  new Set(camposPedidos(def).flatMap((c) => [c.pide, ...(c.etiqueta ? [c.etiqueta] : [])]));
