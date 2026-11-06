import { defineConfig, devices } from '@playwright/test';

/*
 * Fase 7: pruebas de punta a punta contra el entorno de QA (herramientas/qa/e2e.sh).
 * Navegador -> frontend -> API -> Oracle/Redis/MinIO/correo, en Chromium, Firefox,
 * WebKit (motor de Safari) y vista móvil (Pixel 7 e iPhone 15).
 *
 * El certificado del sitio de QA lo emite la CA interna de Caddy: se acepta solo
 * aquí (ignoreHTTPSErrors), nunca en un cliente de producción.
 */
const evidencia = process.env.QA_EVIDENCIA ?? 'evidencia';
const comun = {
  baseURL: process.env.QA_URL ?? 'https://qa.inventario.local',
  ignoreHTTPSErrors: true,
  locale: 'es-GT',
  timezoneId: 'America/Guatemala',
  trace: 'retain-on-failure' as const,
  screenshot: 'only-on-failure' as const,
  video: 'retain-on-failure' as const,
  actionTimeout: 15_000,
  navigationTimeout: 30_000,
};

export default defineConfig({
  testDir: './pruebas',
  outputDir: `${evidencia}/resultados`,
  timeout: 120_000,
  expect: { timeout: 15_000 },
  // Las pruebas escriben en la misma base: cada archivo es independiente y usa datos propios.
  fullyParallel: false,
  workers: 3,
  retries: 0,
  reporter: [
    ['list'],
    ['html', { outputFolder: `${evidencia}/informe`, open: 'never' }],
    ['json', { outputFile: `${evidencia}/resultados.json` }],
  ],
  use: comun,
  projects: [
    // API y base de datos: una sola vez (no dependen del navegador).
    { name: 'api', testMatch: /api\/.*\.spec\.ts/, use: { ...comun } },
    { name: 'chromium', testMatch: /ui\/.*\.spec\.ts/, use: { ...devices['Desktop Chrome'], ...comun } },
    { name: 'firefox', testMatch: /ui\/.*\.spec\.ts/, use: { ...devices['Desktop Firefox'], ...comun } },
    { name: 'webkit', testMatch: /ui\/.*\.spec\.ts/, use: { ...devices['Desktop Safari'], ...comun } },
    { name: 'movil-android', testMatch: /ui\/(movil|qr|login).*\.spec\.ts/, use: { ...devices['Pixel 7'], ...comun } },
    { name: 'movil-iphone', testMatch: /ui\/(movil|qr|login).*\.spec\.ts/, use: { ...devices['iPhone 15'], ...comun } },
  ],
});
