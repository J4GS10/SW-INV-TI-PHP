import client from '../../api/axios';

export interface SystemConfig {
    SYS_Nombre_Empresa: string;
    SYS_Color_Primario: string;
    SYS_Color_Secundario: string;
    SYS_Color_Fondo: string;
    SYS_Logo_URL?: string | null;
    SYS_Idioma_Defecto: string;
    SYS_Codigo_Formulario?: string;
    SYS_Ciudad?: string;
}

export const configService = {
    /**
     * Obtiene la configuración pública del sistema (sin autenticación requerida)
     */
    getConfig: async (): Promise<SystemConfig> => {
        const response = await client.get<SystemConfig>('/gov/config');
        return response.data;
    },

    uploadLogo: async (file: File): Promise<SystemConfig> => {
        const form = new FormData();
        form.append('file', file);
        return (await client.post<SystemConfig>('/gov/config/logo', form)).data;
    },

    getMfaRequirement: async (): Promise<boolean> => {
        const response = await client.get<{ required: boolean }>('/gov/security/mfa');
        return response.data.required;
    },

    setMfaRequirement: async (required: boolean): Promise<boolean> => {
        const response = await client.post<{ required: boolean }>('/gov/security/mfa', { required });
        return response.data.required;
    },

    /**
     * Actualiza la configuración del sistema (requiere rol SUPER_ADMIN)
     */
    updateConfig: async (config: Partial<SystemConfig>): Promise<SystemConfig> => {
        const response = await client.put<SystemConfig>('/gov/config', config);
        return response.data;
    }
};
