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
 *
 * The contract is a **DOM mount, not a React component**, deliberately: this
 * app bundles its own React (see AdminPage), so an add-on's bundle has no
 * React instance to build elements with — a component contract would force
 * every add-on to ship a second React, the exact thing FR-DIST-12 forbids.
 * `mount(el)` receives an element inside the shell's layout and may return a
 * cleanup function; theme tokens (`--text-dim`, `--surface`, …) are on the
 * page for it to use, so a plain-DOM screen still follows light and dark.
 *
 * The registry is reactive because load order is not guaranteed favourable:
 * an add-on's bundle is enqueued *after* this one and may execute after the
 * first render, and a screen registered late must appear without a refresh.
 */
export type ExtensionScreen = { mount: (el: HTMLElement) => (() => void) | void };

/**
 * A tab contributed to the Settings screen. Same mount contract as a screen,
 * plus the label the tab bar shows — it arrives already translated, because
 * the add-on localises its own strings server-side.
 */
export type ExtensionSettingsTab = ExtensionScreen & { label: string };

const screens = new Map<string, ExtensionScreen>();
const settingsTabs = new Map<string, ExtensionSettingsTab>();
const listeners = new Set<() => void>();
let revision = 0;

function bump(): void {
  revision += 1;
  listeners.forEach((fn) => fn());
}

export function registerScreen(path: string, screen: ExtensionScreen): void {
  screens.set(path, screen);
  bump();
}

export function getScreen(path: string): ExtensionScreen | undefined {
  return screens.get(path);
}

/**
 * Contribute a tab to the Settings screen rather than a whole route. This is
 * where an add-on's configuration belongs — a sidebar entry is for a place the
 * merchant *works*, not a form they visit twice a year.
 */
export function registerSettingsTab(id: string, tab: ExtensionSettingsTab): void {
  settingsTabs.set(id, tab);
  bump();
}

export function settingsTabList(): Array<{ id: string; tab: ExtensionSettingsTab }> {
  return Array.from(settingsTabs, ([id, tab]) => ({ id, tab }));
}

/** For useSyncExternalStore: re-render route tables when a screen arrives. */
export function subscribeScreens(fn: () => void): () => void {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

export function screenRevision(): number {
  return revision;
}

if (typeof window !== 'undefined') {
  (window as unknown as { storecrew: unknown }).storecrew = { registerScreen, registerSettingsTab };
}
