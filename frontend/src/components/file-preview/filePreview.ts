/** Cómo se muestra un archivo dentro del sistema, sin descargarlo. */
export type PreviewKind = 'pdf' | 'image' | 'text' | 'csv' | 'none';

/** Un archivo que el visor puede mostrar: se pide recién al abrirlo. */
export interface PreviewFile {
  name: string;
  mime?: string | null;
  /** Línea bajo el nombre: categoría, tamaño, fecha… */
  subtitle?: string;
  load: () => Promise<Blob>;
}

const POR_EXTENSION: Record<string, PreviewKind> = {
  pdf: 'pdf', png: 'image', jpg: 'image', jpeg: 'image', webp: 'image', gif: 'image',
  txt: 'text', log: 'text', json: 'text', csv: 'csv',
};
// Solo imágenes de mapa de bits: un SVG puede llevar código y no se muestra.
const IMAGENES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];

export const extensionOf = (name: string): string => {
  const i = name.lastIndexOf('.');
  return i > 0 ? name.slice(i + 1).toLowerCase() : '';
};

/** Qué visor usar, por el tipo que manda el servidor o, si no viene (o es genérico), por la extensión. */
export const previewKind = (name: string, mime?: string | null): PreviewKind => {
  const m = (mime ?? '').toLowerCase().split(';')[0].trim();
  if (m === 'application/pdf') return 'pdf';
  if (IMAGENES.includes(m)) return 'image';
  if (m === 'text/csv') return 'csv';
  if (m === 'text/plain' || m === 'application/json') return extensionOf(name) === 'csv' ? 'csv' : 'text';
  // Otro tipo conocido (Word, Excel, SVG…): no se muestra aunque la extensión diga otra cosa.
  if (m && m !== 'application/octet-stream') return 'none';
  return POR_EXTENSION[extensionOf(name)] ?? 'none';
};

export const formatBytes = (bytes: number): string => {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

/**
 * Filas de un CSV (comillas dobles; separador «,» o «;», el que más aparezca en la primera línea).
 * Devuelve a lo sumo `maxRows` filas y si quedaron más.
 */
export const parseCsv = (text: string, maxRows = 200): { rows: string[][]; truncated: boolean } => {
  const limpio = text.charCodeAt(0) === 0xfeff ? text.slice(1) : text; // sin la marca BOM de Excel
  const fin = limpio.indexOf('\n');
  const primera = fin < 0 ? limpio : limpio.slice(0, fin);
  const sep = (primera.match(/;/g)?.length ?? 0) > (primera.match(/,/g)?.length ?? 0) ? ';' : ',';
  const rows: string[][] = [];
  let fila: string[] = [];
  let celda = '';
  let comillas = false;
  for (let i = 0; i < limpio.length; i++) {
    const c = limpio[i];
    if (comillas) {
      if (c === '"' && limpio[i + 1] === '"') { celda += '"'; i++; }
      else if (c === '"') comillas = false;
      else celda += c;
    } else if (c === '"') comillas = true;
    else if (c === sep) { fila.push(celda); celda = ''; }
    else if (c === '\n' || c === '\r') {
      if (c === '\r' && limpio[i + 1] === '\n') i++;
      fila.push(celda); celda = '';
      rows.push(fila); fila = [];
      if (rows.length > maxRows) return { rows: rows.slice(0, maxRows), truncated: true };
    } else celda += c;
  }
  if (celda !== '' || fila.length) { fila.push(celda); rows.push(fila); }
  return { rows: rows.slice(0, maxRows), truncated: rows.length > maxRows };
};
