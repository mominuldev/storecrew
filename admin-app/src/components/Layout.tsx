import { NavLink, Outlet } from 'react-router-dom';
import { useTheme } from '../lib/store';
import type { Bootstrap } from '../lib/types';
import { Icon, type IconName } from './Icon';

/**
 * The shell is a rail-and-canvas app: a fixed sidebar on desktop that becomes
 * a horizontal top rail on narrow screens. One set of DOM nodes serves both —
 * the browser test counts exactly one theme toggle, and duplicating the rail
 * per breakpoint would double every control in it.
 *
 * The shell's own five screens are hardcoded deliberately — the shell owns
 * them the way it owns its header. Everything *else* in the nav comes from the
 * capability manifest (G4-D1): contributed routes with `inMenu`, in their
 * declared order, locked ones included — a locked entry leads to the upgrade
 * panel, which is what makes it an invitation rather than a dead link
 * (FR-DIST-10, FR-DIST-12).
 */
const NAV: { to: string; label: string; icon: IconName; end?: boolean }[] = [
  { to: '/', label: 'Overview', icon: 'pulse', end: true },
  { to: '/crew', label: 'Crew', icon: 'users' },
  { to: '/knowledge', label: 'Knowledge', icon: 'book' },
  { to: '/inbox', label: 'Inbox', icon: 'inbox' },
  { to: '/settings', label: 'Settings', icon: 'sliders' },
];

export function Layout({ boot, pending }: { boot: Bootstrap; pending: number }) {
  const { theme, toggle } = useTheme();

  const contributed = boot.routes
    .filter((route) => route.inMenu)
    .sort((a, b) => a.order - b.order)
    .map((route) => ({
      to: route.path,
      label: route.label,
      icon: 'spark' as IconName,
      end: false,
      locked: route.locked,
    }));

  const nav = [...NAV.map((item) => ({ ...item, locked: false })), ...contributed];

  return (
    <div
      className="lg:grid lg:grid-cols-[232px_minmax(0,1fr)]"
      style={{ minHeight: 'calc(100vh - var(--scr-topbar))', background: 'var(--bg)' }}
    >
      {/* The outer div owns the rail's paint for the full grid column — the
          sticky element inside is only viewport-tall, and on a long page the
          column below it would otherwise show the canvas background. */}
      <div
        className="sticky z-20 border-b lg:static lg:border-r lg:border-b-0"
        style={{ top: 'var(--scr-topbar)', background: 'var(--rail)', borderColor: 'var(--line)' }}
      >
      <aside className="flex items-center gap-2 overflow-x-auto px-3 py-2 lg:sticky lg:top-[var(--scr-topbar)] lg:h-[calc(100vh-var(--scr-topbar))] lg:flex-col lg:items-stretch lg:gap-0 lg:overflow-x-visible lg:overflow-y-auto lg:px-3 lg:py-5">
        <div className="flex shrink-0 items-center gap-2.5 pr-2 lg:mb-7 lg:px-2 lg:pr-0">
          <span className="scr-mark">
            <Icon name="spark" size={16} strokeWidth={2.2} />
          </span>
          <div className="hidden leading-tight sm:block">
            <p className="text-[14px] font-bold tracking-tight">StoreCrew</p>
            <p className="scr-label" style={{ fontSize: 9 }}>
              AI crew console
            </p>
          </div>
        </div>

        <nav className="flex items-center gap-1 lg:flex-col lg:items-stretch">
          {nav.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              end={item.end}
              className="shrink-0 whitespace-nowrap rounded-lg px-3 py-2 text-[13px] font-medium transition-colors"
              style={({ isActive }) => ({
                color: isActive ? 'var(--text)' : 'var(--text-dim)',
                background: isActive ? 'var(--tint-signal)' : 'transparent',
              })}
            >
              {({ isActive }) => (
                <span className="flex items-center gap-2.5">
                  <span style={{ color: isActive ? 'var(--fg-signal)' : 'inherit' }}>
                    <Icon name={item.icon} size={16} />
                  </span>
                  {item.label}
                  {item.locked ? (
                    // The tier mark, not a padlock: the destination works — it
                    // shows what the plan adds — so the mark says "paid", not
                    // "forbidden".
                    <span className="scr-label ml-auto" aria-label="On a paid plan">
                      pro
                    </span>
                  ) : null}
                  {'/inbox' === item.to && pending > 0 ? (
                    <span
                      className="scr-num ml-auto rounded-full px-1.5 py-px text-[11px] font-bold"
                      style={{ background: 'var(--color-signal-500)', color: 'var(--color-ink-950)' }}
                    >
                      {pending}
                    </span>
                  ) : null}
                </span>
              )}
            </NavLink>
          ))}
        </nav>

        <button
          onClick={toggle}
          aria-label={`Switch to ${'dark' === theme ? 'light' : 'dark'} theme`}
          className="ml-auto flex shrink-0 items-center gap-2.5 rounded-lg border px-3 py-2 transition-colors hover:bg-[var(--surface-2)] lg:mt-auto lg:ml-0"
          style={{ borderColor: 'var(--line)', color: 'var(--text-dim)' }}
        >
          <Icon name={'dark' === theme ? 'sun' : 'moon'} size={15} />
          <span className="scr-label hidden lg:inline">{'dark' === theme ? 'Light mode' : 'Dark mode'}</span>
        </button>
      </aside>
      </div>

      <main className="min-w-0">
        <div className="mx-auto max-w-[1060px] px-5 py-8 lg:px-10 lg:py-10">
          <Outlet context={boot} />
        </div>
      </main>
    </div>
  );
}
