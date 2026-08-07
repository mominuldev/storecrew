import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib/api';
import type { IndexStatus, SearchResult } from '../lib/types';
import { Icon } from '../components/Icon';
import { Button, Card, Empty, Label, PageHeader, Section, Spinner, Stat, StatCard, Problem } from '../components/primitives';

/**
 * Knowledge is where a merchant answers "why did it say that?".
 *
 * The search box is the important half: it runs retrieval exactly as an agent
 * would and shows the passages that would ground the answer. Without it, a bad
 * answer is unfalsifiable — there is no way to tell a retrieval problem from a
 * prompt problem.
 */
export function Knowledge() {
  const qc = useQueryClient();
  const [query, setQuery] = useState('');
  const [result, setResult] = useState<SearchResult | null>(null);

  const status = useQuery({
    queryKey: ['index'],
    queryFn: () => api.get<IndexStatus>('/index'),
    refetchInterval: (q) => ((q.state.data as IndexStatus | undefined)?.active ? 3_000 : false),
  });

  const start = useMutation({
    mutationFn: () => api.post('/index/start'),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['index'] }),
  });

  const search = useMutation({
    mutationFn: (q: string) => api.post<SearchResult>('/knowledge/search', { query: q, limit: 5 }),
    onSuccess: setResult,
  });

  if (status.isLoading) return <Spinner label="Reading the index" />;

  const s = status.data!;
  const active = s.active;

  return (
    <>
      <PageHeader title="Knowledge" sub="What the crew has read, and the passages it would answer from." />

      <Section
        title="What the crew has read"
        action={
          <Button variant="primary" icon="refresh" onClick={() => start.mutate()} disabled={start.isPending || !!active?.alive}>
            {active?.alive ? 'Reading…' : 'Read everything again'}
          </Button>
        }
      >
        {start.isError ? <div className="mb-3"><Problem message={(start.error as Error).message} /></div> : null}

        <div className="grid gap-3 sm:grid-cols-3">
          <StatCard icon="book" tone="crew" label="Ready to search">
            <Stat value={s.health.embedded} unit={`of ${s.health.chunks} passages`} />
          </StatCard>
          <StatCard icon="store" tone="signal" label="Products">
            <Stat value={s.sources.product ?? 0} unit="in the catalogue" />
          </StatCard>
          <StatCard icon="layers" label="Pages">
            <Stat value={s.sources.post ?? 0} unit="policies and guides" />
          </StatCard>
        </div>

        {active ? (
          <Card edge={active.alive ? 'var(--color-signal-500)' : 'var(--color-alert-500)'} className="mt-3 px-4 py-3.5">
            <div className="flex items-center justify-between gap-3">
              <p className="text-[13px]">
                {active.alive
                  ? `Reading — ${active.processed} of ${active.total} done`
                  : 'The last read stopped without finishing. Starting again picks up where it left off.'}
              </p>
              <span className="scr-num text-[13px] font-semibold">
                {active.total ? Math.round((active.processed / active.total) * 100) : 0}%
              </span>
            </div>
            <div className="mt-2.5 h-1.5 w-full overflow-hidden rounded-full" style={{ background: 'var(--surface-2)' }}>
              <div
                className="h-full rounded-full transition-[width] duration-500"
                style={{
                  width: `${active.total ? Math.min(100, (active.processed / active.total) * 100) : 0}%`,
                  background: active.alive ? 'var(--color-signal-500)' : 'var(--color-alert-500)',
                }}
              />
            </div>
          </Card>
        ) : null}
      </Section>

      <Section title="Try a question">
        <Card className="px-5 py-5">
          <p className="mb-3 text-[13px]" style={{ color: 'var(--text-dim)' }}>
            Ask what a customer would ask. You will see the exact passages the crew would answer from.
          </p>
          <form
            className="flex flex-wrap gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              if (query.trim()) search.mutate(query.trim());
            }}
          >
            <div className="relative min-w-0 flex-1">
              <span
                className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2"
                style={{ color: 'var(--text-dim)' }}
              >
                <Icon name="search" size={15} />
              </span>
              <input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Do you have anything warm for winter?"
                className="scr-input"
                style={{ paddingLeft: 36 }}
              />
            </div>
            <Button type="submit" variant="primary" disabled={search.isPending}>
              {search.isPending ? 'Looking…' : 'Search'}
            </Button>
          </form>
        </Card>

        {search.isError ? <div className="mt-3"><Problem message={(search.error as Error).message} /></div> : null}

        {result ? (
          <div className="mt-4">
            <div className="mb-2.5 flex flex-wrap items-center gap-x-4 gap-y-1">
              <Label>{result.strategy.replace('_', ' ')}</Label>
              <Label>{result.candidates} considered</Label>
              {result.truncated ? <Label>results were cut short</Label> : null}
            </div>

            {result.degraded ? <div className="mb-2"><Problem message={result.degraded} /></div> : null}

            {result.results.length ? (
              <div className="grid gap-2">
                {result.results.map((r) => (
                  <Card key={r.id} className="px-4 py-3.5">
                    <div className="flex items-baseline justify-between gap-3">
                      <p className="truncate text-[13px] font-semibold">{r.sourceTitle || 'Untitled'}</p>
                      <span
                        className="scr-num shrink-0 rounded-md px-1.5 py-0.5 text-[11px] font-semibold"
                        style={{ background: 'var(--tint-crew)', color: 'var(--fg-crew)' }}
                      >
                        {r.score.toFixed(3)}
                      </span>
                    </div>
                    <p className="mt-1.5 line-clamp-3 text-[12px] leading-relaxed" style={{ color: 'var(--text-dim)' }}>
                      {r.content}
                    </p>
                  </Card>
                ))}
              </div>
            ) : (
              <Empty
                icon="search"
                title="Nothing matched"
                hint="The crew would have to say it does not know. Either the answer is not published anywhere, or it has not been read yet."
              />
            )}
          </div>
        ) : null}
      </Section>
    </>
  );
}
