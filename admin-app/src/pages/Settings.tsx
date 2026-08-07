import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib/api';
import type { ChatSettings, Provider, Settings as SettingsData } from '../lib/types';
import { Button, Card, IconChip, Label, PageHeader, Pill, Problem, Section, Spinner } from '../components/primitives';

const TASK_COPY: Record<string, { title: string; hint: string }> = {
  chat: { title: 'Talking to customers', hint: 'The model that writes replies. Worth the best you can afford.' },
  routing: { title: 'Deciding who answers', hint: 'A quick judgement call. A small model is fine and much cheaper.' },
  embedding: { title: 'Reading your store', hint: 'Turns products and pages into something searchable.' },
  summary: { title: 'Summarising', hint: 'Condenses long conversations. A small model is fine.' },
};

export function Settings() {
  const qc = useQueryClient();
  const [keys, setKeys] = useState<Record<string, string>>({});

  const providers = useQuery({ queryKey: ['providers'], queryFn: () => api.get<Provider[]>('/providers') });
  const settings = useQuery({ queryKey: ['settings'], queryFn: () => api.get<SettingsData>('/settings') });

  const saveKey = useMutation({
    mutationFn: ({ id, key }: { id: string; key: string }) => api.post(`/providers/${id}/key`, { key }),
    onSuccess: (_d, v) => {
      setKeys((k) => ({ ...k, [v.id]: '' }));
      qc.invalidateQueries({ queryKey: ['providers'] });
      qc.invalidateQueries({ queryKey: ['settings'] });
      qc.invalidateQueries({ queryKey: ['bootstrap'] });
    },
  });

  const removeKey = useMutation({
    mutationFn: (id: string) => api.del(`/providers/${id}/key`),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['providers'] });
      qc.invalidateQueries({ queryKey: ['bootstrap'] });
    },
  });

  const verify = useMutation({
    mutationFn: (id: string) => api.post<{ ok: boolean; error: string }>(`/providers/${id}/verify`),
  });

  const savePolicy = useMutation({
    mutationFn: (policy: Record<string, { provider: string; model: string }>) =>
      api.post('/settings', { modelPolicy: policy }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['settings'] }),
  });

  const saveChat = useMutation({
    mutationFn: (chat: Partial<ChatSettings>) => api.post('/settings', { chat }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['settings'] }),
  });

  if (providers.isLoading || settings.isLoading) return <Spinner label="Loading settings" />;

  const list = providers.data ?? [];
  const s = settings.data!;

  return (
    <>
      <PageHeader title="Settings" sub="Providers, the models each job uses, and the storefront widget." />

      <Section title="Connections">
        <div className="grid gap-2.5">
          {list.map((p) => (
            <Card
              key={p.id}
              edge={p.configured ? 'var(--color-crew-500)' : undefined}
              className="px-5 py-4"
            >
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-3">
                  <span
                    className="scr-avatar"
                    style={
                      p.configured
                        ? { background: 'var(--tint-crew)', color: 'var(--fg-crew)' }
                        : { background: 'var(--tint-neutral)', color: 'var(--text-dim)' }
                    }
                  >
                    {p.label.slice(0, 2).toUpperCase()}
                  </span>
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="text-[13px] font-semibold">{p.label}</p>
                      {p.configured ? <Pill tone="crew">Connected</Pill> : <Pill>Not connected</Pill>}
                    </div>
                    <div className="mt-1.5 flex flex-wrap gap-x-3 gap-y-1">
                      {!p.capabilities.embeddings ? <Label>cannot read your store</Label> : <Label>can read your store</Label>}
                      {!p.capabilities.sampling ? <Label>no temperature control</Label> : null}
                    </div>
                  </div>
                </div>
                {p.configured ? (
                  <div className="flex items-center gap-2">
                    <span className="scr-num text-[12px]" style={{ color: 'var(--text-dim)' }}>{p.keyHint}</span>
                    <Button icon="check" onClick={() => verify.mutate(p.id)} disabled={verify.isPending}>Test</Button>
                    <Button variant="danger" onClick={() => removeKey.mutate(p.id)}>Remove</Button>
                  </div>
                ) : null}
              </div>

              {verify.data && verify.variables === p.id ? (
                <p className="mt-2 text-[12px]" style={{ color: verify.data.ok ? 'var(--fg-crew)' : 'var(--fg-alert)' }}>
                  {verify.data.ok ? 'Connected.' : verify.data.error}
                </p>
              ) : null}

              <form
                className="mt-3.5 flex gap-2"
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
                  className="scr-input flex-1"
                />
                <Button type="submit" disabled={saveKey.isPending || !(keys[p.id] ?? '').trim()}>Save</Button>
              </form>
            </Card>
          ))}
        </div>
        {saveKey.isError ? <div className="mt-3"><Problem message={(saveKey.error as Error).message} /></div> : null}
      </Section>

      <Section title="Which model does what">
        {!s.canEmbed ? (
          <div className="mb-3">
            <Problem message="Nothing connected can read your store. Add OpenAI or Gemini — Anthropic writes replies but cannot build the search index." />
          </div>
        ) : null}

        <div className="grid gap-2">
          {s.tasks.map((task) => {
            const current = s.modelPolicy[task] ?? s.resolved[task] ?? null;
            const wantsEmbedding = 'embedding' === task;

            const options = list.flatMap((p) => {
              const models = wantsEmbedding ? p.embedModels : p.chatModels;
              return p.configured ? models.map((m) => ({ provider: p.id, model: m, label: `${p.label} · ${m}` })) : [];
            });

            return (
              <Card key={task} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div className="min-w-0">
                  <p className="text-[13px] font-semibold">{TASK_COPY[task]?.title ?? task}</p>
                  <p className="mt-0.5 text-[12px]" style={{ color: 'var(--text-dim)' }}>
                    {TASK_COPY[task]?.hint}
                  </p>
                </div>
                <select
                  value={current ? `${current.provider}|${current.model}` : ''}
                  onChange={(e) => {
                    const [provider, model] = e.target.value.split('|');
                    savePolicy.mutate({ ...s.modelPolicy, [task]: { provider, model } });
                  }}
                  className="scr-select"
                >
                  <option value="">Not set</option>
                  {options.map((o) => (
                    <option key={`${o.provider}|${o.model}`} value={`${o.provider}|${o.model}`}>{o.label}</option>
                  ))}
                </select>
              </Card>
            );
          })}
        </div>

        {savePolicy.isError ? <div className="mt-3"><Problem message={(savePolicy.error as Error).message} /></div> : null}

        <p className="mt-3 text-[12px]" style={{ color: 'var(--text-dim)' }}>
          Cost estimates use published rates last checked {s.pricing.ratesVerified}. Models we have no rate for are
          counted as unknown rather than free.
        </p>
      </Section>

      <Storefront chat={s.chat} canAnswer={Boolean(s.resolved.chat)} save={saveChat.mutate} busy={saveChat.isPending} />
    </>
  );
}

/**
 * Putting the crew on the shop floor.
 *
 * The switch is the whole point of this panel, so it is the first thing in it —
 * and it is disabled until a chat model is set, because a widget that appears
 * and then cannot answer is worse than no widget. The wording says so rather
 * than leaving the merchant to work out why the control is greyed.
 */
function Storefront({
  chat,
  canAnswer,
  save,
  busy,
}: {
  chat: ChatSettings;
  canAnswer: boolean;
  save: (patch: Partial<ChatSettings>) => void;
  busy: boolean;
}) {
  const [draft, setDraft] = useState(chat);

  return (
    <Section title="On the storefront">
      {!canAnswer ? (
        <div className="mb-3">
          <Problem message="No model is set for talking to customers, so the widget stays off. Choose one above first." />
        </div>
      ) : null}

      <Card edge={chat.enabled ? 'var(--color-crew-500)' : undefined} className="px-5 py-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex min-w-0 items-center gap-3">
            <IconChip name="message" tone={chat.enabled ? 'crew' : 'neutral'} />
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <p className="text-[13px] font-semibold">
                  {chat.enabled ? 'On duty on your storefront' : 'Off the floor'}
                </p>
                {chat.enabled ? <Pill tone="crew" live>Live</Pill> : null}
              </div>
              <p className="mt-0.5 text-[12px]" style={{ color: 'var(--text-dim)' }}>
                {chat.enabled
                  ? 'Shoppers can start a conversation from any page.'
                  : 'Nothing is loaded on the storefront at all — not even the script.'}
              </p>
            </div>
          </div>
          <Button
            variant={chat.enabled ? 'danger' : 'primary'}
            disabled={busy || (!canAnswer && !chat.enabled)}
            onClick={() => save({ enabled: !chat.enabled })}
          >
            {chat.enabled ? 'Stand down' : 'Put on duty'}
          </Button>
        </div>
      </Card>

      <Card className="mt-2.5 px-5 py-5">
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <Label>Launcher label</Label>
            <input
              className="scr-input mt-1.5"
              value={draft.launcher}
              onChange={(e) => setDraft({ ...draft, launcher: e.target.value })}
            />
          </div>
          <div>
            <Label>Panel title</Label>
            <input
              className="scr-input mt-1.5"
              value={draft.title}
              onChange={(e) => setDraft({ ...draft, title: e.target.value })}
            />
          </div>
        </div>

        <div className="mt-4">
          <Label>First thing they see</Label>
          <textarea
            rows={2}
            className="scr-input mt-1.5"
            value={draft.greeting}
            onChange={(e) => setDraft({ ...draft, greeting: e.target.value })}
          />
        </div>

        <div className="mt-4 flex flex-wrap items-end gap-4">
          <div>
            <Label>Accent</Label>
            <input
              type="color"
              className="mt-1.5 h-9 w-16 cursor-pointer rounded-lg border"
              style={{ borderColor: 'var(--line)', background: 'var(--field)' }}
              value={draft.accent}
              onChange={(e) => setDraft({ ...draft, accent: e.target.value })}
            />
          </div>
          <div>
            <Label>Corner</Label>
            <select
              className="scr-select mt-1.5"
              value={draft.position}
              onChange={(e) => setDraft({ ...draft, position: e.target.value as 'left' | 'right' })}
            >
              <option value="right">Bottom right</option>
              <option value="left">Bottom left</option>
            </select>
          </div>
          <label className="flex items-center gap-2 pb-2 text-[13px]">
            <input
              type="checkbox"
              checked={draft.autoPlace}
              onChange={(e) => setDraft({ ...draft, autoPlace: e.target.checked })}
            />
            Float on every page
          </label>
          <div className="ms-auto">
            <Button variant="primary" disabled={busy} onClick={() => save(draft)}>Save</Button>
          </div>
        </div>

        <p className="mt-4 text-[12px]" style={{ color: 'var(--text-dim)' }}>
          Turn the floating launcher off to place the panel yourself with the{' '}
          <code className="scr-num">[storecrew_chat]</code> shortcode or the StoreCrew chat block.
        </p>
      </Card>
    </Section>
  );
}
