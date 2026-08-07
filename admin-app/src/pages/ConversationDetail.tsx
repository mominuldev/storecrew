import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { api } from '../lib/api';
import type { Conversation } from '../lib/types';
import { Button, Card, Label, Section, Spinner, Problem } from '../components/primitives';

/**
 * Why did it say that?
 *
 * The transcript alone never answers it. What does is the run underneath each
 * reply: which passages were retrieved, which tools ran, what they returned.
 */
export function ConversationDetail() {
  const { uuid } = useParams();

  const q = useQuery({
    queryKey: ['conversation', uuid],
    queryFn: () => api.get<Conversation>(`/conversations/${uuid}`),
  });

  if (q.isLoading) return <Spinner label="Loading conversation" />;
  if (q.isError) return <Problem message={(q.error as Error).message} />;

  const c = q.data!;

  return (
    <>
      <div className="mb-6 flex items-center gap-3">
        <Link to="/crew"><Button>Back</Button></Link>
        <Label>{c.status} · {c.channel} · {c.identityVerified ? `identified on order ${c.verifiedOrderId}` : 'not identified'}</Label>
      </div>

      <Section title="Transcript">
        <div className="grid gap-2">
          {c.turns.map((t, i) => (
            <Card
              key={i}
              edge={'user' === t.role ? 'var(--line)' : 'var(--color-signal-500)'}
              className="px-4 py-3"
            >
              <Label>{'user' === t.role ? 'Customer' : t.agentId || 'Crew'}</Label>
              <p className="mt-1.5 whitespace-pre-wrap text-[13px]">{t.content}</p>
            </Card>
          ))}
          {c.turns.length ? null : (
            <p className="text-[13px]" style={{ color: 'var(--text-dim)' }}>No messages recorded.</p>
          )}
        </div>
      </Section>

      <Section title="What happened underneath">
        <div className="grid gap-2">
          {c.runs.map((run) => (
            <Card
              key={run.id}
              edge={'completed' === run.status ? undefined : 'var(--color-alert-500)'}
              className="px-4 py-3.5"
            >
              <div className="flex flex-wrap items-baseline justify-between gap-3">
                <p className="text-[13px] font-semibold">{run.agentId}</p>
                <span className="scr-num text-[12px]" style={{ color: 'var(--text-dim)' }}>
                  {run.model} · {run.tokensIn}/{run.tokensOut} tok · {run.latencyMs}ms
                </span>
              </div>

              {'completed' !== run.status ? (
                <Label className="mt-1.5">{run.status}{run.errorCode ? ` · ${run.errorCode}` : ''}</Label>
              ) : null}

              {run.retrieved?.length ? (
                <div className="mt-2.5">
                  <Label>Read {run.retrieved.length} passages</Label>
                  <div className="mt-1 flex flex-wrap gap-1.5">
                    {run.retrieved.map((r) => (
                      <span key={r.id} className="scr-num rounded px-1.5 py-0.5 text-[11px]" style={{ background: 'var(--bg)' }}>
                        #{r.id} · {r.score.toFixed(2)}
                      </span>
                    ))}
                  </div>
                </div>
              ) : null}

              {run.toolCalls.length ? (
                <div className="mt-2.5 grid gap-1.5">
                  {run.toolCalls.map((call) => (
                    <div key={call.id} className="rounded p-2" style={{ background: 'var(--bg)' }}>
                      <div className="flex items-baseline justify-between gap-2">
                        <span className="scr-num text-[12px] font-semibold">{call.toolId}</span>
                        <Label>{call.status}{'write' === call.intent ? ' · changes data' : ''}</Label>
                      </div>
                      <pre className="mt-1 overflow-x-auto text-[11px]" style={{ color: 'var(--text-dim)' }}>
                        {JSON.stringify(call.arguments)}
                      </pre>
                    </div>
                  ))}
                </div>
              ) : null}
            </Card>
          ))}
          {c.runs.length ? null : (
            <p className="text-[13px]" style={{ color: 'var(--text-dim)' }}>No agent runs recorded.</p>
          )}
        </div>
      </Section>
    </>
  );
}
