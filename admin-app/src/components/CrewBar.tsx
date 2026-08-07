import { Card, Pill } from './primitives';
import type { Bootstrap, Health } from '../lib/types';

/**
 * The signature element: a shift board.
 *
 * Each agent is a card on the roster — a monogram, a name, and a status pill.
 * The merchant's first question every morning is "is the crew working", and
 * this answers it before they read a single number.
 */

/**
 * Agents that read the store's knowledge base cannot be on duty without one.
 * Keyed by slug because "needs an index" is a fact about the agent's job, not
 * something the feature catalog carries — a contributed agent defaults to
 * needing one, which errs toward "needs setup" rather than a false "on duty".
 */
const INDEX_FREE = new Set(['agent.analytics']);

const monogram = (label: string): string => {
  const words = label.trim().split(/\s+/);

  return (words.length > 1 ? `${words[0][0]}${words[1][0]}` : label.slice(0, 2)).toUpperCase();
};

/** The board's headline, for anywhere that quotes it: who is on, out of how
 *  many. Same rules as the cards below — entitled *and* able to work. */
export function crewSummary(boot: Bootstrap, health?: Health): { onDuty: number; total: number } {
  const indexReady = (health?.index.embedded ?? 0) > 0;
  const agents = boot.catalog.filter((f) => f.slug.startsWith('agent.'));

  const onDuty = agents.filter(
    (agent) => boot.features[agent.slug] === true && (INDEX_FREE.has(agent.slug) || indexReady),
  ).length;

  return { onDuty, total: agents.length };
}

export function CrewBar({ boot, health, wide }: { boot: Bootstrap; health?: Health; wide?: boolean }) {
  const indexReady = (health?.index.embedded ?? 0) > 0;

  // The board renders from the capability manifest (G4-D1): every feature in
  // the catalog whose slug names an agent, in registry order — free's agents
  // first, then whatever premium registered, with premium's own labels and
  // descriptions. The free plugin no longer owns a word of premium's copy.
  const agents = boot.catalog.filter((f) => f.slug.startsWith('agent.'));

  // The board shares a column with the Overview's rail, where four across
  // truncates every name; only a full-width host asks for four.
  return (
    <div className={`grid gap-3 sm:grid-cols-2 ${wide ? 'xl:grid-cols-4' : ''}`}>
      {agents.map((agent) => {
        const entitled = boot.features[agent.slug] === true;
        // On duty means entitled *and* able to do the job. An agent with no
        // knowledge base is not working, however green its licence is.
        const onDuty = entitled && (INDEX_FREE.has(agent.slug) || indexReady);

        const tone = !entitled ? ('neutral' as const) : onDuty ? ('crew' as const) : ('signal' as const);

        const state = !entitled ? 'Not on your plan' : onDuty ? 'On duty' : 'Needs setup';

        const avatar = {
          crew: { background: 'var(--tint-crew)', color: 'var(--fg-crew)' },
          signal: { background: 'var(--tint-signal)', color: 'var(--fg-signal)' },
          neutral: { background: 'var(--tint-neutral)', color: 'var(--text-dim)' },
        }[tone];

        return (
          <Card key={agent.slug} className="flex flex-col px-4 py-4">
            <div className="flex items-start gap-3">
              <span className="scr-avatar" style={avatar}>
                {monogram(agent.label)}
              </span>
              <div className="min-w-0">
                <p className="truncate text-[13px] font-semibold">{agent.label}</p>
                <p
                  className="mt-0.5 line-clamp-2 text-[12px] leading-snug"
                  style={{ color: 'var(--text-dim)' }}
                  title={agent.description}
                >
                  {agent.description}
                </p>
              </div>
            </div>
            <div className="mt-3.5">
              <Pill tone={tone} live={onDuty}>
                {state}
              </Pill>
            </div>
          </Card>
        );
      })}
    </div>
  );
}
