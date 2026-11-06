import client from '../../api/axios';
import {
  UsuarioCreatePayload,
  UsuarioSistema,
  PersonaResumen,
  PersonaCombo,
  ResumenSeguridad,
  SesionActiva,
  UsuarioUpdatePayload,
} from '../../types/users';

/** Gestión de identidades y accesos (SUPER_ADMIN y ADMIN_SEGURIDAD). */
export const userService = {
  getUsuarios: async (): Promise<UsuarioSistema[]> => {
    const response = await client.get<UsuarioSistema[]>('/org/usuarios');
    return response.data;
  },

  getResumenSeguridad: async (): Promise<ResumenSeguridad> => {
    const response = await client.get<ResumenSeguridad>('/org/usuarios/seguridad/resumen');
    return response.data;
  },

  getAllPersonas: async (): Promise<PersonaCombo[]> => {
    const response = await client.get<PersonaCombo[]>('/org/personas');
    return response.data;
  },

  getPersonasSinUsuario: async (): Promise<PersonaResumen[]> => {
    const response = await client.get<PersonaResumen[]>('/org/personas/disponibles');
    return response.data;
  },

  /** Una contraseña fijada por el administrador es temporal: se cambia en el primer inicio de sesión. */
  createUser: async (data: UsuarioCreatePayload): Promise<void> => {
    await client.post('/org/usuarios', data);
  },

  updateUser: async (id: string, data: UsuarioUpdatePayload): Promise<void> => {
    await client.patch(`/org/usuarios/${id}`, data);
  },

  setEstado: async (id: string, activo: boolean): Promise<void> => {
    if (activo) {
      await client.patch(`/org/usuarios/${id}`, { USU_Estado: true });
    } else {
      await client.delete(`/org/usuarios/${id}`);
    }
  },

  /** Genera una contraseña temporal (se devuelve una sola vez) y cierra sus sesiones. */
  resetPassword: async (id: string): Promise<string> => {
    const response = await client.post<{ temporary_password: string }>(`/org/usuarios/${id}/password/reset`);
    return response.data.temporary_password;
  },

  reset2fa: async (id: string): Promise<void> => {
    await client.post(`/org/usuarios/${id}/2fa/reset`);
  },

  unlock: async (id: string): Promise<void> => {
    await client.post(`/org/usuarios/${id}/unlock`);
  },

  revokeSessions: async (id: string): Promise<void> => {
    await client.post(`/org/usuarios/${id}/sessions/revoke`);
  },

  /** Sesiones activas de todas las cuentas (dispositivo, IP, última actividad). */
  getActiveSessions: async (): Promise<SesionActiva[]> =>
    (await client.get<SesionActiva[]>('/org/usuarios/seguridad/sesiones')).data,

  /** Cierra una sesión concreta de otra cuenta. */
  closeSession: async (userId: string, sessionId: string): Promise<void> => {
    await client.delete(`/org/usuarios/${userId}/sessions/${sessionId}`);
  },
};
