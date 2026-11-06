import wasmZxing from 'zxing-wasm/reader/zxing_reader.wasm?url';

interface CodigoDetectado { rawValue: string }

/** Detector de QR con la forma de la API BarcodeDetector del navegador. */
export interface DetectorQr { detect: (fuente: ImageBitmapSource) => Promise<CodigoDetectado[]> }

interface DetectorNativo {
  new (opciones?: { formats?: string[] }): DetectorQr;
  getSupportedFormats?: () => Promise<string[]>;
}

/** Código del activo a partir de lo que trae el QR (texto, "asset:CÓDIGO" o URL). */
export function codigoDeQr(rawValue: string): string {
  const value = rawValue.trim();
  if (!value) return '';
  if (value.toLowerCase().startsWith('asset:')) return value.slice(6).trim();
  try {
    const url = new URL(value);
    return (url.pathname.split('/').filter(Boolean).pop() || value).trim();
  } catch {
    return value;
  }
}

let detector: Promise<DetectorQr> | null = null;

/**
 * Detector de QR para cualquier navegador: el nativo cuando lee QR (Chrome y Edge en
 * Android y macOS) y, si no, ZXing en WebAssembly (Safari de iPhone/iPad, Firefox, Chrome
 * en Windows…). El .wasm lo sirve el propio sitio: no depende de un CDN ni de internet.
 * Se carga una sola vez y solo cuando alguien escanea.
 */
export function detectorQr(): Promise<DetectorQr> {
  detector ??= crearDetector().catch((error: unknown) => {
    detector = null;
    throw error;
  });
  return detector;
}

async function crearDetector(): Promise<DetectorQr> {
  const Nativo = (window as unknown as { BarcodeDetector?: DetectorNativo }).BarcodeDetector;
  try {
    if (Nativo && (await Nativo.getSupportedFormats?.())?.includes('qr_code')) {
      return new Nativo({ formats: ['qr_code'] });
    }
  } catch {
    // Detector nativo presente pero sin soporte real: se usa ZXing.
  }
  const { BarcodeDetector, prepareZXingModule } = await import('barcode-detector/ponyfill');
  prepareZXingModule({
    overrides: { locateFile: (ruta: string, prefijo: string) => (ruta.endsWith('.wasm') ? wasmZxing : prefijo + ruta) },
  });
  return new BarcodeDetector({ formats: ['qr_code'] });
}

/** Cámara en vivo: requiere HTTPS y getUserMedia (no la tienen algunos navegadores internos de apps). */
export function camaraEnVivoDisponible(): boolean {
  return window.isSecureContext && !!navigator.mediaDevices?.getUserMedia;
}

/** Código del QR de una foto (cámara del sistema o galería); '' si la foto no tiene QR legible. */
export async function leerQrDeImagen(archivo: Blob): Promise<string> {
  const d = await detectorQr();
  const imagen = await createImageBitmap(archivo);
  try {
    return codigoDeQr((await d.detect(imagen))[0]?.rawValue ?? '');
  } finally {
    imagen.close();
  }
}
