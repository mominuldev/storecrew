import { NavLink, Outlet } from 'react-router-dom';
import { useTheme } from '../lib/store';
import type { Bootstrap } from '../lib/types';
import { Label } from './primitives';

/**
 * The shell's own five screens. Hardcoded deliberately — the shell owns them
 * the way it owns its header. Everything *else* in the nav comes from the
 * capability manifest (G4-D1): contributed routes with `inMenu`, in their
 * declared order, locked ones included — a locked entry leads to the upgrade
 * panel, which is what makes it an invitation rather than a dead link
 * (FR-DIST-10, FR-DIST-12).
 */
const NAV = [
  { to: '/', label: 'Overview', end: true },
  { to: '/crew', label: 'Crew' },
  { to: '/knowledge', label: 'Knowledge' },
  { to: '/inbox', label: 'Inbox' },
  { to: '/settings', label: 'Settings' },
];

export function Layout({ boot, pending }: { boot: Bootstrap; pending: number }) {
  const { theme, toggle } = useTheme();

  const contributed = boot.routes
    .filter((route) => route.inMenu)
    .sort((a, b) => a.order - b.order)
    .map((route) => ({ to: route.path, label: route.label, end: false, locked: route.locked }));

  const nav = [...NAV.map((item) => ({ ...item, locked: false })), ...contributed];

  return (
    <div className="min-h-screen" style={{ background: 'var(--bg)' }}>
      <header
        className="sticky top-0 z-20 border-b px-5 py-3"
        style={{ background: 'var(--rail)', borderColor: 'var(--line)' }}
      >
        <div className="flex items-center gap-4">
          <div className="flex items-center gap-2.5">
            <span
              className="inline-block h-4 w-1 rounded-sm"
              style={{ background: 'var(--color-signal-500)' }}
              aria-hidden
            />
            <span className="text-[13px] font-semibold tracking-tight">StoreCrew</span>
          </div>

          <nav className="ml-4 flex flex-wrap items-center gap-1">
            {nav.map((item) => (
              <NavLink
                key={item.to}
                to={item.to}
                end={item.end}
                className={({ isActive }) =>
                  `rounded-md px-2.5 py-1.5 text-[13px] transition-colors ${
                    isActive ? 'font-semibold' : 'hover:opacity-100'
                  }`
                }
                style={({ isActive }) => ({
                  color: isActive ? 'var(--text)' : 'var(--text-dim)',
                  background: isActive ? 'var(--bg)' : 'transparent',
                })}
              >
                {item.label}
                {item.locked ? (
                  // The tier mark, not a padlock: the destination works — it
                  // shows what the plan adds — so the mark says "paid", not
                  // "forbidden".
                  <span className="scr-label ml-1.5" aria-label="On a paid plan" style={{ color: 'var(--text-dim)' }}>
                    pro
                  </span>
                ) : null}
                {'/inbox' === item.to && pending > 0 ? (
                  <span
                    className="scr-num ml-1.5 rounded px-1.5 py-0.5 text-[11px] font-semibold"
                    style={{ background: 'var(--color-signal-500)', color: 'var(--color-ink-950)' }}
                  >
                    {pending}
                  </span>
                ) : null}
              </NavLink>
            ))}
          </nav>

          <button
            onClick={toggle}
            className="ml-auto rounded-md border px-2.5 py-1.5"
            style={{ borderColor: 'var(--line)' }}
            aria-label={`Switch to ${'dark' === theme ? 'light' : 'dark'} theme`}
          >
            <Label>{'dark' === theme ? 'Dark' : 'Light'}</Label>
          </button>
        </div>
      </header>

      <main className="mx-auto max-w-6xl px-5 py-8">
        <Outlet context={boot} />
      </main>
    </div>
  );
}
