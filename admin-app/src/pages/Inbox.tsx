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

  const decide = useMutation({
    mutationFn: ({ id, decision }: { id: number; decision: 'approve' | 'deny' }) =>
      api.post(`/approvals/${id}`, { decision }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['approvals'] }),
  });

  if (list.isLoading) return <Spinner label="Checking the inbox" />;

  const items = list.data ?? [];

  return (
    <Section title={`Needs you${items.length ? ` (${items.length})` : ''}`}>
      {items.length ? (
        <div className="grid gap-2">
          {items.map((item) => (
            <Card key={item.id} edge="var(--color-signal-500)" className="px-4 py-3.5">
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
                  <Button variant="primary" disabled={decide.isPending} onClick={() => decide.mutate({ id: item.id, decision: 'approve' })}>
                    Approve
                  </Button>
                  <Button variant="danger" disabled={decide.isPending} onClick={() => decide.mutate({ id: item.id, decision: 'deny' })}>
                    Decline
                  </Button>
                </div>
              </div>
            </Card>
          ))}
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
