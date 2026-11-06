import client from '../../api/axios';
import { Area, Edificio, Estado, Municipio, Nivel, Pais, Sede } from '../../types/geo';

// Tipos genéricos para update
export const locationService = {
  // PAIS
  getPaises: async () => (await client.get<Pais[]>('/geo/paises')).data,
  createPais: async (data: Partial<Pais>) => (await client.post<Pais>('/geo/paises', data)).data,
  updatePais: async (id: number, data: Partial<Pais>) =>
    (await client.patch<Pais>(`/geo/paises/${id}`, data)).data,
  deletePais: async (id: number) => { await client.delete(`/geo/paises/${id}`); },

  // ESTADO
  getEstados: async (paisId: number) =>
    (await client.get<Estado[]>(`/geo/estados?pais_id=${paisId}`)).data,
  createEstado: async (data: Partial<Estado>) =>
    (await client.post<Estado>('/geo/estados', data)).data,
  updateEstado: async (id: number, data: Partial<Estado>) =>
    (await client.patch<Estado>(`/geo/estados/${id}`, data)).data,
  deleteEstado: async (id: number) => { await client.delete(`/geo/estados/${id}`); },

  // MUNICIPIO
  getMunicipios: async (estadoId: number) =>
    (await client.get<Municipio[]>(`/geo/municipios?estado_id=${estadoId}`)).data,
  createMunicipio: async (data: Partial<Municipio>) =>
    (await client.post<Municipio>('/geo/municipios', data)).data,
  updateMunicipio: async (id: number, data: Partial<Municipio>) =>
    (await client.patch<Municipio>(`/geo/municipios/${id}`, data)).data,
  deleteMunicipio: async (id: number) => { await client.delete(`/geo/municipios/${id}`); },

  // SEDE
  getSedes: async (municipioId: number) =>
    (await client.get<Sede[]>(`/geo/sedes?municipio_id=${municipioId}`)).data,
  createSede: async (data: Partial<Sede>) => (await client.post<Sede>('/geo/sedes', data)).data,
  updateSede: async (id: number, data: Partial<Sede>) =>
    (await client.patch<Sede>(`/geo/sedes/${id}`, data)).data,
  deleteSede: async (id: number) => { await client.delete(`/geo/sedes/${id}`); },

  // EDIFICIO
  getEdificios: async (sedeId: number) =>
    (await client.get<Edificio[]>(`/geo/edificios?sede_id=${sedeId}`)).data,
  createEdificio: async (data: Partial<Edificio>) =>
    (await client.post<Edificio>('/geo/edificios', data)).data,
  updateEdificio: async (id: number, data: Partial<Edificio>) =>
    (await client.patch<Edificio>(`/geo/edificios/${id}`, data)).data,
  deleteEdificio: async (id: number) => { await client.delete(`/geo/edificios/${id}`); },

  // NIVEL
  getNiveles: async (edificioId: number) =>
    (await client.get<Nivel[]>(`/geo/niveles?edificio_id=${edificioId}`)).data,
  createNivel: async (data: Partial<Nivel>) =>
    (await client.post<Nivel>('/geo/niveles', data)).data,
  updateNivel: async (id: number, data: Partial<Nivel>) =>
    (await client.patch<Nivel>(`/geo/niveles/${id}`, data)).data,
  deleteNivel: async (id: number) => { await client.delete(`/geo/niveles/${id}`); },

  // AREA
  getAreas: async (nivelId: number) =>
    (await client.get<Area[]>(`/geo/areas?nivel_id=${nivelId}`)).data,
  createArea: async (data: Partial<Area>) => (await client.post<Area>('/geo/areas', data)).data,
  updateArea: async (id: number, data: Partial<Area>) =>
    (await client.patch<Area>(`/geo/areas/${id}`, data)).data,
  deleteArea: async (id: number) => { await client.delete(`/geo/areas/${id}`); },
};
