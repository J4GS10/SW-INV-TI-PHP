import { useEffect } from 'react';

/**
 * Warns before leaving a page with work in progress: closing the tab, or following a link of the application.
 * Navigation by code (after saving, for example) is not intercepted.
 */
export const useUnsavedChanges = (dirty: boolean, message: string): void => {
  useEffect(() => {
    if (!dirty) return undefined;
    const onBeforeUnload = (event: BeforeUnloadEvent) => { event.preventDefault(); };
    const onClick = (event: MouseEvent) => {
      const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
      if (link && !link.hasAttribute('download') && !window.confirm(message)) {
        event.preventDefault();
        event.stopPropagation();
      }
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    document.addEventListener('click', onClick, true);
    return () => {
      window.removeEventListener('beforeunload', onBeforeUnload);
      document.removeEventListener('click', onClick, true);
    };
  }, [dirty, message]);
};
