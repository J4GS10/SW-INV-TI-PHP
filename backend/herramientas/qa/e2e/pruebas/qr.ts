/*
 * Decodificación de QR en las pruebas: zxing-wasm (la misma biblioteca del lector del
 * frontend) con su .wasm local, sin CDN; y PDF a imagen con mupdf para leer las etiquetas.
 */
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { prepareZXingModule, readBarcodes } from 'zxing-wasm/reader';

const requerir = createRequire(import.meta.url);
let listo = false;
function preparar(): void {
  if (listo) return;
  const wasm = readFileSync(requerir.resolve('zxing-wasm/reader/zxing_reader.wasm'));
  prepareZXingModule({ overrides: { wasmBinary: wasm.buffer.slice(wasm.byteOffset, wasm.byteOffset + wasm.byteLength) }, fireImmediately: true });
  listo = true;
}

/** Textos de todos los QR de una imagen (PNG/JPEG). */
export async function leerQr(imagen: Buffer | Uint8Array): Promise<string[]> {
  preparar();
  const r = await readBarcodes(new Uint8Array(imagen), { formats: ['QRCode'], tryHarder: true, maxNumberOfSymbols: 64 });
  return r.filter((c) => c.isValid).map((c) => c.text);
}

/** Páginas de un PDF como PNG (ppp = resolución). */
export async function pdfAPng(pdf: Buffer, ppp = 200): Promise<Buffer[]> {
  const mupdf = await import('mupdf');
  const doc = mupdf.Document.openDocument(new Uint8Array(pdf), 'application/pdf');
  const paginas: Buffer[] = [];
  for (let i = 0; i < doc.countPages(); i++) {
    const escala = ppp / 72;
    const pix = doc.loadPage(i).toPixmap(mupdf.Matrix.scale(escala, escala), mupdf.ColorSpace.DeviceRGB, false, true);
    paginas.push(Buffer.from(pix.asPNG()));
  }
  return paginas;
}

/** Texto de un PDF (para verificar contenido de actas y hojas de vida). */
export async function pdfATexto(pdf: Buffer): Promise<string> {
  const mupdf = await import('mupdf');
  const doc = mupdf.Document.openDocument(new Uint8Array(pdf), 'application/pdf');
  let texto = '';
  for (let i = 0; i < doc.countPages(); i++) texto += doc.loadPage(i).toStructuredText('preserve-whitespace').asText();
  return texto;
}
