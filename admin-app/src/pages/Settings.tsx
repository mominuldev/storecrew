import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../lib/api';
import type { Provider, Settings as SettingsData } from '../lib/types';
import { Button, Card, Label, Problem, Section, Spinner } from '../components/primitives';

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

  if (providers.isLoading || settings.isLoading) return <Spinner label="Loading settings" />;

  const list = providers.data ?? [];
  const s = settings.data!;

  return (
    <>
      <Section title="Connections">
        <div className="grid gap-2">
          {list.map((p) => (
            <Card
              key={p.id}
              edge={p.configured ? 'var(--color-crew-500)' : undefined}
              className="px-4 py-4"
            >
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="text-[13px] font-semibold">{p.label}</p>
                  <div className="mt-1.5 flex flex-wrap gap-x-3 gap-y-1">
                    {!p.capabilities.embeddings ? <Label>cannot read your store</Label> : <Label>can read your store</Label>}
                    {!p.capabilities.sampling ? <Label>no temperature control</Label> : null}
                  </div>
                </div>
                {p.configured ? (
                  <div className="flex items-center gap-2">
                    <span className="scr-num text-[12px]" style={{ color: 'var(--text-dim)' }}>{p.keyHint}</span>
                    <Button onClick={() => verify.mutate(p.id)} disabled={verify.isPending}>Test</Button>
                    <Button variant="danger" onClick={() => removeKey.mutate(p.id)}>Remove</Button>
                  </div>
                ) : null}
              </div>

              {verify.data && verify.variables === p.id ? (
                <p className="mt-2 text-[12px]" style={{ color: verify.data.ok ? 'var(--color-crew-500)' : 'var(--color-alert-500)' }}>
                  {verify.data.ok ? 'Connected.' : verify.data.error}
                </p>
              ) : null}

              <form
                className="mt-3 flex gap-2"
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
                  className="flex-1 rounded-md border px-3 py-2 text-[13px] outline-none"
                  style={{ borderColor: 'var(--line)', background: 'var(--bg)', color: 'var(--text)' }}
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
              <Card key={task} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3.5">
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
                  className="rounded-md border px-2.5 py-1.5 text-[13px]"
                  style={{ borderColor: 'var(--line)', background: 'var(--bg)', color: 'var(--text)' }}
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
    </>
  );
}
