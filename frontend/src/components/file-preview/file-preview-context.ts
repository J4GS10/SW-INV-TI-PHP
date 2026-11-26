import { createContext, useContext } from 'react';
import { saveBlob } from '../../api/downloadCsv';
import type { PreviewFile } from './filePreview';

export interface FilePreviewContextValue {
  /** Abre el visor sobre una lista de archivos (con flechas para pasar de uno a otro). */
  preview: (files: PreviewFile[], index?: number) => void;
  /** Muestra un documento ya generado (acta, etiquetas…); lo que el navegador no puede mostrar se descarga. */
  showBlob: (blob: Blob, name: string) => void;
}

export const FilePreviewContext = createContext<FilePreviewContextValue | null>(null);

// Fuera del proveedor (pruebas, pantallas sueltas) se conserva la descarga de siempre.
const sinVisor: FilePreviewContextValue = {
  preview: (files, index = 0) => {
    const f = files[index];
    if (f) void f.load().then((b) => saveBlob(b, f.name));
  },
  showBlob: (blob, name) => saveBlob(blob, name),
};

/** Visor de archivos del sistema: PDF, imágenes, texto y CSV se ven sin descargarlos. */
export const useFilePreview = (): FilePreviewContextValue => useContext(FilePreviewContext) ?? sinVisor;
