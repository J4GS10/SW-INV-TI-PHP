import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useNavigate } from 'react-router-dom';
import { CommandPalette } from '../components/CommandPalette';
import { ShortcutsDialog } from '../components/ShortcutsDialog';
import { ATAJOS_NAVEGACION } from '../components/atajos';
import { EntregaPuestoDialog } from '../features/operations/EntregaPuestoDialog';
import { DevolucionLoteDialog } from '../features/operations/DevolucionLoteDialog';
import { usePermissions } from '../hooks/usePermissions';
import {
  WorkspaceContext, type AccionPagina, type DevolucionOpciones, type EntregaOpciones, type Reciente, type WorkspaceValue,
} from './workspace-context';
import { useAuth } from './auth-context';

const CLAVE_RECIENTES = 'ws-recientes';
const CLAVE_FAVORITOS = 'ws-favoritos';
const MAX_RECIENTES = 8;

function leer<T>(clave: string, defecto: T): T {
  try {
    const v = window.localStorage.getItem(clave);
    return v ? (JSON.parse(v) as T) : defecto;
  } catch {
    return defecto;
  }
}
function guardar(clave: string, valor: unknown): void {
  try { window.localStorage.setItem(clave, JSON.stringify(valor)); } catch { /* sin storage */ }
}

/** Escribiendo en un campo o con un diálogo abierto, las teclas sueltas no son atajos. */
function enCampoDeTexto(e: KeyboardEvent): boolean {
  const el = e.target as HTMLElement | null;
  if (!el) return false;
  const tag = el.tagName;
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
}
const hayDialogoAbierto = () => !!document.querySelector('.MuiDialog-root');

/**
 * Espacio de trabajo: paleta de comandos, atajos de teclado, recientes,
 * favoritos y las operaciones en lote disponibles desde cualquier pantalla.
 */
export const WorkspaceProvider = ({ children }: { children: ReactNode }) => {
  const navigate = useNavigate();
  const { canOperate } = usePermissions();
  const [paleta, setPaleta] = useState(false);
  const [atajos, setAtajos] = useState(false);
  const [entrega, setEntrega] = useState<EntregaOpciones | null>(null);
  const [devolucion, setDevolucion] = useState<DevolucionOpciones | null>(null);
  // Lo reciente es de cada cuenta: en un equipo compartido no pasa a otro usuario.
  const { user } = useAuth();
  const claveRecientes = `${CLAVE_RECIENTES}:${user?.sub ?? ''}`;
  const claveFavoritos = `${CLAVE_FAVORITOS}:${user?.sub ?? ''}`;
  const [recientes, setRecientes] = useState<Reciente[]>(() => leer(claveRecientes, []));
  const [favoritos, setFavoritos] = useState<string[]>(() => leer(claveFavoritos, []));
  const acciones = useRef(new Map<AccionPagina, () => void>());
  const prefijoG = useRef(0);

  const agregarReciente = useCallback((r: Reciente) => {
    setRecientes((prev) => {
      const next = [r, ...prev.filter((x) => !(x.tipo === r.tipo && x.id === r.id))].slice(0, MAX_RECIENTES);
      guardar(claveRecientes, next);
      return next;
    });
  }, [claveRecientes]);
  const alternarFavorito = useCallback((ruta: string) => {
    setFavoritos((prev) => {
      const next = prev.includes(ruta) ? prev.filter((x) => x !== ruta) : [...prev, ruta];
      guardar(claveFavoritos, next);
      return next;
    });
  }, [claveFavoritos]);
  const registrarAccion = useCallback((accion: AccionPagina, fn: () => void) => {
    acciones.current.set(accion, fn);
    return () => { if (acciones.current.get(accion) === fn) acciones.current.delete(accion); };
  }, []);
  const abrirEntrega = useCallback((o?: EntregaOpciones) => setEntrega(o ?? {}), []);
  const abrirDevolucion = useCallback((o: DevolucionOpciones) => setDevolucion(o), []);
  const abrirRegistro = useCallback((r: Reciente) => { agregarReciente(r); navigate(r.ruta); }, [agregarReciente, navigate]);
  const cerrarPaleta = useCallback(() => setPaleta(false), []);
  const entregaDesdePaleta = useCallback(() => setEntrega({}), []);
  const atajosDesdePaleta = useCallback(() => setAtajos(true), []);

  useEffect(() => {
    const alPulsar = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setPaleta((v) => !v);
        return;
      }
      if (e.ctrlKey || e.metaKey || e.altKey || enCampoDeTexto(e) || hayDialogoAbierto()) return;
      const k = e.key.toLowerCase();
      if (Date.now() - prefijoG.current < 1200 && ATAJOS_NAVEGACION[k]) {
        e.preventDefault();
        prefijoG.current = 0;
        navigate(ATAJOS_NAVEGACION[k]);
        return;
      }
      if (k === 'g') { prefijoG.current = Date.now(); return; }
      if (e.key === '/') { e.preventDefault(); setPaleta(true); return; }
      if (e.key === '?') { e.preventDefault(); setAtajos(true); return; }
      if (k === 'n' && acciones.current.has('nuevo')) { e.preventDefault(); acciones.current.get('nuevo')?.(); return; }
      if (k === 'e' && canOperate) { e.preventDefault(); setEntrega({}); }
    };
    window.addEventListener('keydown', alPulsar);
    return () => window.removeEventListener('keydown', alPulsar);
  }, [navigate, canOperate]);

  const valor = useMemo<WorkspaceValue>(() => ({
    abrirPaleta: () => setPaleta(true),
    abrirAtajos: () => setAtajos(true),
    abrirEntrega, abrirDevolucion, recientes, agregarReciente, favoritos, alternarFavorito, registrarAccion,
  }), [abrirEntrega, abrirDevolucion, recientes, agregarReciente, favoritos, alternarFavorito, registrarAccion]);

  return (
    <WorkspaceContext.Provider value={valor}>
      {children}
      <CommandPalette open={paleta} onClose={cerrarPaleta} recientes={recientes} favoritos={favoritos}
        onEntrega={entregaDesdePaleta} onAtajos={atajosDesdePaleta} onAbrirRegistro={abrirRegistro} />
      <ShortcutsDialog open={atajos} onClose={() => setAtajos(false)} />
      <EntregaPuestoDialog open={!!entrega} activosIniciales={entrega?.activos} personaInicial={entrega?.persona}
        onClose={(changed) => { const fin = entrega?.onDone; setEntrega(null); if (changed) fin?.(); }} />
      <DevolucionLoteDialog open={!!devolucion} activos={devolucion?.activos ?? []}
        onClose={(changed) => { const fin = devolucion?.onDone; setDevolucion(null); if (changed) fin?.(); }} />
    </WorkspaceContext.Provider>
  );
};
