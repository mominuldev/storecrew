import { useQuery } from '@tanstack/react-query';
import { useOutletContext, Link } from 'react-router-dom';
import { api } from '../lib/api';
import type { Bootstrap, Health, Approval } from '../lib/types';
import { CrewBar } from '../components/CrewBar';
import { Card, IconChip, PageHeader, Section, Stat, StatCard, Spinner, Empty, Button } from '../components/primitives';

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

export function Overview() {
  const boot = useOutletContext<Bootstrap>();

  const health = useQuery({ queryKey: ['health'], queryFn: () => api.get<Health>('/health'), refetchInterval: 20_000 });
  const approvals = useQuery({ queryKey: ['approvals'], queryFn: () => api.get<Approval[]>('/approvals') });

  if (health.isLoading) return <Spinner label="Reading the board" />;
  if (health.isError) return <Empty icon="alert" title="Could not read the board" hint={(health.error as Error).message} />;

  const h = health.data!;
  const waiting = approvals.data ?? [];

  return (
    <>
      <PageHeader title="Overview" sub="What the crew is doing right now, and anything that needs you." />

      <Section title="The crew">
        <CrewBar boot={boot} health={h} />
      </Section>

      {!boot.onboarding.complete ? (
        <Section title="Before the crew can start">
          <Card edge="var(--color-signal-500)" className="flex flex-wrap items-center gap-4 px-5 py-4">
            <IconChip name="key" tone="signal" />
            <div className="min-w-0 flex-1">
              <p className="text-[13px] font-semibold">
                {!boot.onboarding.hasProvider
                  ? 'No AI provider is connected yet.'
                  : 'Nothing can be indexed yet.'}
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
        </Section>
      ) : null}

      <Section
        title={`Needs you${waiting.length ? ` (${waiting.length})` : ''}`}
        action={waiting.length ? <Link to="/inbox"><Button icon="inbox">Open inbox</Button></Link> : null}
      >
        {waiting.length ? (
          <div className="grid gap-2">
            {waiting.slice(0, 3).map((item) => (
              <Link key={item.id} to="/inbox">
                <Card edge="var(--color-signal-500)" interactive className="flex items-center gap-3 px-4 py-3">
                  <IconChip name="check" tone="signal" size={30} />
                  <span className="scr-num shrink-0 text-[13px] font-semibold">{item.toolId}</span>
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

      <Section title="Today">
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <StatCard icon="book" tone="crew" label="Knowledge">
            <Stat value={h.index.embedded} unit={`of ${h.index.chunks} ready`} />
          </StatCard>

          <StatCard icon="coin" tone="signal" label="Spend this month">
            <Stat
              value={money(h.spend.spentMicros)}
              unit={h.spend.capMicros ? `of ${money(h.spend.capMicros)}` : 'no limit set'}
              tone={h.spend.blocked ? 'var(--color-alert-500)' : undefined}
            />
          </StatCard>

          <StatCard icon="layers" label="Background work">
            <Stat value={h.queue.available ? h.queue.pending : '—'} unit={h.queue.available ? 'queued' : 'unavailable'} />
          </StatCard>

          <StatCard
            icon="chip"
            tone={h.index.mismatched > 0 ? 'alert' : undefined}
            label="Index model"
            edge={h.index.mismatched > 0 ? 'var(--color-alert-500)' : undefined}
          >
            <p className="scr-num truncate text-[13px] font-semibold">{h.index.model || 'not set'}</p>
            <p className="mt-1 text-[12px]" style={{ color: 'var(--text-dim)' }}>
              {h.index.mismatched > 0
                ? `${h.index.mismatched} chunks were built by a different model and will not match`
                : `${h.index.dimensions} dimensions`}
            </p>
          </StatCard>
        </div>
      </Section>

      {!h.encryption.secure ? (
        <Section title="Worth fixing">
          <Card edge="var(--color-alert-500)" className="flex items-center gap-3 px-4 py-3.5">
            <IconChip name="alert" tone="alert" size={30} />
            <p className="text-[13px]">{h.encryption.advice}</p>
          </Card>
        </Section>
      ) : null}
    </>
  );
}
