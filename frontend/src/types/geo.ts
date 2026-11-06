// ==========================================
// 1. PAÍS
// ==========================================
export interface Pais {
  PAI_Pais: number;
  PAI_Nombre: string;
  PAI_Codigo_ISO: string;
}

export interface PaisCreate {
  PAI_Nombre: string;
  PAI_Codigo_ISO: string;
}

// ==========================================
// 2. ESTADO (Departamento/Provincia)
// ==========================================
export interface Estado {
  EST_Estado: number;
  EST_Nombre: string;
  PAI_Pais: number; // FK
}

export interface EstadoCreate {
  EST_Nombre: string;
  PAI_Pais: number;
}

// ==========================================
// 3. MUNICIPIO
// ==========================================
export interface Municipio {
  MUN_Municipio: number;
  MUN_Nombre: string;
  EST_Estado: number; // FK
}

export interface MunicipioCreate {
  MUN_Nombre: string;
  EST_Estado: number;
}

// ==========================================
// 4. SEDE (Campus)
// ==========================================
export interface Sede {
  SED_Sede: number;
  SED_Nombre: string;
  SED_Direccion_Calle?: string | null;
  SED_Direccion_Numero?: string | null;
  MUN_Municipio: number; // FK
}

export interface SedeCreate {
  SED_Nombre: string;
  SED_Direccion_Calle?: string;
  SED_Direccion_Numero?: string;
  MUN_Municipio: number;
}

// ==========================================
// 5. EDIFICIO (Torre)
// ==========================================
export interface Edificio {
  EDI_Edificio: number;
  EDI_Nombre: string;
  SED_Sede: number; // FK
}

export interface EdificioCreate {
  EDI_Nombre: string;
  SED_Sede: number;
}

// ==========================================
// 6. NIVEL (Piso)
// ==========================================
export interface Nivel {
  NIV_Nivel: number;
  NIV_Numero_Piso: string;
  NIV_Alias?: string | null;
  EDI_Edificio: number; // FK
}

export interface NivelCreate {
  NIV_Numero_Piso: string;
  NIV_Alias?: string;
  EDI_Edificio: number;
}

// ==========================================
// 7. ÁREA (Oficina Final)
// ==========================================
export interface Area {
  ARE_Area: number;
  ARE_Nombre: string;
  ARE_Tipo_Acceso: string; // Ej: "General", "Restringido"
  ARE_Descripcion?: string | null;
  NIV_Nivel: number; // FK
}

export interface AreaCreate {
  ARE_Nombre: string;
  ARE_Tipo_Acceso: string;
  ARE_Descripcion?: string;
  NIV_Nivel: number;
}