import client from '../../api/axios';
import { tokenStorage } from '../../api/tokenStorage';
import { AuthResponse } from '../../types/auth';

export interface TwoFactorChallenge {
  requires_2fa: true;
  method: 'TOTP' | 'EMAIL';
  challenge_token: string;
  /** Days this browser can be remembered after a valid code (0 = not offered). */
  remember_days?: number;
}
export type LoginResult = AuthResponse | TwoFactorChallenge;

export interface TrustedDevice {
  id: number;
  device: string | null;
  ip: string | null;
  created_at: string | null;
  expires_at: string | null;
  last_used_at: string | null;
}

export interface TwoFactorStatus {
  habilitado: boolean;
  metodo: 'TOTP' | 'EMAIL' | null;
  requerido: boolean;
}

export interface SsoProvider {
  provider: 'microsoft' | 'google';
  label: string;
}

export const authService = {
  async getSsoProviders(): Promise<SsoProvider[]> {
    const r = await client.get<{ enabled: boolean; providers: SsoProvider[] }>('/login/sso/providers');
    return r.data.enabled ? r.data.providers : [];
  },

  async getSsoAuthorizationUrl(provider: string, returnTo: string): Promise<string> {
    const r = await client.get<{ authorization_url: string }>(
      `/login/sso/${encodeURIComponent(provider)}/authorize`,
      { params: { return_to: returnTo } },
    );
    return r.data.authorization_url;
  },

  async login(username: string, password: string): Promise<LoginResult> {
    const formData = new URLSearchParams();
    formData.append('username', username);
    formData.append('password', password);

    const response = await client.post<LoginResult>('/login/access-token', formData, {
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    });

    // Si requiere 2FA, NO hay token todavía: el SPA debe pedir el 2º factor.
    return response.data;
  },

  async verify2fa(challengeToken: string, code: string, rememberDevice = false): Promise<AuthResponse> {
    const r = await client.post<AuthResponse>('/login/2fa/verify', {
      challenge_token: challengeToken,
      code,
      remember_device: rememberDevice,
    });
    return r.data;
  },

  /** Skips setting up MFA in the first sign-in (only once); returns a session without the restriction. */
  async skipMfa(): Promise<AuthResponse> {
    return (await client.post<AuthResponse>('/me/2fa/skip', {})).data;
  },

  // --- Remembered devices (MFA code not asked on them) ---
  async trustedDevices(): Promise<TrustedDevice[]> {
    return (await client.get<TrustedDevice[]>('/me/trusted-devices')).data;
  },
  async forgetTrustedDevice(id: number): Promise<void> {
    await client.delete(`/me/trusted-devices/${id}`);
  },
  async forgetAllTrustedDevices(): Promise<void> {
    await client.delete('/me/trusted-devices');
  },

  // --- Gestión de 2FA del usuario autenticado ---
  async get2faStatus(): Promise<TwoFactorStatus> {
    return (await client.get<TwoFactorStatus>('/me/2fa')).data;
  },
  async totpSetup(): Promise<{ secret: string; otpauth_uri: string; qr_data_uri: string }> {
    return (await client.post('/me/2fa/totp/setup')).data;
  },
  async totpActivate(code: string): Promise<{ recovery_codes: string[] }> {
    return (await client.post('/me/2fa/totp/activate', { code })).data;
  },
  async emailSetup(): Promise<void> {
    await client.post('/me/2fa/email/setup');
  },
  async emailActivate(code: string): Promise<{ recovery_codes: string[] }> {
    return (await client.post('/me/2fa/email/activate', { code })).data;
  },
  async disable2fa(password: string): Promise<void> {
    await client.post('/me/2fa/disable', { password });
  },
  /**
   * Exige volver a autenticarse: la contraseña, o un código del segundo factor en una
   * cuenta solo SSO (el servidor toma el campo que corresponde a la cuenta).
   */
  async regenerateRecoveryCodes(secret: string): Promise<{ recovery_codes: string[] }> {
    return (await client.post('/me/2fa/recovery-codes/regenerate', { password: secret, code: secret })).data;
  },

  /**
   * Revoca la sesión en el servidor. La cookie HttpOnly del refresh viaja sola;
   * el access token se pasa explícito porque el llamador limpia la memoria de
   * inmediato (el interceptor ya no lo encontraría). El backend no exige un
   * access vigente: revoca el refresh aunque el access haya expirado.
   */
  async logout(accessToken: string | null = tokenStorage.getAccessToken()): Promise<void> {
    try {
      await client.post('/login/logout', {}, accessToken
        ? { headers: { Authorization: `Bearer ${accessToken}` } }
        : undefined);
    } catch {
      /* best effort: la limpieza local la hace el llamador */
    }
  },

  /** Cierra todas las sesiones del usuario en todos los dispositivos. */
  async logoutAll(): Promise<void> {
    await client.post('/login/logout-all', {});
  },

  async me() {
    const response = await client.get('/me');
    return response.data;
  },

  async changeMyPassword(currentPassword: string, newPassword: string): Promise<void> {
    await client.post('/me/password', {
      current_password: currentPassword,
      new_password: newPassword,
    });
  },

  // Restablecimiento de contraseña (olvidé mi clave). El backend siempre
  // responde igual (anti-enumeración); el token llega al correo del usuario.
  async requestPasswordReset(identifier: string): Promise<void> {
    await client.post('/login/password-reset/request', { identifier });
  },

  async confirmPasswordReset(token: string, newPassword: string): Promise<void> {
    await client.post('/login/password-reset/confirm', { token, new_password: newPassword });
  },
};
