import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { api } from '../lib/api';
import type { Conversation } from '../lib/types';
import { Icon } from '../components/Icon';
import { Button, Card, Label, PageHeader, Pill, Section, Spinner, Problem } from '../components/primitives';

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
      <PageHeader
        title="Conversation"
        sub={`${c.channel} · ${c.identityVerified ? `identified on order ${c.verifiedOrderId}` : 'not identified'}`}
        action={
          <div className="flex items-center gap-3">
            <Pill tone={'escalated' === c.status ? 'alert' : 'open' === c.status ? 'crew' : 'neutral'}>{c.status}</Pill>
            <Link to="/crew"><Button icon="arrowLeft">Back</Button></Link>
          </div>
        }
      />

      <Section title="Transcript">
        <div className="grid gap-3">
          {c.turns.map((t, i) => {
            const customer = 'user' === t.role;

            return (
              <div key={i} className={`flex items-start gap-2.5 ${customer ? '' : 'flex-row-reverse'}`}>
                <span
                  className="mt-1 grid h-7 w-7 shrink-0 place-items-center rounded-full"
                  style={
                    customer
                      ? { background: 'var(--tint-neutral)', color: 'var(--text-dim)' }
                      : { background: 'var(--tint-signal)', color: 'var(--fg-signal)' }
                  }
                  aria-hidden
                >
                  <Icon name={customer ? 'user' : 'spark'} size={14} />
                </span>
                <div className={`max-w-[85%] min-w-0 sm:max-w-[70%] ${customer ? '' : 'text-right'}`}>
                  <Label>{customer ? 'Customer' : t.agentId || 'Crew'}</Label>
                  <div
                    className={`mt-1 inline-block rounded-2xl border px-4 py-2.5 text-left ${
                      customer ? 'rounded-tl-md' : 'rounded-tr-md'
                    }`}
                    style={
                      customer
                        ? { background: 'var(--surface)', borderColor: 'var(--line)' }
                        : { background: 'var(--tint-signal)', borderColor: 'transparent' }
                    }
                  >
                    <p className="whitespace-pre-wrap text-[13px] leading-relaxed">{t.content}</p>
                  </div>
                </div>
              </div>
            );
          })}
          {c.turns.length ? null : (
            <p className="text-[13px]" style={{ color: 'var(--text-dim)' }}>No messages recorded.</p>
          )}
        </div>
      </Section>

      <Section title="What happened underneath">
        <div className="grid gap-2.5">
          {c.runs.map((run) => (
            <Card
              key={run.id}
              edge={'completed' === run.status ? undefined : 'var(--color-alert-500)'}
              className="px-5 py-4"
            >
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-2.5">
                  <p className="text-[13px] font-semibold">{run.agentId}</p>
                  {'completed' !== run.status ? (
                    <Pill tone="alert">{run.status}{run.errorCode ? ` · ${run.errorCode}` : ''}</Pill>
                  ) : null}
                </div>
                <span className="scr-num text-[12px]" style={{ color: 'var(--text-dim)' }}>
                  {run.model} · {run.tokensIn}/{run.tokensOut} tok · {run.latencyMs}ms
                </span>
              </div>

              {run.retrieved?.length ? (
                <div className="mt-3">
                  <Label>Read {run.retrieved.length} passages</Label>
                  <div className="mt-1.5 flex flex-wrap gap-1.5">
                    {run.retrieved.map((r) => (
                      <span
                        key={r.id}
                        className="scr-num rounded-md px-1.5 py-0.5 text-[11px]"
                        style={{ background: 'var(--surface-2)', color: 'var(--text-dim)' }}
                      >
                        #{r.id} · {r.score.toFixed(2)}
                      </span>
                    ))}
                  </div>
                </div>
              ) : null}

              {run.toolCalls.length ? (
                <div className="mt-3 grid gap-1.5">
                  {run.toolCalls.map((call) => (
                    <div key={call.id} className="rounded-lg border p-2.5" style={{ borderColor: 'var(--line)', background: 'var(--bg)' }}>
                      <div className="flex items-baseline justify-between gap-2">
                        <span className="scr-num text-[12px] font-semibold">{call.toolId}</span>
                        <Label>{call.status}{'write' === call.intent ? ' · changes data' : ''}</Label>
                      </div>
                      <pre className="mt-1.5 overflow-x-auto text-[11px]" style={{ color: 'var(--text-dim)' }}>
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
