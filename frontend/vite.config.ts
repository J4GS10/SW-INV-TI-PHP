import { readFileSync } from 'node:fs'
import { defineConfig, type Plugin } from 'vite'
import react from '@vitejs/plugin-react'

/**
 * El lector QR (src/utils/lectorQr.ts) sirve el .wasm de `zxing-wasm` y lo carga con el JS
 * de `barcode-detector`, que fija su propia versión exacta de zxing-wasm. Si no coinciden,
 * el módulo falla en cada cuadro («Failed to allocate … bytes in WASM memory») y la cámara y
 * «Leer desde foto» dejan de leer donde no hay detector nativo (iPhone, Firefox, Windows).
 * La compilación se detiene si las versiones se separan.
 */
function zxingAlineado(): Plugin {
  const version = (paquete: string) => JSON.parse(readFileSync(`node_modules/${paquete}/package.json`, 'utf8'))
  return {
    name: 'zxing-alineado',
    buildStart() {
      const servida = version('zxing-wasm').version as string
      const requerida = version('barcode-detector').dependencies['zxing-wasm'] as string
      if (servida !== requerida) {
        throw new Error(`zxing-wasm ${servida} no coincide con la que usa barcode-detector (${requerida}): fije "zxing-wasm": "${requerida}" en package.json.`)
      }
    },
  }
}

function vendorChunk(id: string): string | undefined {
  if (id.includes('commonjsHelpers')) return 'vendor-react'
  if (!id.includes('node_modules')) return undefined

  const normalized = id.split('\\').join('/')

  // Lector QR de respaldo (ZXing): queda en su propio fragmento y solo se descarga al escanear.
  if (normalized.includes('/node_modules/barcode-detector/') || normalized.includes('/node_modules/zxing-wasm/')) {
    return undefined
  }

  if (
    normalized.includes('/node_modules/react/') ||
    normalized.includes('/node_modules/react-dom/') ||
    normalized.includes('/node_modules/react-router') ||
    normalized.includes('/node_modules/scheduler/') ||
    normalized.includes('/node_modules/jwt-decode/')
  ) {
    return 'vendor-react'
  }

  if (
    normalized.includes('/node_modules/@mui/icons-material/')
  ) {
    return 'vendor-mui-icons'
  }

  if (
    normalized.includes('/node_modules/@mui/') ||
    normalized.includes('/node_modules/@emotion/')
  ) {
    return 'vendor-mui'
  }

  if (
    normalized.includes('/node_modules/i18next') ||
    normalized.includes('/node_modules/react-i18next/')
  ) {
    return 'vendor-i18n'
  }

  if (
    normalized.includes('/node_modules/react-hook-form/') ||
    normalized.includes('/node_modules/@hookform/') ||
    normalized.includes('/node_modules/zod/')
  ) {
    return 'vendor-forms'
  }

  if (
    normalized.includes('/node_modules/axios/') ||
    normalized.includes('/node_modules/notistack/') ||
    normalized.includes('/node_modules/zustand/')
  ) {
    return 'vendor-core'
  }

  return 'vendor'
}

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react(), zxingAlineado()],
  build: {
    rollupOptions: {
      output: {
        manualChunks: vendorChunk,
      },
    },
  },
})
