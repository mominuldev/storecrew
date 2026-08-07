/**
 * The SSE event assembler — transport-agnostic on purpose (FR-CHAT-02, R-TECH-02).
 *
 * A streaming host hands the reply to the browser in many small network reads;
 * a host that buffers (nginx without `X-Accel-Buffering`, a php-fpm proxy that
 * holds the response) hands over the *same bytes in one read* at the end. The
 * whole R-TECH-02 fallback rests on those two producing an identical result —
 * "the buffered experience is the streamed one, arriving late" — and the only
 * way that is true is if event assembly never depends on where the chunk
 * boundaries fall.
 *
 * So the assembler owns exactly one job: accumulate arbitrary text fragments,
 * and emit a completed event each time a blank line (`\n\n`) closes one. Bytes
 * after the last separator are a partial event, held for the next fragment. A
 * caller feeding it one 4 KB read, or 4,000 one-byte reads, or the whole
 * response at once, gets the same events in the same order. That equivalence is
 * probed directly in tests/browser/sse.spec.mjs against this exact code.
 */

import type { ReplyPayload } from './types';

export type SseEvent =
  | { kind: 'delta'; text: string }
  | { kind: 'done'; payload: ReplyPayload }
  | { kind: 'error'; code: string; message: string };

export class SseAssembler {
  private buffer = '';

  /**
   * Feed one fragment of the stream, in whatever size it arrived.
   *
   * Returns the events this fragment completed — often none (a partial event),
   * sometimes several (a buffered host delivering the whole reply at once).
   */
  push(chunk: string): SseEvent[] {
    this.buffer += chunk;

    // Normalise CRLF / CR line endings to LF so events separated by `\r\n\r\n`
    // or `\r\r` split the same as `\n\n` — a buffering proxy may also rewrite
    // line endings, and the SSE spec permits all three. A lone trailing `\r` is
    // held back unconverted: it may be the first half of a `\r\n` that lands in
    // the next read, and turning it into `\n` now could forge a blank-line
    // separator that was never sent.
    let held = '';

    if (this.buffer.endsWith('\r')) {
      held = '\r';
      this.buffer = this.buffer.slice(0, -1);
    }

    this.buffer = this.buffer.replace(/\r\n/g, '\n').replace(/\r/g, '\n') + held;

    const events: SseEvent[] = [];
    let cut: number;

    // Events end at a blank line; anything after the last separator is a
    // partial event held for the next fragment.
    while ((cut = this.buffer.indexOf('\n\n')) !== -1) {
      const event = this.parse(this.buffer.slice(0, cut));

      if (event) {
        events.push(event);
      }

      this.buffer = this.buffer.slice(cut + 2);
    }

    return events;
  }

  /**
   * Parse one raw event block into a typed event, or null when it carries no
   * usable data (a comment, a heartbeat, an unparseable payload).
   */
  private parse(raw: string): SseEvent | null {
    let event = 'message';
    let data = '';

    for (const line of raw.split('\n')) {
      const clean = line.replace(/\r$/, '');

      if (clean.startsWith('event:')) event = clean.slice(6).trim();
      if (clean.startsWith('data:')) data += clean.slice(5).trim();
    }

    if (!data) return null;

    let payload: unknown;

    try {
      payload = JSON.parse(data);
    } catch {
      return null;
    }

    if (event === 'delta') {
      const text = (payload as { text?: string }).text ?? '';

      return text ? { kind: 'delta', text } : null;
    }

    if (event === 'done') {
      return { kind: 'done', payload: payload as ReplyPayload };
    }

    if (event === 'error') {
      const error = payload as { code?: string; message?: string };

      return { kind: 'error', code: error.code ?? 'stream_error', message: error.message ?? 'Something went wrong.' };
    }

    return null;
  }
}
