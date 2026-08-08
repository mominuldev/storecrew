import { useEffect, useRef } from 'react';
import type { ExtensionScreen } from '../lib/store';

/**
 * Hosts an add-on's DOM-mounted content inside the shell — a whole routed
 * screen or a single Settings tab, same contract either way.
 *
 * The add-on's bundle has no React (deliberately — see lib/store.ts), so the
 * shell owns the element's lifecycle: mount on attach, run the returned
 * cleanup on detach, remount if a different screen object arrives for the
 * same path.
 */
export function ExtensionMount({ screen }: { screen: ExtensionScreen }) {
  const host = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!host.current) return;

    const cleanup = screen.mount(host.current);

    return () => {
      if (cleanup) cleanup();
      if (host.current) host.current.replaceChildren();
    };
  }, [screen]);

  return <div ref={host} />;
}
