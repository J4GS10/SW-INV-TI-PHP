/**
 * Un dato se anota en un solo lugar: si «Memoria RAM» es una pieza interna (con su serie y su capacidad),
 * no se pide además como característica del equipo. Estas reglas detectan ese doble registro.
 */
const clave = (nombre: string): string =>
  nombre.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();

/** Ids de las características cuyo nombre coincide con el de una pieza interna del tipo. */
export const specsDuplicatedByComponents = (
  specIds: number[], specNames: ReadonlyMap<number, string>, componentNames: string[],
): number[] => {
  const piezas = new Set(componentNames.map(clave));
  return specIds.filter((id) => piezas.has(clave(specNames.get(id) ?? '')));
};
