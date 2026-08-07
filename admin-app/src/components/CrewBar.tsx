import { Card, Label } from './primitives';
import type { Bootstrap, Health } from '../lib/types';

/**
 * The signature element: a shift board.
 *
 * Each agent is a card with its state carried on a left edge-bar, the way a
 * roster reads by colour down the margin. The merchant's first question every
 * morning is "is the crew working", and this answers it before they read a
 * single number.
 */

const AGENTS = [
  { id: 'sales', label: 'Sales', feature: 'agent.sales', role: 'Finds products' },
  { id: 'support', label: 'Support', feature: 'agent.support', role: 'Handles orders' },
  { id: 'marketing', label: 'Marketing', feature: 'agent.marketing', role: 'Runs campaigns' },
  { id: 'analytics', label: 'Analytics', feature: 'agent.analytics', role: 'Reads the numbers' },
];

export function CrewBar({ boot, health }: { boot: Bootstrap; health?: Health }) {
  const indexReady = (health?.index.embedded ?? 0) > 0;

  return (
    <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
      {AGENTS.map((agent) => {
        const entitled = boot.features[agent.feature] === true;
        // On duty means entitled *and* able to do the job. An agent with no
        // knowledge base is not working, however green its licence is.
        const onDuty = entitled && (agent.id === 'analytics' || indexReady);

        const edge = !entitled
          ? 'var(--line)'
          : onDuty
            ? 'var(--color-crew-500)'
            : 'var(--color-signal-500)';

        const state = !entitled ? 'Not on your plan' : onDuty ? 'On duty' : 'Needs setup';

        return (
          <Card key={agent.id} edge={edge} className="px-4 py-3.5">
            <div className="flex items-start justify-between gap-2">
              <div className="min-w-0">
                <p className="truncate text-[13px] font-semibold">{agent.label}</p>
                <p className="mt-0.5 truncate text-[12px]" style={{ color: 'var(--text-dim)' }}>
                  {agent.role}
                </p>
              </div>
              {onDuty ? (
                <span
                  className="scr-live mt-1 inline-block h-1.5 w-1.5 shrink-0 rounded-full"
                  style={{ background: 'var(--color-crew-500)' }}
                  aria-hidden
                />
              ) : null}
            </div>
            <Label className="mt-3">{state}</Label>
          </Card>
        );
      })}
    </div>
  );
}
