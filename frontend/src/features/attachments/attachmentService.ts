import client from '../../api/axios';

export interface Adjunto {
  ADJ_Adjunto: string;
  ADJ_Nombre_Original: string;
  ADJ_Tipo_MIME?: string | null;
  ADJ_Tamano_Bytes: number;
  ADJ_Categoria: string;
  ADJ_Descripcion?: string | null;
  ADJ_Fecha_Subida: string;
  ACT_Activo: string;
  USU_Usuario?: string | null;
}

export const attachmentService = {
  list: async (activoId: string): Promise<Adjunto[]> => {
    const r = await client.get<Adjunto[]>(`/adjuntos/activos/${activoId}`);
    return r.data;
  },

  listOrden: async (ordenId: number): Promise<Adjunto[]> => {
    const r = await client.get<Adjunto[]>(`/adjuntos/ordenes/${ordenId}`);
    return r.data;
  },

  upload: async (
    activoId: string, file: File, categoria: string, descripcion?: string,
  ): Promise<Adjunto> => {
    const form = new FormData();
    form.append('file', file);
    form.append('categoria', categoria);
    if (descripcion) form.append('descripcion', descripcion);
    // El cliente axios NO fija Content-Type global, así el navegador pone
    // `multipart/form-data` con boundary automáticamente para el FormData.
    const r = await client.post<Adjunto>(`/adjuntos/activos/${activoId}`, form);
    return r.data;
  },

  uploadOrden: async (
    ordenId: number, file: File, categoria: string, descripcion?: string,
  ): Promise<Adjunto> => {
    const form = new FormData();
    form.append('file', file);
    form.append('categoria', categoria);
    if (descripcion) form.append('descripcion', descripcion);
    const r = await client.post<Adjunto>(`/adjuntos/ordenes/${ordenId}`, form);
    return r.data;
  },

  // Descarga autenticada: pedimos el blob (el interceptor de axios inyecta el
  // token) y disparamos la descarga en el navegador.
  download: async (adj: Adjunto): Promise<void> => {
    const r = await client.get(`/adjuntos/${adj.ADJ_Adjunto}/download`, { responseType: 'blob' });
    const url = URL.createObjectURL(r.data as Blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = adj.ADJ_Nombre_Original;
    a.click();
    URL.revokeObjectURL(url);
  },

  remove: async (id: string): Promise<void> => {
    await client.delete(`/adjuntos/${id}`);
  },
};
