export type TipoAprobacion = 'BAJA' | 'AJUSTE_CONSUMIBLE'
  | 'RECLASIFICACION';
export type EstadoAprobacion = 'PENDIENTE' | 'APROBADA' | 'RECHAZADA' | 'ANULADA';

/** Solicitud que requiere la aprobación dotro administrador de TI (otra persona). */
export interface Aprobacion {
  APR_Aprobacion: number;
  APR_Tipo: TipoAprobacion;
  APR_Estado: EstadoAprobacion;
  APR_Resumen: string;
  APR_Motivo?: string | null;
  APR_Payload: Record<string, unknown>;
  ACT_Activo?: string | null;
  SED_Sede?: number | null;
  APR_Solicitante: string;
  solicitante_nombre?: string | null;
  APR_Fecha_Solicitud: string;
  APR_Aprobador?: string | null;
  aprobador_nombre?: string | null;
  APR_Fecha_Resolucion?: string | null;
  APR_Comentario?: string | null;
  /** La pidió el usuario actual (no puede resolverla; sí anularla). */
  es_mia?: boolean;
}
