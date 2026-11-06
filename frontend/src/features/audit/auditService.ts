import client from '../../api/axios';

export interface AuditDiffEntry {
  antes: unknown;
  despues: unknown;
}

export interface AuditSnapshot {
  diff?: Record<string, AuditDiffEntry>;
  [key: string]: unknown;
}

export interface AuditEvent {
  AUD_Auditoria: string;
  AUD_Fecha_Hora: string;
  AUD_Accion: string;
  AUD_Entidad_Afectada: string;
  AUD_Snapshot_JSON: AuditSnapshot | null;
  AUD_IP_Origen: string | null;
  AUD_User_Agent: string | null;
  USU_Usuario: string | null;
  /** Nombre legible de quien hizo el cambio (el servidor lo resuelve). */
  usuario_nombre?: string | null;
}

export interface AuditList {
  total: number;
  items: AuditEvent[];
}

export interface AuditFilters {
  skip?: number;
  limit?: number;
  accion?: string;
  entidad?: string;
  usuario_id?: string;
  from_date?: string;
  to_date?: string;
}

export const auditService = {
  list: async (f: AuditFilters = {}): Promise<AuditList> => {
    const params = new URLSearchParams();
    if (f.skip != null) params.set('skip', String(f.skip));
    if (f.limit != null) params.set('limit', String(f.limit));
    if (f.accion) params.set('accion', f.accion);
    if (f.entidad) params.set('entidad', f.entidad);
    if (f.usuario_id) params.set('usuario_id', f.usuario_id);
    if (f.from_date) params.set('from_date', f.from_date);
    if (f.to_date) params.set('to_date', f.to_date);
    const r = await client.get<AuditList>(`/gov/auditoria?${params.toString()}`);
    return r.data;
  },
};
