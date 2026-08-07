import { useQuery } from '@tanstack/react-query';
import { useOutletContext, Link } from 'react-router-dom';
import { api, userName } from '../lib/api';
import type { Bootstrap, Health, Approval } from '../lib/types';
import { CrewBar, crewSummary } from '../components/CrewBar';
import { Gauge } from '../components/Gauge';
import { Icon } from '../components/Icon';
import { Card, IconChip, Label, Section, Spinner, Empty, Button } from '../components/primitives';

const money = (micros: number) => `$${(micros / 1_000_000).toFixed(2)}`;

/**
 * One readable line for a pending action's arguments (G4-D2). The full
 * definition list lives on the Inbox; this row is a teaser, so it reads as
 * words ("Order 421 · Note …"), never as JSON.
 */
const summarise = (args: Record<string, unknown> | null): string =>
  Object.entries(args ?? {})
    .map(([k, v]) => `${k.replace(/_/g, ' ')}: ${typeof v === 'object' && v !== null ? '…' : String(v)}`)
    .join(' · ') || 'No details attached.';

/** A pastel headline tile: what it counts up top, the number writ large, and
 *  a dark round arrow when the tile opens a screen. */
function Tile({
  tile,
  title,
  hint,
  value,
  unit,
  to,
}: {
  tile: string;
  title: string;
  hint: string;
  value: string | number;
  unit?: string;
  to?: string;
}) {
  const body = (
    <div className="scr-tile" style={{ '--tile': tile } as React.CSSProperties}>
      <p className="text-[15px] font-semibold">{title}</p>
      <p className="mt-1 text-[12.5px] leading-snug" style={{ color: 'var(--tile-dim)' }}>
        {hint}
      </p>
      <div className="mt-auto flex items-end justify-between gap-3 pt-4">
        <div className="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2 gap-y-0.5">
          <span className="text-[38px] leading-none font-bold tracking-tight tabular-nums">{value}</span>
          {unit ? (
            <span className="text-[12px] font-medium" style={{ color: 'var(--tile-dim)' }}>
              {unit}
            </span>
          ) : null}
        </div>
        {to ? (
          <span className="scr-tile-go shrink-0" aria-hidden>
            <Icon name="arrowUpRight" size={17} />
          </span>
        ) : null}
      </div>
    </div>
  );

  return to ? <Link to={to}>{body}</Link> : body;
}

export function Overview() {
  const boot = useOutletContext<Bootstrap>();

  const health = useQuery({ queryKey: ['health'], queryFn: () => api.get<Health>('/health'), refetchInterval: 20_000 });
  const approvals = useQuery({ queryKey: ['approvals'], queryFn: () => api.get<Approval[]>('/approvals') });

  if (health.isLoading) return <Spinner label="Reading the board" />;
  if (health.isError) return <Empty icon="alert" title="Could not read the board" hint={(health.error as Error).message} />;

  const h = health.data!;
  const waiting = approvals.data ?? [];
  const crew = crewSummary(boot, h);
  const lockedRoute = boot.routes.find((r) => r.inMenu && r.locked);
  const name = userName();

  const spendPct = h.spend.capMicros ? Math.min(100, (h.spend.spentMicros / h.spend.capMicros) * 100) : null;

  return (
    <>
      <header className="mb-8">
        <h1 className="text-[32px] font-bold tracking-tight">{name ? `Hello ${name},` : 'Hello,'}</h1>
        <p className="mt-1.5 text-[15px]" style={{ color: 'var(--text-dim)' }}>
          Here is what your crew is doing right now.
        </p>
      </header>

      {!boot.onboarding.complete ? (
        <Card className="mb-8 flex flex-wrap items-center gap-4 px-5 py-4">
          <IconChip name="key" tone="signal" />
          <div className="min-w-0 flex-1">
            <p className="text-[13px] font-semibold">
              {!boot.onboarding.hasProvider ? 'No AI provider is connected yet.' : 'Nothing can be indexed yet.'}
            </p>
            <p className="mt-0.5 text-[13px]" style={{ color: 'var(--text-dim)' }}>
              {!boot.onboarding.hasProvider
                ? 'Connect a provider and the crew can start answering.'
                : 'Your connected provider cannot generate embeddings, so the crew has nothing to read. Add OpenAI or Gemini as well.'}
            </p>
          </div>
          <Link to="/settings">
            <Button variant="primary">Open settings</Button>
          </Link>
        </Card>
      ) : null}

      <div className="xl:grid xl:grid-cols-[minmax(0,1fr)_300px] xl:items-start xl:gap-6">
        <div className="min-w-0">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Tile
              tile="var(--tile-peach)"
              title="Knowledge"
              hint="Passages the crew can answer from."
              value={h.index.embedded}
              unit={`of ${h.index.chunks} ready`}
              to="/knowledge"
            />
            <Tile
              tile="var(--tile-blue)"
              title="Spend this month"
              hint="What the crew has cost so far."
              value={money(h.spend.spentMicros)}
              unit={h.spend.capMicros ? `of ${money(h.spend.capMicros)}` : 'no cap'}
              to="/settings"
            />
            <Tile
              tile="var(--tile-gray)"
              title="Background work"
              hint="Indexing and embedding jobs queued."
              value={h.queue.available ? h.queue.pending : '—'}
              unit={h.queue.available ? 'queued' : 'unavailable'}
            />
          </div>

          {lockedRoute ? (
            <Link to={lockedRoute.path}>
              <div
                className="mt-6 flex items-center gap-4 rounded-[22px] px-6 py-5 transition-transform hover:-translate-y-0.5"
                style={{ background: 'var(--chip-bg)', color: 'var(--chip-fg)' }}
              >
                <span
                  className="rounded-full border px-2.5 py-1 text-[10px] font-bold tracking-widest uppercase"
                  style={{ borderColor: 'var(--chip-dim)' }}
                >
                  Pro
                </span>
                <p className="min-w-0 flex-1 text-[15px] font-semibold">
                  More agents are ready to join your crew — see what Pro adds.
                </p>
                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-full" style={{ background: 'var(--chip-fg)', color: 'var(--chip-bg)' }}>
                  <Icon name="arrowUpRight" size={16} />
                </span>
              </div>
            </Link>
          ) : null}

          <div className="mt-8">
            <Section title="The crew">
              <CrewBar boot={boot} health={h} />
            </Section>

            <Section
              title={`Needs you${waiting.length ? ` (${waiting.length})` : ''}`}
              action={waiting.length ? <Link to="/inbox"><Button icon="inbox">Open inbox</Button></Link> : null}
            >
              {waiting.length ? (
                <div className="grid gap-2">
                  {waiting.slice(0, 3).map((item) => (
                    <Link key={item.id} to="/inbox">
                      <Card interactive className="flex items-center gap-3 px-4 py-3">
                        <IconChip name="check" tone="signal" size={30} />
                        <span className="shrink-0 text-[13px] font-semibold">{item.toolId}</span>
                        <span className="truncate text-[12px]" style={{ color: 'var(--text-dim)' }}>
                          {summarise(item.arguments)}
                        </span>
                      </Card>
                    </Link>
                  ))}
                </div>
              ) : (
                <Card className="flex items-center gap-3 px-4 py-3.5">
                  <IconChip name="check" tone="crew" size={30} />
                  <p className="text-[13px]" style={{ color: 'var(--text-dim)' }}>
                    Nothing is waiting on you. The crew handles what it can and asks before anything that changes an order.
                  </p>
                </Card>
              )}
            </Section>
          </div>
        </div>

        <aside className="mt-8 grid gap-4 xl:mt-0">
          {/* The headline chip: the two numbers that say the product is alive. */}
          <div className="rounded-[22px] px-6 py-5" style={{ background: 'var(--chip-bg)', color: 'var(--chip-fg)' }}>
            <div className="flex items-baseline gap-2">
              <span className="text-[34px] leading-none font-bold tracking-tight tabular-nums">
                {crew.onDuty}/{crew.total}
              </span>
              <span className="text-[13px]" style={{ color: 'var(--chip-dim)' }}>
                agents on duty
              </span>
            </div>
            <div className="mt-3 flex items-baseline gap-2">
              <span className="text-[22px] leading-none font-bold tracking-tight tabular-nums">{h.index.embedded}</span>
              <span className="text-[13px]" style={{ color: 'var(--chip-dim)' }}>
                passages ready to answer from
              </span>
            </div>
          </div>

          <Card className="px-5 py-5">
            <div className="flex items-start justify-between gap-2">
              <div>
                <p className="text-[15px] font-semibold">Your spend</p>
                <p className="mt-0.5 text-[12.5px]" style={{ color: 'var(--text-dim)' }}>
                  {h.spend.capMicros ? `Monthly cap: ${money(h.spend.capMicros)}` : 'No monthly cap is set.'}
                </p>
              </div>
            </div>

            {null !== spendPct ? (
              <>
                <div className="mt-4">
                  <Gauge value={spendPct}>
                    <span className="text-[34px] font-bold tracking-tight tabular-nums">
                      {Math.round(spendPct)}
                      <span className="text-[18px]">%</span>
                    </span>
                  </Gauge>
                </div>
                <p className="mt-1 text-center text-[13px]" style={{ color: h.spend.blocked ? 'var(--fg-alert)' : 'var(--text-dim)' }}>
                  {h.spend.blocked
                    ? `Blocked — ${money(h.spend.spentMicros)} used of ${money(h.spend.capMicros!)}`
                    : `${money(h.spend.spentMicros)} used of ${money(h.spend.capMicros!)}`}
                </p>
              </>
            ) : (
              <p className="mt-4 text-[30px] font-bold tracking-tight tabular-nums">{money(h.spend.spentMicros)}</p>
            )}
          </Card>

          <Card edge={h.index.mismatched > 0 ? 'var(--color-alert-500)' : undefined} className="px-5 py-4">
            <div className="flex items-center gap-2.5">
              <IconChip name="chip" tone={h.index.mismatched > 0 ? 'alert' : 'neutral'} size={30} />
              <Label>Index model</Label>
            </div>
            <p className="mt-3 truncate text-[13px] font-semibold">{h.index.model || 'not set'}</p>
            <p className="mt-1 text-[12px]" style={{ color: 'var(--text-dim)' }}>
              {h.index.mismatched > 0
                ? `${h.index.mismatched} chunks were built by a different model and will not match`
                : `${h.index.dimensions} dimensions`}
            </p>
          </Card>

          {!h.encryption.secure ? (
            <Card edge="var(--color-alert-500)" className="flex items-start gap-3 px-5 py-4">
              <IconChip name="alert" tone="alert" size={30} />
              <p className="text-[13px] leading-relaxed">{h.encryption.advice}</p>
            </Card>
          ) : null}
        </aside>
      </div>
    </>
  );
}
