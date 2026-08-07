import { useQuery } from '@tanstack/react-query';
import { useOutletContext, Link } from 'react-router-dom';
import { api } from '../lib/api';
import type { Bootstrap, Health, Approval } from '../lib/types';
import { CrewBar } from '../components/CrewBar';
import { Card, Label, Section, Stat, Spinner, Empty, Button } from '../components/primitives';

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
  if (health.isError) return <Empty title="Could not read the board" hint={(health.error as Error).message} />;

  const h = health.data!;
  const waiting = approvals.data ?? [];

  return (
    <>
      <Section title="The crew">
        <CrewBar boot={boot} health={h} />
      </Section>

      {!boot.onboarding.complete ? (
        <Section title="Before the crew can start">
          <Card edge="var(--color-signal-500)" className="px-4 py-4">
            <p className="text-[13px] font-medium">
              {!boot.onboarding.hasProvider
                ? 'No AI provider is connected yet.'
                : 'Nothing can be indexed yet.'}
            </p>
            <p className="mt-1 text-[13px]" style={{ color: 'var(--text-dim)' }}>
              {!boot.onboarding.hasProvider
                ? 'Connect a provider and the crew can start answering.'
                : 'Your connected provider cannot generate embeddings, so the crew has nothing to read. Add OpenAI or Gemini as well.'}
            </p>
            <div className="mt-3">
              <Link to="/settings">
                <Button variant="primary">Open settings</Button>
              </Link>
            </div>
          </Card>
        </Section>
      ) : null}

      <Section
        title={`Needs you${waiting.length ? ` (${waiting.length})` : ''}`}
        action={waiting.length ? <Link to="/inbox"><Button>Open inbox</Button></Link> : null}
      >
        {waiting.length ? (
          <div className="grid gap-2">
            {waiting.slice(0, 3).map((item) => (
              <Card key={item.id} edge="var(--color-signal-500)" className="flex items-center gap-3 px-4 py-3">
                <span className="scr-num text-[13px] font-semibold">{item.toolId}</span>
                <span className="truncate text-[12px]" style={{ color: 'var(--text-dim)' }}>
                  {summarise(item.arguments)}
                </span>
              </Card>
            ))}
          </div>
        ) : (
          <Card className="px-4 py-3.5">
            <p className="text-[13px]" style={{ color: 'var(--text-dim)' }}>
              Nothing is waiting on you. The crew handles what it can and asks before anything that changes an order.
            </p>
          </Card>
        )}
      </Section>

      <Section title="Today">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Card className="px-4 py-4">
            <Label>Knowledge</Label>
            <div className="mt-2">
              <Stat value={h.index.embedded} unit={`of ${h.index.chunks} ready`} />
            </div>
          </Card>

          <Card className="px-4 py-4">
            <Label>Spend this month</Label>
            <div className="mt-2">
              <Stat
                value={money(h.spend.spentMicros)}
                unit={h.spend.capMicros ? `of ${money(h.spend.capMicros)}` : 'no limit set'}
                tone={h.spend.blocked ? 'var(--color-alert-500)' : undefined}
              />
            </div>
          </Card>

          <Card className="px-4 py-4">
            <Label>Background work</Label>
            <div className="mt-2">
              <Stat value={h.queue.available ? h.queue.pending : '—'} unit={h.queue.available ? 'queued' : 'unavailable'} />
            </div>
          </Card>

          <Card
            className="px-4 py-4"
            edge={h.index.mismatched > 0 ? 'var(--color-alert-500)' : undefined}
          >
            <Label>Index model</Label>
            <p className="scr-num mt-2 truncate text-[13px]">{h.index.model || 'not set'}</p>
            <p className="mt-1 text-[12px]" style={{ color: 'var(--text-dim)' }}>
              {h.index.mismatched > 0
                ? `${h.index.mismatched} chunks were built by a different model and will not match`
                : `${h.index.dimensions} dimensions`}
            </p>
          </Card>
        </div>
      </Section>

      {!h.encryption.secure ? (
        <Section title="Worth fixing">
          <Card edge="var(--color-alert-500)" className="px-4 py-3.5">
            <p className="text-[13px]">{h.encryption.advice}</p>
          </Card>
        </Section>
      ) : null}
    </>
  );
}
