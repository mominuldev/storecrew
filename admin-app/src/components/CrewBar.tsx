import { useQuery } from '@tanstack/react-query';
import { api } from '../lib/api';
import { Card, Pill } from './primitives';
import type { Agent, Bootstrap, Health } from '../lib/types';

/**
 * The signature element: a shift board.
 *
 * Each agent is a card on the roster — a monogram, a name, and a status pill.
 * The merchant's first question every morning is "is the crew working", and
 * this answers it before they read a single number.
 */

/**
 * Which agents the merchant has actually put on, keyed by the feature slug the
 * catalog uses. An agent missing from the roster is read as on — the same
 * default the orchestrator takes for an agent with no configuration row, so a
 * loading roster never flickers the whole crew to "stood down".
 */
export function useRoster(): Map<string, boolean> {
  const agents = useQuery({ queryKey: ['agents'], queryFn: () => api.get<Agent[]>('/agents') });

  return new Map((agents.data ?? []).map((a) => [a.feature, a.enabled]));
}

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
export function crewSummary(
  boot: Bootstrap,
  health?: Health,
  roster?: Map<string, boolean>,
): { onDuty: number; total: number } {
  const indexReady = (health?.index.embedded ?? 0) > 0;
  const agents = boot.catalog.filter((f) => f.slug.startsWith('agent.'));

  const onDuty = agents.filter(
    (agent) =>
      boot.features[agent.slug] === true &&
      false !== roster?.get(agent.slug) &&
      (INDEX_FREE.has(agent.slug) || indexReady),
  ).length;

  return { onDuty, total: agents.length };
}

export function CrewBar({ boot, health, wide }: { boot: Bootstrap; health?: Health; wide?: boolean }) {
  const indexReady = (health?.index.embedded ?? 0) > 0;
  const roster = useRoster();

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
        // Stood down by the merchant, not by the plan — a distinct state, and
        // one the board has to show or the setup step's switch appears to do
        // nothing (FR-ADMIN-02).
        const standing = false !== roster.get(agent.slug);
        // On duty means entitled, put on, *and* able to do the job. An agent
        // with no knowledge base is not working, however green its licence is.
        const onDuty = entitled && standing && (INDEX_FREE.has(agent.slug) || indexReady);

        const tone = !entitled ? ('neutral' as const) : onDuty ? ('crew' as const) : ('signal' as const);

        const state = !entitled
          ? 'Not on your plan'
          : !standing
            ? 'Stood down'
            : onDuty
              ? 'On duty'
              : 'Needs setup';

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
