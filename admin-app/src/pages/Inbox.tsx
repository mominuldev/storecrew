import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import type { Approval, ConversationSummary } from '../lib/types';
import { ArgumentList } from '../components/ArgumentList';
import { Button, Card, Empty, IconChip, Label, PageHeader, Section, Spinner } from '../components/primitives';

/**
 * Everything the crew asked permission for.
 *
 * Reads never appear here — filling this with lookups would train a merchant to
 * approve without reading, which is the failure mode an approval queue exists
 * to prevent.
 */
export function Inbox() {
  const qc = useQueryClient();
  const list = useQuery({ queryKey: ['approvals'], queryFn: () => api.get<Approval[]>('/approvals') });

  // The other half of "needs you" (G4-D4): conversations an agent has handed
  // to a human. The console is the pull surface; the M1 email is the push.
  const escalated = useQuery({
    queryKey: ['conversations', 'escalated'],
    queryFn: () => api.get<ConversationSummary[]>('/conversations?status=escalated&limit=25'),
  });

  // Which card failed, and what the server said about it. Per card rather than
  // per screen: the merchant decided *this* action and needs the answer where
  // they clicked, not in a banner that could belong to any of them.
  const [problem, setProblem] = useState<{ id: number; message: string } | null>(null);

  const decide = useMutation({
    mutationFn: ({ id, decision }: { id: number; decision: 'approve' | 'deny' }) =>
      api.post(`/approvals/${id}`, { decision }),
    onMutate: ({ id }) => setProblem((p) => (p?.id === id ? null : p)),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['approvals'] }),
    onError: (error: Error, { id }) => setProblem({ id, message: error.message }),
  });

  // A 409 means the call left the queue while this page sat open — decided
  // elsewhere, or executed. The card stays on screen carrying the server's own
  // sentence, and refreshing is the merchant's move: silently dropping the row
  // would answer their click with nothing visible happening, which is what
  // made this look broken before.
  const refresh = () => {
    setProblem(null);
    qc.invalidateQueries({ queryKey: ['approvals'] });
  };

  if (list.isLoading) return <Spinner label="Checking the inbox" />;

  const items = list.data ?? [];

  return (
    <>
    <PageHeader title="Inbox" sub="Actions the crew wants approved, and conversations handed to a person." />

    <Section title={`Needs you${items.length ? ` (${items.length})` : ''}`}>
      {items.length ? (
        <div className="grid gap-2.5">
          {items.map((item) => {
            const failed = problem?.id === item.id ? problem.message : null;
            // Only the card being decided goes busy — one pending mutation must
            // not read as every card being unavailable.
            const busy = decide.isPending && decide.variables?.id === item.id;

            return (
              <Card
                key={item.id}
                edge={failed ? 'var(--color-alert-500)' : 'var(--color-signal-500)'}
                className="px-5 py-4"
              >
                <div className="flex flex-wrap items-start justify-between gap-4">
                  <div className="flex min-w-0 items-start gap-3">
                    <IconChip name="check" tone="signal" />
                    <div className="min-w-0">
                      <p className="scr-num text-[13px] font-semibold">{item.toolId}</p>
                      <div className="mt-2">
                        <ArgumentList args={item.arguments} />
                      </div>
                      <Label className="mt-2.5">Asked {item.createdAt}</Label>
                    </div>
                  </div>
                  <div className="flex shrink-0 gap-2">
                    <Button variant="primary" icon="check" disabled={busy} onClick={() => decide.mutate({ id: item.id, decision: 'approve' })}>
                      Approve
                    </Button>
                    <Button variant="danger" icon="x" disabled={busy} onClick={() => decide.mutate({ id: item.id, decision: 'deny' })}>
                      Decline
                    </Button>
                  </div>
                </div>

                {failed ? (
                  <div
                    className="mt-3 flex flex-wrap items-center gap-3 border-t pt-3"
                    style={{ borderColor: 'var(--line)' }}
                  >
                    <p className="text-[13px]" style={{ color: 'var(--fg-alert)' }}>
                      {failed}
                    </p>
                    <Button icon="refresh" onClick={refresh}>Refresh the queue</Button>
                  </div>
                ) : null}
              </Card>
            );
          })}
        </div>
      ) : (
        <Empty
          icon="check"
          title="Nothing waiting"
          hint="The crew answers questions on its own. It only asks here before doing something that changes an order."
        />
      )}
    </Section>

    <Section title={`Waiting for a human${(escalated.data?.length ?? 0) > 0 ? ` (${escalated.data!.length})` : ''}`}>
      {escalated.data?.length ? (
        <div className="grid gap-2">
          {escalated.data.map((c) => (
            <Link key={c.uuid} to={`/conversation/${c.uuid}`}>
              <Card edge="var(--color-alert-500)" interactive className="flex flex-wrap items-center gap-3 px-4 py-3">
                <IconChip name="message" tone="alert" size={30} />
                <div className="min-w-0 flex-1">
                  <p className="text-[13px] font-medium">
                    {c.identityVerified ? 'Identified customer' : 'Visitor'} · {c.messageCount} messages
                  </p>
                  <Label className="mt-1">{c.lastActivityAt} · {c.channel}</Label>
                </div>
                <Label>Open the conversation</Label>
              </Card>
            </Link>
          ))}
        </div>
      ) : (
        <Card className="flex items-center gap-3 px-4 py-3.5">
          <IconChip name="users" size={30} />
          <p className="text-[13px]" style={{ color: 'var(--text-dim)' }}>
            No conversation is waiting for a person. When an agent hands one over, it appears here with the reason.
          </p>
        </Card>
      )}
    </Section>
  </>
  );
}
