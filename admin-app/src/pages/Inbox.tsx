import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib/api';
import type { Approval } from '../lib/types';
import { Button, Card, Empty, Label, Section, Spinner } from '../components/primitives';

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
    <Section title={`Needs you${items.length ? ` (${items.length})` : ''}`}>
      {items.length ? (
        <div className="grid gap-2">
          {items.map((item) => {
            const failed = problem?.id === item.id ? problem.message : null;
            // Only the card being decided goes busy — one pending mutation must
            // not read as every card being unavailable.
            const busy = decide.isPending && decide.variables?.id === item.id;

            return (
              <Card
                key={item.id}
                edge={failed ? 'var(--color-alert-500)' : 'var(--color-signal-500)'}
                className="px-4 py-3.5"
              >
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <p className="scr-num text-[13px] font-semibold">{item.toolId}</p>
                    <pre
                      className="mt-1.5 overflow-x-auto rounded p-2 text-[12px]"
                      style={{ background: 'var(--bg)', color: 'var(--text-dim)' }}
                    >
                      {JSON.stringify(item.arguments, null, 2)}
                    </pre>
                    <Label className="mt-2">Asked {item.createdAt}</Label>
                  </div>
                  <div className="flex shrink-0 gap-2">
                    <Button variant="primary" disabled={busy} onClick={() => decide.mutate({ id: item.id, decision: 'approve' })}>
                      Approve
                    </Button>
                    <Button variant="danger" disabled={busy} onClick={() => decide.mutate({ id: item.id, decision: 'deny' })}>
                      Decline
                    </Button>
                  </div>
                </div>

                {failed ? (
                  <div
                    className="mt-3 flex flex-wrap items-center gap-3 border-t pt-3"
                    style={{ borderColor: 'var(--line)' }}
                  >
                    <p className="text-[13px]" style={{ color: 'var(--color-alert-500)' }}>
                      {failed}
                    </p>
                    <Button onClick={refresh}>Refresh the queue</Button>
                  </div>
                ) : null}
              </Card>
            );
          })}
        </div>
      ) : (
        <Empty
          title="Nothing waiting"
          hint="The crew answers questions on its own. It only asks here before doing something that changes an order."
        />
      )}
    </Section>
  );
}
