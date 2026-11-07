/** Kardex unificado, toma de inventario y reportes de trazabilidad (espejo de app/schemas/kardex.py). */

export type KardexObjeto = 'ACTIVO' | 'CONSUMIBLE' | 'LICENCIA';
export type KardexCategoria = 'ENTRADAS' | 'SALIDAS' | 'ENTRADAS_SALIDAS' | 'REASIGNACIONES';

export const TIPOS_EVENTO = [
  'ALTA', 'RECEPCION', 'ASIGNACION', 'PRESTAMO', 'DEVOLUCION', 'TRANSFERENCIA', 'TRASLADO_SEDE',
  'MANTENIMIENTO_ENTRADA', 'MANTENIMIENTO_SALIDA', 'COMPONENTE_INSTALADO', 'COMPONENTE_RETIRADO',
  'ESPECIFICACION', 'CAMBIO_DATOS', 'CAMBIO_COSTO', 'CAMBIO_ESTADO', 'BAJA', 'INVENTARIO_FISICO',
  'CONSUMIBLE_ENTRADA', 'CONSUMIBLE_SALIDA', 'CONSUMIBLE_AJUSTE', 'CONSUMIBLE_TRASLADO',
  'LICENCIA_ALTA', 'LICENCIA_ASIGNADA', 'LICENCIA_RETIRADA', 'LICENCIA_AJUSTE', 'LICENCIA_BAJA',
] as const;
export type TipoEvento = typeof TIPOS_EVENTO[number];

export interface KardexEvento {
  KDX_Evento: number;
  KDX_Fecha: string;
  KDX_Tipo: TipoEvento;
  KDX_Objeto: KardexObjeto;
  ACT_Activo?: string | null;
  CON_Consumible?: number | null;
  LIC_Licencia?: number | null;
  KDX_Referencia?: string | null;
  KDX_Cantidad?: number | null;
  KDX_Saldo?: number | null;
  KDX_Costo?: string | number | null;
  /** Valor del saldo al costo promedio ponderado. */
  KDX_Saldo_Valor?: string | number | null;
  KDX_Moneda?: string | null;
  KDX_Documento_Tipo?: string | null;
  KDX_Documento_Ref?: string | null;
  KDX_Motivo?: string | null;
  KDX_Detalle?: Record<string, unknown> | null;
  KDX_Migrado: boolean;
  /** Sello encadenado: posición en la cadena y hash SHA-256. */
  KDX_Secuencia?: number | null;
  KDX_Hash?: string | null;
  origen_persona?: string | null;
  origen_area?: string | null;
  origen_sede?: string | null;
  origen_activo?: string | null;
  destino_persona?: string | null;
  destino_area?: string | null;
  destino_sede?: string | null;
  destino_activo?: string | null;
  usuario?: string | null;
}

export interface KardexPagina {
  total: number;
  items: KardexEvento[];
}

export interface KardexFiltros {
  objeto?: KardexObjeto;
  tipos?: TipoEvento[];
  categoria?: KardexCategoria;
  activo_id?: string;
  consumible_id?: number;
  licencia_id?: number;
  persona_id?: string;
  sede_id?: number;
  desde?: string;
  hasta?: string;
  texto?: string;
}

export interface CustodiaItem {
  ACT_Activo: string;
  activo_codigo: string;
  activo_serie: string;
  activo_tipo?: string | null;
  PER_Persona: string;
  persona: string;
  ARE_Area: number;
  area?: string | null;
  SED_Sede?: number | null;
  desde: string;
  tipo?: string | null;
  devolucion_prevista?: string | null;
}

export interface ResumenEntradasSalidas {
  por_tipo: Array<{ tipo: TipoEvento; clase: 'ENTRADA' | 'SALIDA'; eventos: number; cantidad: number;
    valor_por_moneda?: Record<string, number> }>;
}

export type SeveridadAtencion = 'alta' | 'media' | 'baja';
export interface ItemAtencion {
  clave: 'ASIGNADO_SIN_CUSTODIO' | 'ALMACEN_CON_CUSTODIO' | 'PERSONA_INACTIVA_CON_EQUIPOS' | 'PRESTAMO_VENCIDO'
    | 'STOCK_BAJO' | 'INGRESO_POR_CONFIRMAR' | 'ACTIVO_POR_COMPLETAR' | 'BAJA_CON_CUSTODIA' | 'APROBACION_PENDIENTE' | 'TICKET_ANTIGUO' | 'GARANTIA_POR_VENCER' | 'LICENCIA_POR_VENCER' | 'DATOS_INCOMPLETOS'
    | 'CUENTA_POR_CAMBIAR' | 'CUENTA_SIN_ROTAR' | 'CUPOS_AGOTADOS' | 'FALTANTE_PENDIENTE' | 'RESPONSABLE_INACTIVO'
    | 'INSUMOS_POR_RECLASIFICAR';
  severidad: SeveridadAtencion;
  total: number;
  items: Array<Record<string, unknown>>;
  sin_garantia?: number;
  /** CUENTA_SIN_ROTAR: días sin cambiar la clave que disparan el aviso. */
  dias?: number;
  sin_costo?: number;
}

// ---------------- Toma de inventario ----------------
export type ResultadoLectura = 'ENCONTRADO' | 'OTRA_UBICACION' | 'OTRA_SEDE' | 'DE_BAJA' | 'NO_REGISTRADO' | 'REPETIDO';

export interface Toma {
  TOM_Toma: number;
  SED_Sede: number;
  ARE_Area?: number | null;
  TOM_Estado: 'ABIERTA' | 'CERRANDO' | 'CERRADA' | 'ANULADA';
  TOM_Fecha_Inicio: string;
  TOM_Fecha_Cierre?: string | null;
  TOM_Observacion?: string | null;
}

export interface ItemToma { codigo: string; activo?: string | null; area?: number | null }

export interface ResumenToma {
  esperados: number;
  leidos: number;
  encontrados: number;
  avance_pct: number;
  faltantes: ItemToma[];
  otra_ubicacion: ItemToma[];
  otra_sede: ItemToma[];
  de_baja: ItemToma[];
  no_registrados: ItemToma[];
  repetidos: number;
}

export interface Lectura {
  TLE_Lectura: number;
  TLE_Codigo_Leido: string;
  ACT_Activo?: string | null;
  TLE_Resultado: ResultadoLectura;
  ARE_Area?: number | null;
  TLE_Fecha: string;
}

export interface LecturaResultado extends Lectura {
  activo_codigo?: string | null;
  activo_descripcion?: string | null;
  esperados: number;
  leidos: number;
}

export interface TomaDetalle extends Toma {
  resumen: ResumenToma;
  lecturas: Lectura[];
}

// ---------------- Componentes y baja ----------------
export interface ComponenteResumen {
  ACT_Activo: string;
  ACT_Codigo_Interno: string;
  ACT_Serie_Fabricante: string;
  TAC_Tipo_Activo: number;
  MOD_Modelo: number;
}

export interface ComponentesActivo {
  equipo: string;
  instalado_en?: string | null;
  actuales: ComponenteResumen[];
  historial: KardexEvento[];
}

export interface BajaPayload {
  /** Códigos de los catálogos «motivos-baja» y «destinos-baja». */
  motivo: string;
  destino: string;
  fecha?: string;
  documento?: string;
  observacion?: string;
  retirar_componentes?: boolean;
}

export interface DevolucionPayload {
  ACT_Activo: string;
  MOV_Motivo?: string;
  /** Motivo del catálogo (motivos-movimiento) y su detalle escrito. */
  MMO_Motivo?: number | null;
  MOV_Motivo_Detalle?: string | null;
  ARE_Area_Destino?: number | null;
  condicion?: 'BUENO' | 'CON_DANO';
}

export interface DevolucionResultado {
  MOV_Movimiento: string;
  estado: string;
  siguientes_pasos: Array<'ABRIR_TICKET_MANTENIMIENTO' | 'DESCARGAR_HOJA_DESCARGO'>;
}
