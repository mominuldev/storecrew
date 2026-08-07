import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useOutletContext } from 'react-router-dom';
import { api, siteUrl } from '../lib/api';
import type {
  Agent,
  Bootstrap,
  ChatSettings,
  IndexEstimate,
  IndexStatus,
  Provider,
  Settings as SettingsData,
  StepId,
} from '../lib/types';
import { Icon } from '../components/Icon';
import { ProviderMark, providerKeysUrl } from '../components/ProviderMark';
import { Button, Card, Label, PageHeader, Pill, Problem, Spinner } from '../components/primitives';

/**
 * The five-step path (FR-ADMIN-02): key → sources → index → agents → widget.
 *
 * One screen, not a tour of five others. The PRD's time-to-value target is
 * fifteen minutes on a fresh install, and most of that budget goes on *finding*
 * the next control — so every step's control is inline here, and the merchant
 * never has to work out which settings tab a sentence was talking about.
 *
 * Nothing on this screen records its own progress. Each step reads as done
 * because the thing itself is true — a provider resolves, a decision is on
 * record, vectors exist, an agent is on duty, the widget is switched on
 * (`Core\Onboarding`). A stored "step 3 complete" flag is exactly how a console
 * ends up congratulating a merchant whose crew cannot answer a question.
 */

const money = (micros: number) => `$${(micros / 1_000_000).toFixed(2)}`;

const STEPS: { id: StepId; title: string; why: string }[] = [
  {
    id: 'provider',
    title: 'Connect an AI provider',
    why: 'The crew runs on your own API key. You pay the provider directly — nothing is billed through StoreCrew, and no store data passes through us.',
  },
  {
    id: 'sources',
    title: 'Choose what the crew reads',
    why: 'Only what you pick here is read, embedded, and quoted back to customers.',
  },
  {
    id: 'index',
    title: 'Let the crew read your store',
    why: 'This is the one step that costs money, and it runs in the background. You can leave this page.',
  },
  {
    id: 'agents',
    title: 'Say who is on duty',
    why: 'Each agent answers a different kind of question, and only reaches the tools it was given.',
  },
  {
    id: 'widget',
    title: 'Put the crew on your storefront',
    why: 'Until this is on, nothing at all loads on the shop — not even the script.',
  },
];

/** The one-liner the Overview card and the rail badge use to name what is
 *  blocking. Exported so the sentence exists once. */
export const STEP_BLOCKER: Record<StepId, string> = {
  provider: 'No AI provider is connected yet.',
  sources: 'Nobody has said what the crew may read.',
  index: 'The crew has not read your store yet.',
  agents: 'Nobody is on duty.',
  widget: 'The crew is not on your storefront yet.',
};

function StepCard({
  index,
  title,
  why,
  done,
  open,
  onOpen,
  children,
}: {
  index: number;
  title: string;
  why: string;
  done: boolean;
  open: boolean;
  onOpen: () => void;
  children: ReactNode;
}) {
  return (
    <Card edge={done ? 'var(--color-crew-500)' : open ? 'var(--color-accent-500)' : undefined} className="px-5 py-4">
      <button onClick={onOpen} aria-expanded={open} className="flex w-full items-start gap-3.5 text-left">
        <span
          className="grid h-7 w-7 shrink-0 place-items-center rounded-full text-[12px] font-bold tabular-nums"
          style={
            done
              ? { background: 'var(--tint-crew)', color: 'var(--fg-crew)' }
              : { background: 'var(--tint-neutral)', color: 'var(--text-dim)' }
          }
        >
          {done ? <Icon name="check" size={14} strokeWidth={2.6} /> : index + 1}
        </span>
        <span className="min-w-0 flex-1">
          <span className="flex flex-wrap items-center gap-2">
            <span className="text-[14px] font-semibold">{title}</span>
            {done ? <Pill tone="crew">Done</Pill> : null}
          </span>
          <span className="mt-0.5 block text-[12.5px] leading-snug" style={{ color: 'var(--text-dim)' }}>
            {why}
          </span>
        </span>
        <span className="shrink-0 pt-1" style={{ color: 'var(--text-dim)' }} aria-hidden>
          <Icon name={open ? 'x' : 'arrowUpRight'} size={14} />
        </span>
      </button>

      {open ? <div className="mt-4 border-t pt-4" style={{ borderColor: 'var(--line)' }}>{children}</div> : null}
    </Card>
  );
}

export function Setup() {
  const boot = useOutletContext<Bootstrap>();

  const done = new Map(boot.onboarding.steps.map((s) => [s.id, s.done]));
  const [opened, setOpened] = useState<StepId | null>(null);
  const open = opened ?? (boot.onboarding.current || null);

  const finished = boot.onboarding.steps.filter((s) => s.done).length;

  return (
    <>
      <PageHeader
        title="Set up your crew"
        sub={
          boot.onboarding.complete
            ? 'Everything is in place. Come back here any time to change it.'
            : 0 === finished
              ? // A freshly activated install lands here from the activation
                // redirect, and "pick up where you left off" is a lie to
                // someone who has not left anything yet.
                `${STEPS.length} steps. Nothing reaches a customer until the last one.`
              : `${finished} of ${STEPS.length} done — pick up where you left off.`
        }
        action={
          boot.onboarding.complete ? (
            <Link to="/">
              <Button variant="primary">Go to the board</Button>
            </Link>
          ) : null
        }
      />

      <div className="grid gap-2.5">
        {STEPS.map((step, i) => (
          <StepCard
            key={step.id}
            index={i}
            title={step.title}
            why={step.why}
            done={done.get(step.id) === true}
            open={open === step.id}
            onOpen={() => setOpened(open === step.id ? null : step.id)}
          >
            {'provider' === step.id ? <ProviderStep canEmbed={boot.onboarding.canEmbed} /> : null}
            {'sources' === step.id ? <SourcesStep /> : null}
            {'index' === step.id ? <IndexStep /> : null}
            {'agents' === step.id ? <AgentsStep /> : null}
            {'widget' === step.id ? <WidgetStep /> : null}
          </StepCard>
        ))}
      </div>

      {boot.onboarding.complete ? (
        <Card className="mt-6 px-5 py-5">
          <p className="text-[14px] font-semibold">Your crew is on the floor.</p>
          <p className="mt-1 text-[13px]" style={{ color: 'var(--text-dim)' }}>
            Ask the index what a shopper would ask to see exactly what the crew would answer from, and watch the
            Overview for anything that needs you.
          </p>
          <div className="mt-4 flex flex-wrap gap-2">
            <Link to="/knowledge">
              <Button icon="search">Try a question</Button>
            </Link>
            <a href={siteUrl()} target="_blank" rel="noreferrer">
              <Button icon="store">Open your storefront</Button>
            </a>
          </div>
        </Card>
      ) : null}
    </>
  );
}

/** Invalidate everything a step's action can move. `bootstrap` carries the step
 *  state, so it is on every list — a step that completes has to stop saying it
 *  has not. */
const useRefresh = () => {
  const qc = useQueryClient();

  return (...keys: string[]) => {
    for (const key of ['bootstrap', ...keys]) {
      qc.invalidateQueries({ queryKey: [key] });
    }
  };
};

function ProviderStep({ canEmbed }: { canEmbed: boolean }) {
  const refresh = useRefresh();
  const [keys, setKeys] = useState<Record<string, string>>({});

  const providers = useQuery({ queryKey: ['providers'], queryFn: () => api.get<Provider[]>('/providers') });
  const settings = useQuery({ queryKey: ['settings'], queryFn: () => api.get<SettingsData>('/settings') });

  const saveKey = useMutation({
    mutationFn: ({ id, key }: { id: string; key: string }) => api.post(`/providers/${id}/key`, { key }),
    onSuccess: (_d, v) => {
      setKeys((k) => ({ ...k, [v.id]: '' }));
      refresh('providers', 'settings');
    },
  });

  const verify = useMutation({
    mutationFn: (id: string) => api.post<{ ok: boolean; error: string }>(`/providers/${id}/verify`),
  });

  if (providers.isLoading || settings.isLoading) return <Spinner label="Reading your connections" />;

  const list = providers.data ?? [];
  const connected = list.filter((p) => p.configured);
  const cap = settings.data?.spend.capMicros ?? 0;

  return (
    <>
      <p className="text-[13px] leading-relaxed">
        {cap > 0 ? (
          <>
            Most stores spend a few dollars a month. Your monthly cap is <strong>{money(cap)}</strong> — the crew stops
            when it reaches it, so a runaway bill is not possible.
          </>
        ) : (
          <>
            Most stores spend a few dollars a month. No monthly cap is set yet — set one on Settings and the crew stops
            rather than spending past it.
          </>
        )}
      </p>

      {connected.length > 0 && !canEmbed ? (
        <div className="mt-3">
          <Problem message="What you have connected can hold a conversation but cannot read your store — Anthropic has no embeddings endpoint. Add OpenAI or Gemini as well, or step 3 has nothing to build." />
        </div>
      ) : null}

      <div className="mt-4 grid gap-2">
        {list.map((p) => (
          <div key={p.id} className="rounded-xl border px-4 py-3" style={{ borderColor: 'var(--line)' }}>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="flex min-w-0 items-center gap-2.5">
                <ProviderMark id={p.id} label={p.label} />
                <div className="min-w-0">
                  <p className="text-[13px] font-semibold">{p.label}</p>
                  <Label>{p.capabilities.embeddings ? 'can read your store' : 'replies only — cannot read your store'}</Label>
                </div>
              </div>
              {p.configured ? (
                <div className="flex items-center gap-2">
                  <Pill tone="crew">Connected</Pill>
                  <Button icon="check" onClick={() => verify.mutate(p.id)} disabled={verify.isPending}>
                    Test
                  </Button>
                </div>
              ) : providerKeysUrl(p.id) ? (
                <a
                  href={providerKeysUrl(p.id)!}
                  target="_blank"
                  rel="noreferrer"
                  className="flex items-center gap-1 text-[12px] whitespace-nowrap hover:underline"
                  style={{ color: 'var(--fg-accent)' }}
                >
                  Get a key from {p.label}
                  <Icon name="arrowUpRight" size={12} />
                </a>
              ) : null}
            </div>

            {verify.data && verify.variables === p.id ? (
              <p className="mt-2 text-[12px]" style={{ color: verify.data.ok ? 'var(--fg-crew)' : 'var(--fg-alert)' }}>
                {verify.data.ok ? 'The key works.' : verify.data.error}
              </p>
            ) : null}

            <form
              className="mt-2.5 flex flex-wrap items-center gap-2"
              onSubmit={(e) => {
                e.preventDefault();
                const key = (keys[p.id] ?? '').trim();
                if (key) saveKey.mutate({ id: p.id, key });
              }}
            >
              <input
                type="password"
                value={keys[p.id] ?? ''}
                onChange={(e) => setKeys((k) => ({ ...k, [p.id]: e.target.value }))}
                placeholder={p.configured ? 'Replace the key' : 'Paste the API key'}
                className="scr-input min-w-0 flex-1"
                aria-label={`${p.label} API key`}
              />
              <Button type="submit" variant="primary" disabled={saveKey.isPending || !(keys[p.id] ?? '').trim()}>
                Save and check
              </Button>
            </form>
          </div>
        ))}
      </div>

      {saveKey.isError ? (
        <div className="mt-3">
          <Problem message={(saveKey.error as Error).message} />
        </div>
      ) : null}
    </>
  );
}

function SourcesStep() {
  const refresh = useRefresh();
  const [draft, setDraft] = useState<Set<string> | null>(null);

  const status = useQuery({ queryKey: ['index'], queryFn: () => api.get<IndexStatus>('/index') });

  const save = useMutation({
    mutationFn: (sources: string[]) =>
      api.post<{ selected: string[]; removed: string[]; purged: { sources: number; chunks: number } }>(
        '/index/sources',
        { sources },
      ),
    onSuccess: () => refresh('index', 'estimate'),
  });

  if (status.isLoading) return <Spinner label="Reading your store" />;

  const available = status.data?.selection.available ?? [];
  const selected = draft ?? new Set(available.filter((s) => s.enabled).map((s) => s.type));

  const toggle = (type: string) => {
    const next = new Set(selected);
    if (next.has(type)) {
      next.delete(type);
    } else {
      next.add(type);
    }
    setDraft(next);
  };

  // Turning a source off is destructive — what has already been read from it is
  // removed, because content that stays in the index stays quotable.
  const dropping = available.filter((s) => s.enabled && !selected.has(s.type));

  if (!available.length) {
    return (
      <p className="text-[13px]" style={{ color: 'var(--text-dim)' }}>
        Nothing on this site can be read yet. Products need WooCommerce active.
      </p>
    );
  }

  return (
    <>
      <div className="grid gap-2">
        {available.map((source) => (
          <label
            key={source.type}
            className="flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3"
            style={{ borderColor: 'var(--line)' }}
          >
            <input type="checkbox" checked={selected.has(source.type)} onChange={() => toggle(source.type)} />
            <span className="min-w-0 flex-1">
              <span className="block text-[13px] font-semibold">{source.label}</span>
              <span className="block text-[12px]" style={{ color: 'var(--text-dim)' }}>
                {source.count} to read
              </span>
            </span>
          </label>
        ))}
      </div>

      {dropping.length ? (
        <div className="mt-3">
          <Problem
            message={`Saving this removes everything already read from ${dropping
              .map((s) => s.label.toLowerCase())
              .join(' and ')}. The crew stops being able to answer from it.`}
          />
        </div>
      ) : null}

      {save.data && save.data.purged.chunks > 0 ? (
        <p className="mt-3 text-[12px]" style={{ color: 'var(--text-dim)' }}>
          Removed {save.data.purged.chunks} passages from {save.data.purged.sources} excluded items.
        </p>
      ) : null}

      {save.isError ? (
        <div className="mt-3">
          <Problem message={(save.error as Error).message} />
        </div>
      ) : null}

      <div className="mt-4 flex flex-wrap items-center gap-3">
        <Button
          variant="primary"
          disabled={save.isPending || selected.size === 0}
          onClick={() => save.mutate([...selected])}
        >
          {save.isPending ? 'Saving…' : 'Save what the crew reads'}
        </Button>
        {selected.size === 0 ? (
          <span className="text-[12px]" style={{ color: 'var(--text-dim)' }}>
            Pick at least one — with nothing to read the crew can only say it does not know.
          </span>
        ) : null}
      </div>
    </>
  );
}

function IndexStep() {
  const refresh = useRefresh();

  const status = useQuery({
    queryKey: ['index'],
    queryFn: () => api.get<IndexStatus>('/index'),
    refetchInterval: (q) => {
      const data = q.state.data as IndexStatus | undefined;
      return data?.active?.alive || (data?.health.pending ?? 0) > 0 ? 3_000 : false;
    },
  });

  const estimate = useQuery({ queryKey: ['estimate'], queryFn: () => api.get<IndexEstimate>('/index/estimate') });

  const start = useMutation({ mutationFn: () => api.post('/index/start'), onSuccess: () => refresh('index') });
  const embed = useMutation({ mutationFn: () => api.post('/index/embed'), onSuccess: () => refresh('index') });

  if (status.isLoading) return <Spinner label="Reading the index" />;

  const s = status.data!;
  const active = s.active;
  const est = estimate.data;
  const pct = active?.total ? Math.min(100, (active.processed / active.total) * 100) : 0;

  return (
    <>
      {est ? (
        <p className="text-[13px] leading-relaxed">
          {est.total} items to read, around {est.estimatedChunks} passages.{' '}
          {est.costKnown ? (
            <>
              That should cost about <strong>{money(est.costMicros)}</strong> once.
            </>
          ) : (
            <>We have no published rate for the model you chose, so we will not guess at the cost.</>
          )}
        </p>
      ) : null}

      <div className="mt-3 flex flex-wrap items-center gap-3">
        <span className="text-[13px]">
          <strong className="tabular-nums">{s.health.embedded}</strong> of{' '}
          <span className="tabular-nums">{s.health.chunks}</span> passages ready
        </span>
        {s.health.pending > 0 ? (
          <Pill tone="signal" live>
            {s.health.pending} still to read
          </Pill>
        ) : null}
      </div>

      {/* This step counts as done once the crew can answer from *something*,
          because the rest is a queue the merchant cannot hurry. That is only
          honest while the remainder stays visible — so it is said in words
          here, not left to the number above. */}
      {s.health.embedded > 0 && s.health.pending > 0 ? (
        <p className="mt-2 text-[12.5px] leading-relaxed" style={{ color: 'var(--text-dim)' }}>
          Your part of this step is done — the crew is answering already. It is still working through the rest in the
          background, and until it finishes there are questions it will say it does not know the answer to.
        </p>
      ) : null}

      {active ? (
        <div className="mt-3">
          <div className="flex items-center justify-between gap-3">
            <p className="text-[13px]">
              {active.alive
                ? `Reading — ${active.processed} of ${active.total} done. You can leave this page.`
                : 'The last read stopped without finishing. Starting again picks up where it left off.'}
            </p>
            <span className="scr-num text-[13px] font-semibold">{Math.round(pct)}%</span>
          </div>
          <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full" style={{ background: 'var(--surface-2)' }}>
            <div
              className="h-full rounded-full transition-[width] duration-500"
              style={{
                width: `${pct}%`,
                background: active.alive ? 'var(--color-signal-500)' : 'var(--color-alert-500)',
              }}
            />
          </div>
        </div>
      ) : null}

      {start.isError ? (
        <div className="mt-3">
          <Problem message={(start.error as Error).message} />
        </div>
      ) : null}

      <div className="mt-4 flex flex-wrap gap-2">
        <Button variant="primary" icon="refresh" onClick={() => start.mutate()} disabled={start.isPending || !!active?.alive}>
          {active?.alive ? 'Reading…' : s.health.chunks > 0 ? 'Read everything again' : 'Start reading'}
        </Button>
        {s.health.pending > 0 && !active?.alive ? (
          <Button onClick={() => embed.mutate()} disabled={embed.isPending}>
            Finish the waiting passages
          </Button>
        ) : null}
      </div>
    </>
  );
}

function AgentsStep() {
  const refresh = useRefresh();

  const agents = useQuery({ queryKey: ['agents'], queryFn: () => api.get<Agent[]>('/agents') });

  const toggle = useMutation({
    mutationFn: ({ id, enabled }: { id: string; enabled: boolean }) => api.post(`/agents/${id}`, { enabled }),
    onSuccess: () => refresh('agents'),
  });

  if (agents.isLoading) return <Spinner label="Reading the roster" />;

  const list = agents.data ?? [];
  const onDuty = list.filter((a) => a.entitled && a.enabled);

  return (
    <>
      <div className="grid gap-2">
        {list.map((agent) => (
          <div key={agent.id} className="rounded-xl border px-4 py-3" style={{ borderColor: 'var(--line)' }}>
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <p className="text-[13px] font-semibold">{agent.label}</p>
                  {!agent.entitled ? (
                    <Pill>Not on your plan</Pill>
                  ) : agent.enabled ? (
                    <Pill tone="crew" live>
                      On duty
                    </Pill>
                  ) : (
                    <Pill tone="signal">Stood down</Pill>
                  )}
                </div>
                <p className="mt-1 text-[12px] leading-snug" style={{ color: 'var(--text-dim)' }}>
                  {agent.mission}
                </p>
                {agent.toolIds.length ? (
                  <div className="mt-1.5">
                    <Label>can use {agent.toolIds.join(' · ')}</Label>
                  </div>
                ) : null}
              </div>
              <Button
                variant={agent.enabled ? 'danger' : 'primary'}
                disabled={!agent.entitled || toggle.isPending}
                onClick={() => toggle.mutate({ id: agent.id, enabled: !agent.enabled })}
              >
                {agent.enabled ? 'Stand down' : 'Put on duty'}
              </Button>
            </div>
          </div>
        ))}
      </div>

      {toggle.isError ? (
        <div className="mt-3">
          <Problem message={(toggle.error as Error).message} />
        </div>
      ) : null}

      {!onDuty.length ? (
        <div className="mt-3">
          <Problem message="With nobody on duty the widget can take a message but cannot answer it. Put at least one agent on." />
        </div>
      ) : null}

      <p className="mt-3 text-[12px]" style={{ color: 'var(--text-dim)' }}>
        Personas and per-tool autonomy are edited from the agent's own screen — this step is only about who is working.
      </p>
    </>
  );
}

function WidgetStep() {
  const refresh = useRefresh();

  const settings = useQuery({ queryKey: ['settings'], queryFn: () => api.get<SettingsData>('/settings') });

  const save = useMutation({
    mutationFn: (chat: Partial<ChatSettings>) => api.post('/settings', { chat }),
    onSuccess: () => refresh('settings'),
  });

  if (settings.isLoading) return <Spinner label="Reading your storefront settings" />;

  const s = settings.data!;
  const chat = s.chat;
  const canAnswer = Boolean(s.resolved.chat);

  return (
    <>
      {!canAnswer ? (
        <div className="mb-3">
          <Problem message="No model is set for talking to customers, so the widget stays off. Finish step 1 first." />
        </div>
      ) : null}

      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <p className="text-[13px] font-semibold">
            {chat.enabled ? 'On duty on your storefront' : 'Nothing is loaded on the storefront'}
          </p>
          <p className="mt-0.5 text-[12px]" style={{ color: 'var(--text-dim)' }}>
            {chat.enabled
              ? 'Shoppers can start a conversation from any page.'
              : 'Not even the script — turning this on is what puts it there.'}
          </p>
        </div>
        <Button
          variant={chat.enabled ? 'danger' : 'primary'}
          disabled={save.isPending || (!canAnswer && !chat.enabled)}
          onClick={() => save.mutate({ enabled: !chat.enabled })}
        >
          {chat.enabled ? 'Take it down' : 'Put the crew on the floor'}
        </Button>
      </div>

      <div className="mt-4 flex flex-wrap items-end gap-4">
        <div>
          <Label>Corner</Label>
          <select
            className="scr-select mt-1.5"
            value={chat.position}
            onChange={(e) => save.mutate({ position: e.target.value as 'left' | 'right' })}
          >
            <option value="right">Bottom right</option>
            <option value="left">Bottom left</option>
          </select>
        </div>
        <label className="flex items-center gap-2 pb-2 text-[13px]">
          <input type="checkbox" checked={chat.autoPlace} onChange={(e) => save.mutate({ autoPlace: e.target.checked })} />
          Float on every page
        </label>
        {chat.enabled ? (
          <a href={siteUrl()} target="_blank" rel="noreferrer" className="ms-auto">
            <Button icon="store">Go and look at it</Button>
          </a>
        ) : null}
      </div>

      {save.isError ? (
        <div className="mt-3">
          <Problem message={(save.error as Error).message} />
        </div>
      ) : null}

      <p className="mt-4 text-[12px]" style={{ color: 'var(--text-dim)' }}>
        Wording, colour, and the greeting live on{' '}
        <Link to="/settings?tab=storefront" className="underline">
          Settings › Storefront
        </Link>
        . Turn the floating launcher off to place the panel yourself with the{' '}
        <code className="scr-num">[storecrew_chat]</code> shortcode.
      </p>
    </>
  );
}
