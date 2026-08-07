import { useQuery } from '@tanstack/react-query';
import { Link, useOutletContext } from 'react-router-dom';
import { api } from '../lib/api';
import type { Bootstrap, ConversationSummary, Health } from '../lib/types';
import { CrewBar } from '../components/CrewBar';
import { Card, Empty, IconChip, Label, PageHeader, Pill, Section, Spinner } from '../components/primitives';

export function Crew() {
  const boot = useOutletContext<Bootstrap>();
  const health = useQuery({ queryKey: ['health'], queryFn: () => api.get<Health>('/health') });
  const conversations = useQuery({
    queryKey: ['conversations'],
    queryFn: () => api.get<ConversationSummary[]>('/conversations?limit=25'),
  });

  return (
    <>
      <PageHeader title="Crew" sub="Your AI employees, and the conversations they have been handling." />

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
                  interactive
                  className="flex flex-wrap items-center gap-3 px-4 py-3"
                >
                  <IconChip name="message" tone={'escalated' === c.status ? 'alert' : 'open' === c.status ? 'crew' : 'neutral'} size={30} />
                  <div className="min-w-0 flex-1">
                    <p className="text-[13px] font-medium">
                      {c.messageCount} {1 === c.messageCount ? 'message' : 'messages'}
                      {c.identityVerified ? ' · identified' : ''}
                    </p>
                    <Label className="mt-1">{c.lastActivityAt} · {c.channel}</Label>
                  </div>
                  <Pill tone={'escalated' === c.status ? 'alert' : 'open' === c.status ? 'crew' : 'neutral'}>
                    {c.status}
                  </Pill>
                </Card>
              </Link>
            ))}
          </div>
        ) : (
          <Empty
            icon="message"
            title="No conversations yet"
            hint="Once the chat widget is on your storefront, everything the crew handles shows up here."
          />
        )}
      </Section>
    </>
  );
}
