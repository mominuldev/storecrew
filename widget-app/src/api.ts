/**
 * The REST client.
 *
 * `fetch` directly, with no library. The widget makes four calls and has a
 * 45 KB gzipped budget to meet (FR-CHAT-01); a client library would spend a
 * third of it.
 */

import type { ApiError, BootPayload, ReplyPayload, SessionPayload } from './types';

const SESSION_STORAGE_KEY = 'storecrew.chat.token';

export class ApiFailure extends Error {
  public readonly code: string;
  public readonly status: number;
  public readonly retryAfter: number;

  constructor(error: ApiError, status: number) {
    super(error.message);
    this.code = error.code;
    this.status = status;
    this.retryAfter = error.retryAfter ?? 0;
  }
}

export class ChatApi {
  private nonce = '';

  constructor(private readonly root: string) {}

  /**
   * The session token, when the cookie did not survive the host's page cache.
   *
   * Read from `sessionStorage`, so it dies with the tab — the cookie is the
   * durable copy and this is only the fallback. Wrapped because Safari's
   * private mode throws on access rather than returning null.
   */
  private get token(): string {
    try {
      return window.sessionStorage.getItem(SESSION_STORAGE_KEY) ?? '';
    } catch {
      return '';
    }
  }

  private set token(value: string) {
    try {
      if (value) {
        window.sessionStorage.setItem(SESSION_STORAGE_KEY, value);
      }
    } catch {
      /* Storage unavailable. The cookie still carries the session. */
    }
  }

  async boot(): Promise<BootPayload> {
    const payload = await this.call<BootPayload>('GET', '/chat/boot');
    this.nonce = payload.nonce;

    return payload;
  }

  async session(): Promise<SessionPayload> {
    const payload = await this.call<SessionPayload>('POST', '/chat/session');

    if (payload.token) {
      this.token = payload.token;
    }

    return payload;
  }

  async send(uuid: string, message: string): Promise<ReplyPayload> {
    return this.call<ReplyPayload>('POST', `/chat/${uuid}/messages`, { message });
  }

  /**
   * Send one turn, surfacing text deltas as they arrive (FR-CHAT-02).
   *
   * One contract, three transports, all ending in the same payload:
   * - The server streams and the host passes it through → deltas paint live.
   * - The host buffers → the same events arrive in one piece at the end and
   *   parse identically. Nothing detects buffering; buffering just *is* the
   *   buffered experience.
   * - The server declines SSE (filtered off, or an intermediary rewrote the
   *   response to JSON) → parsed as the plain reply.
   * Failures throw the same ApiFailure send() does.
   */
  async sendStream(uuid: string, message: string, onDelta: (text: string) => void): Promise<ReplyPayload> {
    const headers: Record<string, string> = {
      Accept: 'text/event-stream',
      'Content-Type': 'application/json',
    };

    if (this.nonce) {
      headers['X-WP-Nonce'] = this.nonce;
    }

    const token = this.token;

    if (token) {
      headers['X-StoreCrew-Session'] = token;
    }

    const response = await fetch(`${this.root}/chat/${uuid}/messages`, {
      method: 'POST',
      headers,
      credentials: 'same-origin',
      body: JSON.stringify({ message }),
    });

    const type = response.headers.get('content-type') ?? '';

    if (!response.ok || !type.includes('text/event-stream') || !response.body) {
      // The buffered JSON path, including every error the guards return.
      const text = await response.text();
      let parsed: unknown = null;

      try {
        parsed = text ? JSON.parse(text) : null;
      } catch {
        parsed = null;
      }

      if (!response.ok) {
        const error = (parsed ?? {}) as { code?: string; message?: string; data?: { retryAfter?: number } };

        throw new ApiFailure(
          { code: error.code ?? 'http_error', message: error.message ?? 'Something went wrong.', retryAfter: error.data?.retryAfter },
          response.status,
        );
      }

      return ((parsed as { data?: ReplyPayload })?.data ?? ({} as ReplyPayload)) as ReplyPayload;
    }

    // Incremental SSE parse. Events end at a blank line; anything after the
    // last separator is a partial event held for the next chunk.
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    let done: ReplyPayload | null = null;
    let failure: ApiFailure | null = null;

    const handle = (raw: string): void => {
      let event = 'message';
      let data = '';

      for (const line of raw.split('\n')) {
        const clean = line.replace(/\r$/, '');

        if (clean.startsWith('event:')) event = clean.slice(6).trim();
        if (clean.startsWith('data:')) data += clean.slice(5).trim();
      }

      if (!data) return;

      let payload: unknown;

      try {
        payload = JSON.parse(data);
      } catch {
        return;
      }

      if (event === 'delta') {
        const text = (payload as { text?: string }).text ?? '';
        if (text) onDelta(text);
      } else if (event === 'done') {
        done = payload as ReplyPayload;
      } else if (event === 'error') {
        const error = payload as { code?: string; message?: string };
        failure = new ApiFailure({ code: error.code ?? 'stream_error', message: error.message ?? 'Something went wrong.' }, 200);
      }
    };

    for (;;) {
      const { value, done: eof } = await reader.read();

      if (value) {
        buffer += decoder.decode(value, { stream: true });

        let cut: number;

        while ((cut = buffer.indexOf('\n\n')) !== -1) {
          handle(buffer.slice(0, cut));
          buffer = buffer.slice(cut + 2);
        }
      }

      if (eof) break;
    }

    if (failure) throw failure;

    if (!done) {
      // The stream ended without its final event — a proxy cut it off. The
      // deltas the customer saw were real; report the truncation honestly.
      throw new ApiFailure({ code: 'stream_truncated', message: 'The connection was interrupted.' }, 200);
    }

    return done;
  }

  async close(uuid: string): Promise<void> {
    await this.call('POST', `/chat/${uuid}/close`);
  }

  private async call<T>(method: string, path: string, body?: unknown): Promise<T> {
    const headers: Record<string, string> = { Accept: 'application/json' };

    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
    }

    // Sent so a signed-in customer is recognised as one. WordPress treats a
    // cookie-authenticated REST request without a nonce as anonymous — a
    // degradation — but a present-and-wrong nonce is a 403 before any route
    // callback runs. boot() therefore mints the nonce for the user the login
    // cookie proves; if it ever goes stale mid-session (expiry, login state
    // changed in another tab), every call fails until the page is reloaded.
    if (this.nonce) {
      headers['X-WP-Nonce'] = this.nonce;
    }

    const token = this.token;

    if (token) {
      headers['X-StoreCrew-Session'] = token;
    }

    const response = await fetch(this.root + path, {
      method,
      headers,
      credentials: 'same-origin',
      body: body === undefined ? undefined : JSON.stringify(body),
    });

    const text = await response.text();
    let parsed: unknown = null;

    try {
      parsed = text ? JSON.parse(text) : null;
    } catch {
      parsed = null;
    }

    if (!response.ok) {
      const error = (parsed ?? {}) as { code?: string; message?: string; data?: { retryAfter?: number } };

      throw new ApiFailure(
        {
          code: error.code ?? 'http_error',
          message: error.message ?? 'Something went wrong.',
          retryAfter: error.data?.retryAfter,
        },
        response.status,
      );
    }

    return ((parsed as { data?: T })?.data ?? ({} as T)) as T;
  }
}
