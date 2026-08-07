import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { App } from './App';
import './styles/app.css';

const client = new QueryClient({
  defaultOptions: {
    queries: {
      // The admin is a low-traffic surface; refetching on every window focus
      // makes it feel busy without telling the merchant anything new.
      refetchOnWindowFocus: false,
      retry: 1,
      staleTime: 10_000,
    },
  },
});

const mount = document.getElementById('storecrew-root');

if (mount) {
  createRoot(mount).render(
    <StrictMode>
      <QueryClientProvider client={client}>
        <App />
      </QueryClientProvider>
    </StrictMode>,
  );
}
