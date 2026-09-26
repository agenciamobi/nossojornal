import { useCallback, useEffect, useRef } from 'react';

const DEFAULT_MESSAGE = 'Existem alterações não salvas. Deseja sair desta página e descartar essas alterações?';

type AdminEditorGuardOptions = {
  dirty: boolean;
  saving?: boolean;
  message?: string;
  onSave?: () => void | Promise<void>;
};

function isPlainLeftClick(event: MouseEvent) {
  return (
    event.button === 0
    && !event.metaKey
    && !event.ctrlKey
    && !event.shiftKey
    && !event.altKey
  );
}

export function useAdminEditorGuard({
  dirty,
  saving = false,
  message = DEFAULT_MESSAGE,
  onSave,
}: AdminEditorGuardOptions) {
  const bypassNextPopState = useRef(false);
  const saveRef = useRef(onSave);

  useEffect(() => {
    saveRef.current = onSave;
  }, [onSave]);

  const confirmNavigation = useCallback(() => {
    if (!dirty || saving) return true;
    return window.confirm(message);
  }, [dirty, message, saving]);

  useEffect(() => {
    if (!dirty || saving) return;

    const handleBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault();
      event.returnValue = '';
    };

    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => window.removeEventListener('beforeunload', handleBeforeUnload);
  }, [dirty, saving]);

  useEffect(() => {
    if (!dirty || saving) return;

    const handleDocumentClick = (event: MouseEvent) => {
      if (!isPlainLeftClick(event) || event.defaultPrevented) return;

      const target = event.target;
      if (!(target instanceof Element)) return;

      const anchor = target.closest('a[href]');
      if (!(anchor instanceof HTMLAnchorElement)) return;
      if (anchor.dataset.allowDirtyNavigation === 'true') return;
      if (anchor.target && anchor.target !== '_self') return;
      if (anchor.hasAttribute('download')) return;

      const destination = new URL(anchor.href, window.location.href);
      if (destination.origin !== window.location.origin) return;
      if (destination.href === window.location.href) return;

      if (!window.confirm(message)) {
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
      }
    };

    document.addEventListener('click', handleDocumentClick, true);
    return () => document.removeEventListener('click', handleDocumentClick, true);
  }, [dirty, message, saving]);

  useEffect(() => {
    if (!dirty || saving) return;

    const handlePopState = () => {
      if (bypassNextPopState.current) {
        bypassNextPopState.current = false;
        return;
      }

      if (window.confirm(message)) return;

      bypassNextPopState.current = true;
      window.history.go(1);
    };

    window.addEventListener('popstate', handlePopState);
    return () => window.removeEventListener('popstate', handlePopState);
  }, [dirty, message, saving]);

  useEffect(() => {
    if (!onSave) return;

    const handleKeyDown = (event: KeyboardEvent) => {
      const saveShortcut = (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's';
      if (!saveShortcut) return;

      event.preventDefault();

      if (!dirty || saving) return;
      void saveRef.current?.();
    };

    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [dirty, onSave, saving]);

  return { confirmNavigation };
}
