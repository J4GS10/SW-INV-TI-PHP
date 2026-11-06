import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App.tsx';
import '@fontsource-variable/manrope';
import './index.css';
import './i18n'; 

// Tras un despliegue, los chunks con hash de la versión anterior ya no
// existen: una pestaña abierta fallaría al navegar a una página lazy. Se
// recarga UNA vez para obtener el index.html nuevo (la marca evita bucles).
window.addEventListener('vite:preloadError', (event) => {
  const key = 'iti_chunk_reload';
  try {
    if (sessionStorage.getItem(key)) return;
    sessionStorage.setItem(key, '1');
  } catch {
    return;
  }
  event.preventDefault();
  window.location.reload();
});
window.addEventListener('load', () => {
  window.setTimeout(() => {
    try { sessionStorage.removeItem('iti_chunk_reload'); } catch { /* sin storage */ }
  }, 10_000);
});

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
);