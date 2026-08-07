/**
 * REST client for the storecrew/v1 namespace.
 *
 * Every request carries the WordPress nonce. Without it a logged-in cookie is
 * not accepted as authentication for a REST write, which is what stops a
 * third-party page from driving this API using the merchant's own session.
 */

declare global {
  interface Window {
    storecrewBoot?: {
      root: string;
      nonce: string;
      version: string;
      adminUrl: string;
    };
  }
}

const boot = () => {
  const b = window.storecrewBoot;

  if (!b) {
    throw new Error('StoreCrew did not receive its bootstrap configuration.');
  }

  return b;
};

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly code: string,
  ) {
    super(message);
  }
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const { root, nonce } = boot();

  const response = await fetch(`${root}storecrew/v1${path}`, {
    ...init,
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      'X-WP-Nonce': nonce,
      ...(init.headers ?? {}),
    },
  });

  const text = await response.text();
  const body = text ? JSON.parse(text) : null;

  if (!response.ok) {
    // WP_Error serialises as {code, message}. Surfacing the server's own
    // wording matters here — "That feature is not available on your plan" is
    // actionable in a way that "Request failed" is not.
    throw new ApiError(
      body?.message ?? 'Something went wrong.',
      response.status,
      body?.code ?? 'unknown',
    );
  }

  return body?.data as T;
}

export const api = {
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, body?: unknown) =>
    request<T>(path, { method: 'POST', body: body ? JSON.stringify(body) : undefined }),
  del: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
};

export const adminUrl = () => boot().adminUrl;
