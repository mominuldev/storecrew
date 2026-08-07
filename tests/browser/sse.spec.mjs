/**
 * SSE assembler — buffered delivery parses identically to streamed (R-TECH-02).
 *
 * The streaming criterion's open half (14 § M1): on a host that buffers the
 * response, the widget receives the whole SSE body in one read instead of many,
 * and the fallback rests on that producing exactly the streamed result. This is
 * invisible to the PHP suites and awkward to force in a real browser, so it is
 * probed here against the *actual shipping code*: widget-app/src/sse.ts is
 * transpiled with the project's own TypeScript and driven under every delivery
 * pattern a host could impose — one giant read (buffered), one read per event,
 * one byte at a time, and splits at every offset in between. All must yield the
 * same events, the same reassembled text, and the same final payload.
 *
 * No browser, no framework — the same PASS/FAIL discipline as the rest of the
 * suite. Run via tests/browser/run.sh (or `node tests/browser/sse.spec.mjs`).
 */

import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';
import ts from 'typescript';
import { t, summary } from './helpers.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const source = await readFile(resolve(here, '../../widget-app/src/sse.ts'), 'utf8');

// Transpile the real module (types erased, `import type` dropped) and import it
// from a data URL, so the test exercises exactly what ships — not a copy.
const js = ts.transpileModule(source, {
  compilerOptions: { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2020 },
}).outputText;

const { SseAssembler } = await import(`data:text/javascript;base64,${Buffer.from(js).toString('base64')}`);

/**
 * Run the whole SSE body through an assembler in fragments of a given shape,
 * collecting every event it emits in order.
 */
const drive = (body, fragments) => {
  const assembler = new SseAssembler();
  const events = [];

  for (const fragment of fragments) {
    for (const event of assembler.push(fragment)) {
      events.push(event);
    }
  }

  return events;
};

/** Every single-character fragment — the pathological "one byte at a time" host. */
const perChar = (body) => [...body];

/** Every prefix/suffix split point — split the body in two at each offset. */
const splitAt = (body, at) => [body.slice(0, at), body.slice(at)];

/** Reduce a list of events to a comparable shape: joined delta text + done + error. */
const outcome = (events) => ({
  text: events.filter((e) => e.kind === 'delta').map((e) => e.text).join(''),
  done: events.find((e) => e.kind === 'done')?.payload ?? null,
  error: events.find((e) => e.kind === 'error') ?? null,
  order: events.map((e) => e.kind).join(','),
});

const eq = (a, b) => JSON.stringify(a) === JSON.stringify(b);

// A representative reply: several deltas, then the done event carrying exactly
// the JSON the non-streaming path would have returned.
const donePayload = {
  uuid: '11111111-2222-3333-4444-555555555555',
  reply: { role: 'assistant', content: 'Hello — how can I help you today?', agentId: 'support' },
  outcome: 'answered',
  escalated: false,
};

const deltas = ['Hello', ' — how', ' can I', ' help you', ' today?'];

const frame = (event, data) => `event: ${event}\ndata: ${JSON.stringify(data)}\n\n`;

const body =
  deltas.map((d) => frame('delta', { text: d })).join('') + frame('done', donePayload);

// The streamed reference: one read per event (what a pass-through host does).
const eventFragments = [
  ...deltas.map((d) => frame('delta', { text: d })),
  frame('done', donePayload),
];
const reference = outcome(drive(body, eventFragments));

console.log('SSE assembler — buffered == streamed (R-TECH-02)\n');

// The reference itself is correct: text reassembles, done payload survives.
t('the streamed reference reassembles the full text', reference.text === deltas.join(''), reference.text);
t('the streamed reference recovers the done payload exactly', eq(reference.done, donePayload));
t('the streamed reference reports no error', reference.error === null);
t('events arrive in order: deltas then done', reference.order === 'delta,delta,delta,delta,delta,done');

// The buffered host: the entire body in a single read.
t('a buffered host (one read) matches the stream', eq(outcome(drive(body, [body])), reference));

// The pathological host: one character per read.
t('a one-byte-at-a-time host matches the stream', eq(outcome(drive(body, perChar(body))), reference));

// Every split point: the boundary can fall anywhere — mid-event, mid-JSON,
// exactly on the blank-line separator, one side of it, the other.
let allSplitsMatch = true;
let firstBad = -1;
for (let at = 0; at <= body.length; at++) {
  if (!eq(outcome(drive(body, splitAt(body, at))), reference)) {
    allSplitsMatch = false;
    firstBad = at;
    break;
  }
}
t('a split at any offset matches the stream', allSplitsMatch, firstBad >= 0 ? `first mismatch at offset ${firstBad}` : '');

// A proxy that rewrote line endings to CRLF (or the CR-only variant): events
// must still split identically, including when the boundary falls mid-CRLF.
const crlfBody = body.replace(/\n/g, '\r\n');
let crlfSplitsMatch = true;
for (let at = 0; at <= crlfBody.length && crlfSplitsMatch; at++) {
  crlfSplitsMatch = eq(outcome(drive(crlfBody, splitAt(crlfBody, at))), reference);
}
t('CRLF separators parse the same, buffered', eq(outcome(drive(crlfBody, [crlfBody])), reference));
t('CRLF separators parse the same, one byte at a time', eq(outcome(drive(crlfBody, perChar(crlfBody))), reference));
t('CRLF separators parse the same at any split offset', crlfSplitsMatch);

// The error event: a provider failure mid-stream surfaces as one error event,
// identically whether buffered or streamed.
const errorBody =
  frame('delta', { text: 'One moment' }) + frame('error', { code: '429', message: 'Rate limited.' });
const errWhole = outcome(drive(errorBody, [errorBody]));
const errChars = outcome(drive(errorBody, perChar(errorBody)));
t('an error event surfaces once, buffered or streamed', eq(errWhole, errChars) && errWhole.error !== null, errWhole.error?.code);
t('the delta before the error still arrives', errWhole.text === 'One moment');

// Noise the assembler must swallow rather than mis-emit: comments, heartbeats,
// and unparseable data must not become spurious events.
const noisy =
  ': keep-alive\n\n' + 'event: delta\ndata: not json\n\n' + frame('delta', { text: 'ok' }) + frame('done', donePayload);
const noisyOut = outcome(drive(noisy, [noisy]));
t('comments and unparseable data emit no spurious events', noisyOut.text === 'ok' && eq(noisyOut.done, donePayload));

process.exit(summary('sse') > 0 ? 1 : 0);
