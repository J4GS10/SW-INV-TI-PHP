import client from '../../api/axios';
import type {
  AdGroup,
  AdTestResult,
  ConnectionTestResult,
  DirectoryStatus,
  IntegrationOut,
  IntegrationUpdate,
  MailTestResult,
  NotificationRule,
  NotificationRuleInput,
  RecipientPreview,
  SyncResult,
} from './types';

export const directoryService = {
  /** Estado de la integración con Active Directory (SUPER_ADMIN / ADMIN_TI). */
  getStatus: async (): Promise<DirectoryStatus> => {
    const r = await client.get<DirectoryStatus>('/directorio/estado');
    return r.data;
  },

  /** Prueba de conexión/bind contra AD (SUPER_ADMIN). */
  testConnection: async (): Promise<ConnectionTestResult> => {
    const r = await client.post<ConnectionTestResult>('/directorio/probar');
    return r.data;
  },

  /** Ejecuta (o simula con dryRun) la sincronización de personas desde AD. */
  sync: async (dryRun: boolean, forzarBajas = false): Promise<SyncResult> => {
    // La sincronización puede tardar con directorios grandes.
    const r = await client.post<SyncResult>('/directorio/sincronizar', null, {
      params: { dry_run: dryRun, forzar_bajas: forzarBajas },
      timeout: 300_000, // igual que proxy_read_timeout de nginx para este endpoint
    });
    return r.data;
  },

  /** Búsqueda de grupos de AD para autocompletar. Devuelve [] si AD está deshabilitado. */
  searchGroups: async (q: string, signal?: AbortSignal): Promise<AdGroup[]> => {
    const r = await client.get<AdGroup[]>('/directorio/grupos', { params: { q }, signal });
    return r.data;
  },

  listRules: async (): Promise<NotificationRule[]> => {
    const r = await client.get<NotificationRule[]>('/directorio/notificaciones/reglas');
    return r.data;
  },

  updateRule: async (evento: string, data: NotificationRuleInput): Promise<NotificationRule> => {
    const r = await client.put<NotificationRule>(
      `/directorio/notificaciones/reglas/${encodeURIComponent(evento)}`,
      data,
    );
    return r.data;
  },

  /** Elimina la personalización y restaura los valores por defecto del evento. */
  resetRule: async (evento: string): Promise<void> => {
    await client.delete(`/directorio/notificaciones/reglas/${encodeURIComponent(evento)}`);
  },

  previewRecipients: async (evento: string, personaId?: string | null): Promise<RecipientPreview> => {
    const r = await client.get<RecipientPreview>(
      `/directorio/notificaciones/vista-previa/${encodeURIComponent(evento)}`,
      { params: personaId ? { persona_id: personaId } : undefined },
    );
    return r.data;
  },

  /* ── Cuenta de servicio / integración (SUPER_ADMIN) ── */

  getIntegration: async (): Promise<IntegrationOut> => {
    const r = await client.get<IntegrationOut>('/directorio/integracion');
    return r.data;
  },

  /** Secretos omitidos/null = conservar el guardado; "" = borrarlo. */
  updateIntegration: async (data: IntegrationUpdate): Promise<IntegrationOut> => {
    const r = await client.put<IntegrationOut>('/directorio/integracion', data);
    return r.data;
  },

  /** Envía un correo de prueba real con los valores del formulario (sin guardar). */
  testIntegrationMail: async (config: IntegrationUpdate, destinatario: string): Promise<MailTestResult> => {
    const r = await client.post<MailTestResult>(
      '/directorio/integracion/probar-correo',
      { config, destinatario },
      { timeout: 60_000 },
    );
    return r.data;
  },

  /** Prueba el bind contra AD con los valores del formulario (sin guardar). */
  testIntegrationAd: async (config: IntegrationUpdate): Promise<AdTestResult> => {
    const r = await client.post<AdTestResult>('/directorio/integracion/probar-ad', { config }, { timeout: 60_000 });
    return r.data;
  },

  /** Limpia los bloqueos (circuit breakers) tras un fallo de autenticación. */
  unlockIntegration: async (): Promise<void> => {
    await client.post('/directorio/integracion/desbloquear');
  },
};
