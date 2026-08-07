import { useQuery } from '@tanstack/react-query';
import { Link, useOutletContext } from 'react-router-dom';
import { api } from '../lib/api';
import type { Bootstrap, ConversationSummary, Health } from '../lib/types';
import { CrewBar } from '../components/CrewBar';
import { Card, Empty, Label, Section, Spinner } from '../components/primitives';

export function Crew() {
  const boot = useOutletContext<Bootstrap>();
  const health = useQuery({ queryKey: ['health'], queryFn: () => api.get<Health>('/health') });
  const conversations = useQuery({
    queryKey: ['conversations'],
    queryFn: () => api.get<ConversationSummary[]>('/conversations?limit=25'),
  });

  return (
    <>
      <Section title="Who is on">
        <CrewBar boot={boot} health={health.data} />
      </Section>

      <Section title="Recent conversations">
        {conversations.isLoading ? (
          <Spinner label="Loading conversations" />
        ) : (conversations.data ?? []).length ? (
          <div className="grid gap-2">
            {conversations.data!.map((c) => (
              <Link key={c.uuid} to={`/conversation/${c.uuid}`}>
                <Card
                  edge={'escalated' === c.status ? 'var(--color-alert-500)' : 'open' === c.status ? 'var(--color-crew-500)' : undefined}
                  className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 transition-colors hover:border-signal-400"
                >
                  <div className="min-w-0">
                    <p className="text-[13px] font-medium">
                      {c.messageCount} {1 === c.messageCount ? 'message' : 'messages'}
                      {c.identityVerified ? ' · identified' : ''}
                    </p>
                    <Label className="mt-1">{c.lastActivityAt} · {c.channel}</Label>
                  </div>
                  <span className="scr-num text-[12px]" style={{ color: 'var(--text-dim)' }}>{c.status}</span>
                </Card>
              </Link>
            ))}
          </div>
        ) : (
          <Empty
            title="No conversations yet"
            hint="Once the chat widget is on your storefront, everything the crew handles shows up here."
          />
        )}
      </Section>
    </>
  );
}
