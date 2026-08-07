import { useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { HashRouter, Navigate, Route, Routes } from 'react-router-dom';
import { api } from './lib/api';
import { getScreen, useTheme } from './lib/store';
import type { Approval, Bootstrap } from './lib/types';
import { Layout } from './components/Layout';
import { Card, Spinner } from './components/primitives';
import { Overview } from './pages/Overview';
import { Crew } from './pages/Crew';
import { Knowledge } from './pages/Knowledge';
import { Inbox } from './pages/Inbox';
import { Settings } from './pages/Settings';
import { Setup } from './pages/Setup';
import { ConversationDetail } from './pages/ConversationDetail';

/**
 * Routing is hash-based. WordPress owns the admin URL, and a history-based
 * router would need a rewrite rule to survive a page refresh — a hash keeps
 * every deep link working with no server involvement.
 */
export function App() {
  const apply = useTheme((s) => s.apply);

  useEffect(apply, [apply]);

  const boot = useQuery({ queryKey: ['bootstrap'], queryFn: () => api.get<Bootstrap>('/bootstrap') });
  const approvals = useQuery({ queryKey: ['approvals'], queryFn: () => api.get<Approval[]>('/approvals') });

  if (boot.isLoading) {
    return <div className="p-8"><Spinner label="Starting StoreCrew" /></div>;
  }

  if (boot.isError) {
    return (
      <div className="p-8">
        <Card edge="var(--color-alert-500)" className="px-4 py-4">
          <p className="text-[13px] font-medium">StoreCrew could not start.</p>
          <p className="mt-1 text-[13px]" style={{ color: 'var(--text-dim)' }}>
            {(boot.error as Error).message}
          </p>
        </Card>
      </div>
    );
  }

  const data = boot.data!;

  return (
    <HashRouter>
      <Routes>
        <Route element={<Layout boot={data} pending={approvals.data?.length ?? 0} />}>
          <Route index element={<Overview />} />
          <Route path="crew" element={<Crew />} />
          <Route path="knowledge" element={<Knowledge />} />
          <Route path="inbox" element={<Inbox />} />
          <Route path="settings" element={<Settings />} />
          <Route path="setup" element={<Setup />} />
          <Route path="conversation/:uuid" element={<ConversationDetail />} />

          {/* Routes contributed by add-ons. The server decides both that a
              route exists and whether it is entitled; a locked route renders an
              upgrade panel rather than disappearing, so the merchant can see
              what the plan would add. */}
          {data.routes.map((route) => {
            const Screen = getScreen(route.path);

            return (
              <Route
                key={route.path}
                path={route.path.replace(/^\//, '')}
                element={
                  route.locked || !Screen ? (
                    <Card edge="var(--color-signal-500)" className="px-5 py-6">
                      <p className="text-[13px] font-semibold">{route.label}</p>
                      <p className="mt-1 text-[13px]" style={{ color: 'var(--text-dim)' }}>
                        {route.locked
                          ? 'This is part of a paid plan.'
                          : 'This screen has not finished loading. Try refreshing.'}
                      </p>
                    </Card>
                  ) : (
                    <Screen />
                  )
                }
              />
            );
          })}

          <Route path="*" element={<Navigate to="/" replace />} />
        </Route>
      </Routes>
    </HashRouter>
  );
}
