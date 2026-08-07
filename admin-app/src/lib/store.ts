import type { JSX } from 'react';
import { create } from 'zustand';

type Theme = 'light' | 'dark';

const KEY = 'storecrew:theme';

const initial = (): Theme => {
  const saved = localStorage.getItem(KEY);

  if ('light' === saved || 'dark' === saved) {
    return saved;
  }

  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
};

export const useTheme = create<{ theme: Theme; toggle: () => void; apply: () => void }>((set, get) => ({
  theme: initial(),
  toggle: () => {
    const next: Theme = 'dark' === get().theme ? 'light' : 'dark';
    localStorage.setItem(KEY, next);
    set({ theme: next });
    get().apply();
  },
  apply: () => {
    document.documentElement.classList.toggle('dark', 'dark' === get().theme);
  },
}));

/**
 * Client-side registry for routes contributed by add-ons.
 *
 * Premium ships its own bundle and calls `registerScreen()` for each route the
 * server advertised. The shell renders a screen only when the server both
 * declared the route *and* entitled it — the manifest is a rendering hint, and
 * the REST controller behind each screen re-checks entitlement anyway.
 */
type Screen = () => JSX.Element;

const screens = new Map<string, Screen>();

export function registerScreen(path: string, component: Screen): void {
  screens.set(path, component);
}

export function getScreen(path: string): Screen | undefined {
  return screens.get(path);
}

if (typeof window !== 'undefined') {
  (window as unknown as { storecrew: unknown }).storecrew = { registerScreen };
}
