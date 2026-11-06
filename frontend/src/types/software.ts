// --- 1. TIPO DE LICENCIA ---
export interface TipoLicencia {
  TLI_Tipo_Licencia: number;
  TLI_Nombre: string;
  TLI_Descripcion?: string | null;
}

export interface TipoLicenciaCreate {
  TLI_Nombre: string;
  TLI_Descripcion?: string;
}

// --- 2. SOFTWARE ---
export interface Software {
  SOF_Software: number;
  SOF_Nombre: string;
  SOF_Version?: string | null;
  SOF_Fabricante: string;
  /** Fabricante como marca del catálogo. */
  MAR_Marca?: number | null;
}

export interface SoftwareCreate {
  SOF_Nombre: string;
  SOF_Version?: string;
  /** Con MAR_Marca el backend fija el texto del fabricante. */
  SOF_Fabricante?: string;
  MAR_Marca?: number | null;
}

// --- 3. LICENCIA (INVENTARIO) ---
export interface Licencia {
  LIC_Licencia: number;
  LIC_Clave_Activacion?: string | null;
  LIC_Fecha_Vencimiento?: string | null;
  LIC_Cantidad_Total: number;
  LIC_Cantidad_Usada: number;
  LIC_Claves_Total?: number;
  LIC_Claves_Disponibles?: number;
  LIC_Claves_Asignadas?: number;
  /** Costo de la licencia y su moneda (GTQ/USD/CHF). */
  LIC_Costo?: string | number | null;
  LIC_Moneda?: string | null;
  
  // FKs y Relaciones
  SOF_Software: number;
  /** Tipo anterior (alias del modelo comercial). */
  TLI_Tipo_Licencia?: number | null;
  MCO_Modelo_Comercial?: number | null;
  MAC_Metodo_Activacion?: number | null;
  FCO_Forma_Conteo?: number | null;
  LIC_Proveedor?: number | null;
  LIC_Fecha_Inicio?: string | null;
  LIC_Renovacion_Automatica?: boolean | null;
  LIC_Notas?: string | null;
  
  // Objetos anidados (si el backend los envía para mostrar nombres en tablas)
  software?: Software;
  tipo_licencia?: TipoLicencia;
}

export interface LicenciaClaveCreate {
  LCL_Clave_Activacion: string;
  LCL_Referencia?: string | null;
}

export interface LicenciaClave {
  LCL_Licencia_Clave: number;
  LIC_Licencia: number;
  LCL_Clave_Activacion: string;
  LCL_Referencia?: string | null;
  LCL_Estado: 'DISPONIBLE' | 'ASIGNADA' | 'RETIRADA';
  LCL_Creado_En?: string | null;
  LCL_Asignada_En?: string | null;
  LCL_Destino_Tipo?: 'ACTIVO' | 'PERSONA' | null;
  LCL_Destino_Id?: string | null;
  LCL_Destino_Nombre?: string | null;
  LCL_Destino_Detalle?: string | null;
}

export interface LicenciaCreate {
  LIC_Clave_Activacion?: string;
  LIC_Fecha_Vencimiento?: string;
  LIC_Cantidad_Total: number;
  SOF_Software: number;
  TLI_Tipo_Licencia?: number | null;
  LIC_Claves?: LicenciaClaveCreate[];
  LIC_Costo?: string | null;
  LIC_Moneda?: string | null;
  MCO_Modelo_Comercial?: number | null;
  MAC_Metodo_Activacion?: number | null;
  FCO_Forma_Conteo?: number | null;
  LIC_Fecha_Inicio?: string | null;
  LIC_Renovacion_Automatica?: boolean;
  LIC_Notas?: string | null;
  LIC_Credenciales?: CredencialIn[];
}

// --- CREDENCIALES / CUPOS (activación variable) ---
/** Campos que puede pedir un método de activación (catálogo «metodos-activacion»). */
export const CAMPOS_CREDENCIAL = ['usuario', 'secreto', 'serial', 'clave', 'servidor', 'archivo', 'dispositivo'] as const;
export type CampoCredencial = typeof CAMPOS_CREDENCIAL[number];

export type CredencialIn = Partial<Record<CampoCredencial, string | null>> & {
  referencia?: string | null;
  vence?: string | null;
};

export interface Credencial {
  LCL_Licencia_Clave: number;
  LIC_Licencia: number;
  referencia: string | null;
  usuario: string | null;
  serial: string | null;
  servidor: string | null;
  dispositivo: string | null;
  archivo: { id: string; nombre: string | null } | null;
  /** Últimos caracteres de la key (nunca la key completa). */
  clave_mascara: string | null;
  tiene_secreto: boolean;
  tiene_clave: boolean;
  vence: string | null;
  estado: 'DISPONIBLE' | 'ASIGNADA' | 'SUSPENDIDA' | 'BAJA' | 'RETIRADA';
  creado: string | null;
  ultimo_cambio_secreto: string | null;
  requiere_cambio: boolean;
  sin_rotar: boolean;
  asignada_en: string | null;
  destino: { tipo: 'ACTIVO' | 'PERSONA'; id: string; nombre: string; detalle?: string | null } | null;
  legada?: boolean;
}

export interface SecretoRevelado {
  secreto: string | null;
  clave: string | null;
  ocultar_en_segundos: number;
}

export interface LicenciaDisponible {
  LIC_Licencia: number;
  SOF_Software: number;
  software: string;
  forma: 'EQUIPO' | 'PERSONA' | 'CONCURRENTE' | 'SITIO' | null;
  metodo: string | null;
  total: number;
  usadas: number;
  libres: number | null;
  vence: string | null;
}

/** Una asignación de la licencia (vigente o retirada). */
export interface HistorialLicencia {
  INS_Instalacion: number;
  destino: 'ACTIVO' | 'PERSONA';
  ACT_Activo?: string | null;
  activo_codigo?: string | null;
  PER_Persona?: string | null;
  persona_nombre?: string | null;
  LCL_Licencia_Clave?: number | null;
  INS_Fecha_Instalacion: string;
  INS_Estado: boolean;
  INS_Fecha_Retiro?: string | null;
  INS_Motivo_Retiro?: string | null;
}

// --- 4. INSTALACIÓN (NUEVO) ---
export interface Instalacion {
  INS_Instalacion: number;
  INS_Fecha_Instalacion: string;
  INS_Estado: boolean;
  ACT_Activo: string; // ID del activo (Vinculación)
  LIC_Licencia: number; // ID de la licencia consumida
  LCL_Licencia_Clave?: number | null;
}

export interface InstalacionCreate {
  /** Destino: un equipo o una persona (exactamente uno, según la forma de conteo). */
  ACT_Activo?: string | null;
  PER_Persona?: string | null;
  LIC_Licencia: number;
  LCL_Licencia_Clave?: number | null;
  INS_Fecha_Instalacion?: string;
}
