import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, NavLink, Outlet, useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import { useTheme } from '../lib/store';
import type { Bootstrap, Health } from '../lib/types';
import { Icon, type IconName } from './Icon';

const money = (micros: number) => `$${(micros / 1_000_000).toFixed(2)}`;

/**
 * The shell is a rail-and-canvas app: a white sidebar on desktop that becomes
 * a horizontal top rail on narrow screens, and a top bar carrying the one
 * global action the console really has — asking the knowledge base what a
 * customer would ask. One set of DOM nodes serves both breakpoints; the
 * browser test counts exactly one theme toggle, and duplicating the rail per
 * breakpoint would double every control in it.
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

function NavItem({
  item,
  pending,
}: {
  item: { to: string; label: string; icon: IconName; end?: boolean; locked: boolean };
  pending: number;
}) {
  return (
    <NavLink
      to={item.to}
      end={item.end}
      className="shrink-0 whitespace-nowrap rounded-xl px-3 py-2 text-[13px] font-medium transition-colors"
      style={({ isActive }) => ({
        color: isActive ? 'var(--text)' : 'var(--text-dim)',
        background: isActive ? 'var(--surface-2)' : 'transparent',
        border: `1px solid ${isActive ? 'var(--line)' : 'transparent'}`,
      })}
    >
      {({ isActive }) => (
        <span className="flex items-center gap-2.5">
          <span style={{ color: isActive ? 'var(--fg-accent)' : 'inherit' }}>
            <Icon name={item.icon} size={16} />
          </span>
          {item.label}
          {item.locked ? (
            // The tier mark, not a padlock: the destination works — it shows
            // what the plan adds — so the mark says "paid", not "forbidden".
            <span className="scr-label ml-auto" aria-label="On a paid plan">
              pro
            </span>
          ) : null}
          {'/inbox' === item.to && pending > 0 ? (
            <span
              className="ml-auto rounded-full px-1.5 py-px text-[11px] font-bold tabular-nums"
              style={{ background: 'var(--color-accent-500)', color: '#fff' }}
            >
              {pending}
            </span>
          ) : null}
        </span>
      )}
    </NavLink>
  );
}

/** Spend against the monthly cap, at the foot of the rail — the reference's
 *  plan meter, carrying the number StoreCrew actually has. */
function SpendMeter() {
  const health = useQuery({ queryKey: ['health'], queryFn: () => api.get<Health>('/health') });
  const spend = health.data?.spend;

  if (!spend) return null;

  const pct = spend.capMicros ? Math.min(100, (spend.spentMicros / spend.capMicros) * 100) : null;

  return (
    <div className="hidden rounded-2xl border p-3.5 lg:block" style={{ borderColor: 'var(--line)' }}>
      <div className="flex items-center justify-between gap-2">
        <span className="scr-label">Spend this month</span>
        <span className="text-[12px] font-semibold tabular-nums" style={spend.blocked ? { color: 'var(--fg-alert)' } : undefined}>
          {money(spend.spentMicros)}
        </span>
      </div>
      {null !== pct ? (
        <>
          <div className="mt-2.5 h-1.5 w-full overflow-hidden rounded-full" style={{ background: 'var(--surface-2)' }}>
            <div
              className="h-full rounded-full"
              style={{ width: `${pct}%`, background: spend.blocked ? 'var(--color-alert-500)' : 'var(--color-accent-500)' }}
            />
          </div>
          <p className="mt-2 text-[11px]" style={{ color: 'var(--text-dim)' }}>
            of a {money(spend.capMicros!)} monthly cap
          </p>
        </>
      ) : (
        <p className="mt-2 text-[11px]" style={{ color: 'var(--text-dim)' }}>
          No monthly cap is set.
        </p>
      )}
    </div>
  );
}

export function Layout({ boot, pending }: { boot: Bootstrap; pending: number }) {
  const { theme, toggle } = useTheme();
  const navigate = useNavigate();
  const [q, setQ] = useState('');

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

  const main = NAV.map((item) => ({ ...item, locked: false }));

  return (
    <div
      className="lg:grid lg:grid-cols-[248px_minmax(0,1fr)]"
      style={{ minHeight: 'calc(100vh - var(--scr-topbar))', background: 'var(--bg)' }}
    >
      {/* The outer div owns the rail's paint for the full grid column — the
          sticky element inside is only viewport-tall, and on a long page the
          column below it would otherwise show the canvas background. */}
      <div
        className="sticky z-20 border-b lg:static lg:border-r lg:border-b-0"
        style={{ top: 'var(--scr-topbar)', background: 'var(--rail)', borderColor: 'var(--line)' }}
      >
        <aside className="flex items-center gap-2 overflow-x-auto px-3 py-2 lg:sticky lg:top-[var(--scr-topbar)] lg:h-[calc(100vh-var(--scr-topbar))] lg:flex-col lg:items-stretch lg:gap-0 lg:overflow-x-visible lg:overflow-y-auto lg:px-4 lg:py-6">
          <div className="flex shrink-0 items-center gap-3 pr-2 lg:mb-8 lg:px-1 lg:pr-0">
            <span className="scr-mark">
              <Icon name="spark" size={17} strokeWidth={2.2} />
            </span>
            <div className="hidden leading-tight sm:block">
              <p className="text-[15px] font-bold tracking-tight">StoreCrew</p>
              <p className="scr-label" style={{ fontSize: 9 }}>
                AI crew console
              </p>
            </div>
          </div>

          <nav className="flex items-center gap-1 lg:flex-col lg:items-stretch">
            {/* A sixth destination, and only while it has something to say.
                The ≤15-minute time-to-value target is spent mostly on finding
                the next thing to do, and a merchant who navigates away from
                the Overview card otherwise has nothing to navigate back to. */}
            {!boot.onboarding.complete ? (
              <>
                <span className="scr-label hidden lg:mb-2 lg:block lg:px-3">Get started</span>
                <NavItem key="/setup" item={{ to: '/setup', label: 'Finish setup', icon: 'spark', locked: false }} pending={0} />
                <span className="scr-label hidden lg:mt-6 lg:mb-2 lg:block lg:px-3">Console</span>
              </>
            ) : (
              <span className="scr-label hidden lg:mb-2 lg:block lg:px-3">Console</span>
            )}
            {main.map((item) => (
              <NavItem key={item.to} item={item} pending={pending} />
            ))}

            {contributed.length ? (
              <>
                <span className="scr-label hidden lg:mt-6 lg:mb-2 lg:block lg:px-3">Add-ons</span>
                {contributed.map((item) => (
                  <NavItem key={item.to} item={item} pending={pending} />
                ))}
              </>
            ) : null}
          </nav>

          <div className="ml-auto lg:mt-auto lg:ml-0">
            <SpendMeter />
          </div>
        </aside>
      </div>

      <main className="min-w-0">
        <div className="mx-auto max-w-[1180px] px-5 py-5 lg:px-8 lg:py-6">
          <div className="mb-7 flex items-center gap-3">
            {/* The search runs real retrieval: it lands on Knowledge and asks
                the index exactly what an agent would. */}
            <form
              className="min-w-0 flex-1"
              onSubmit={(e) => {
                e.preventDefault();
                if (q.trim()) {
                  navigate(`/knowledge?q=${encodeURIComponent(q.trim())}`);
                  setQ('');
                }
              }}
            >
              <div className="relative max-w-xl">
                <span className="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2" style={{ color: 'var(--text-dim)' }}>
                  <Icon name="search" size={15} />
                </span>
                <input
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                  placeholder="Ask what a customer would ask…"
                  className="scr-input"
                  style={{ paddingLeft: 38, borderRadius: 14, background: 'var(--rail)' }}
                  aria-label="Search the knowledge base"
                />
              </div>
            </form>

            <button
              onClick={toggle}
              aria-label={`Switch to ${'dark' === theme ? 'light' : 'dark'} theme`}
              className="grid h-10 w-10 shrink-0 place-items-center rounded-full border transition-colors hover:bg-[var(--surface-2)]"
              style={{ borderColor: 'var(--line)', background: 'var(--rail)', color: 'var(--text-dim)' }}
            >
              <Icon name={'dark' === theme ? 'sun' : 'moon'} size={16} />
            </button>

            <Link
              to="/inbox"
              className="relative grid h-10 w-10 shrink-0 place-items-center rounded-full border transition-colors hover:bg-[var(--surface-2)]"
              style={{ borderColor: 'var(--line)', background: 'var(--rail)', color: 'var(--text-dim)' }}
              aria-label={pending > 0 ? `Inbox, ${pending} waiting` : 'Inbox'}
            >
              <Icon name="bell" size={16} />
              {pending > 0 ? (
                <span
                  className="absolute -top-0.5 -right-0.5 grid h-4 min-w-4 place-items-center rounded-full px-1 text-[10px] font-bold tabular-nums"
                  style={{ background: 'var(--color-accent-500)', color: '#fff' }}
                >
                  {pending}
                </span>
              ) : null}
            </Link>
          </div>

          <Outlet context={boot} />
        </div>
      </main>
    </div>
  );
}
