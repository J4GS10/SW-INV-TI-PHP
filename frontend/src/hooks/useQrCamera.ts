import { useCallback, useEffect, useRef, useState } from 'react';
import { camaraEnVivoDisponible, codigoDeQr, detectorQr, leerQrDeImagen } from '../utils/lectorQr';

export type EstadoCamara = 'idle' | 'starting' | 'active' | 'unsupported' | 'denied';

/** Pausa entre lecturas del video: suficiente para leer al vuelo sin recalentar un celular. */
const INTERVALO_MS = 120;

/**
 * Lectura de QR en cualquier dispositivo: cámara en vivo (trasera por defecto, con cambio
 * entre cámaras) o foto. Un lector USB/Bluetooth escribe en el campo de la pantalla como
 * un teclado y no pasa por aquí. `onCode` recibe cada código distinto; un mismo código
 * seguido se ignora durante `pausaMs` para no registrarlo dos veces al sostener el equipo.
 */
export function useQrCamera(onCode: (codigo: string) => void | Promise<void>, pausaMs = 2500) {
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const timerRef = useRef<number | null>(null);
  const activoRef = useRef(false);
  const ultimoRef = useRef<{ codigo: string; en: number }>({ codigo: '', en: 0 });
  const onCodeRef = useRef(onCode);
  const [estado, setEstado] = useState<EstadoCamara>('idle');
  const [camaras, setCamaras] = useState<MediaDeviceInfo[]>([]);
  const [camaraActual, setCamaraActual] = useState<string | null>(null);

  useEffect(() => { onCodeRef.current = onCode; }, [onCode]);

  const liberar = useCallback(() => {
    activoRef.current = false;
    if (timerRef.current !== null) window.clearTimeout(timerRef.current);
    timerRef.current = null;
    streamRef.current?.getTracks().forEach((track) => track.stop());
    streamRef.current = null;
    if (videoRef.current) videoRef.current.srcObject = null;
  }, []);

  const detener = useCallback(() => {
    liberar();
    setEstado((e) => (e === 'active' || e === 'starting' ? 'idle' : e));
  }, [liberar]);

  const arrancar = useCallback(async (deviceId?: string) => {
    liberar();
    if (!camaraEnVivoDisponible()) {
      setEstado('unsupported');
      return;
    }
    setEstado('starting');
    // En paralelo: el permiso de la cámara no espera a que cargue el lector.
    const [lector, video] = await Promise.allSettled([
      detectorQr(),
      navigator.mediaDevices.getUserMedia({
        video: deviceId ? { deviceId: { exact: deviceId } } : { facingMode: { ideal: 'environment' } },
        audio: false,
      }),
    ]);
    if (lector.status === 'rejected' || video.status === 'rejected') {
      if (video.status === 'fulfilled') video.value.getTracks().forEach((track) => track.stop());
      setEstado(video.status === 'rejected' ? 'denied' : 'unsupported');
      return;
    }
    const stream = video.value;
    streamRef.current = stream;
    try {
      if (videoRef.current) {
        videoRef.current.srcObject = stream;
        // Algunos navegadores tardan en resolver play(); la lectura espera a que haya cuadros.
        await Promise.race([videoRef.current.play(), new Promise((r) => { window.setTimeout(r, 3000); })]);
      }
    } catch {
      liberar();
      setEstado('denied');
      return;
    }
    setCamaraActual(stream.getVideoTracks()[0]?.getSettings().deviceId ?? null);
    // Los nombres de las cámaras solo se conocen después de dar el permiso.
    navigator.mediaDevices.enumerateDevices()
      .then((d) => setCamaras(d.filter((x) => x.kind === 'videoinput')))
      .catch(() => setCamaras([]));
    activoRef.current = true;
    setEstado('active');
    const tick = async () => {
      const v = videoRef.current;
      if (!activoRef.current || !v) return;
      if (v.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
        try {
          const raw = (await lector.value.detect(v))[0]?.rawValue;
          const codigo = raw ? codigoDeQr(raw) : '';
          const ahora = Date.now();
          if (codigo && (codigo !== ultimoRef.current.codigo || ahora - ultimoRef.current.en > pausaMs)) {
            ultimoRef.current = { codigo, en: ahora };
            await onCodeRef.current(codigo);
          }
        } catch {
          // Un cuadro intermedio puede fallar; el siguiente reintenta.
        }
      }
      if (activoRef.current) timerRef.current = window.setTimeout(tick, INTERVALO_MS);
    };
    void tick();
  }, [liberar, pausaMs]);

  const iniciar = useCallback(() => arrancar(), [arrancar]);

  /** Pasa a la siguiente cámara del equipo (trasera, frontal, externa…). */
  const cambiarCamara = useCallback(() => {
    if (camaras.length < 2) return Promise.resolve();
    const i = camaras.findIndex((c) => c.deviceId === camaraActual);
    return arrancar(camaras[(i + 1) % camaras.length].deviceId);
  }, [arrancar, camaraActual, camaras]);

  /** Lee el QR de una foto; false si no se encontró ninguno. */
  const leerFoto = useCallback(async (archivo: Blob): Promise<boolean> => {
    const codigo = await leerQrDeImagen(archivo);
    if (!codigo) return false;
    ultimoRef.current = { codigo, en: Date.now() };
    await onCodeRef.current(codigo);
    return true;
  }, []);

  useEffect(() => liberar, [liberar]);

  return { videoRef, estado, iniciar, detener, camaras, cambiarCamara, leerFoto };
}
