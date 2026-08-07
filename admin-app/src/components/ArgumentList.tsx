/**
 * A tool call's arguments, rendered for a merchant (G4-D2).
 *
 * The merchant reading these is about to approve a write — this is the case
 * FR-ADMIN-06 exists for, so the arguments must read as a form, not as JSON.
 * Keys the shipped tools use get proper labels; anything a future tool sends
 * is humanised (snake_case → words) rather than dumped, so a contributed tool
 * degrades to readable, never to `{"…"}`.
 */

const LABELS: Record<string, string> = {
  order_id: 'Order',
  note: 'Note',
  customer_note: 'Shown to the customer',
  email: 'Email',
  query: 'Search',
  decision: 'Decision',
};

const humanise = (key: string): string =>
  LABELS[key] ?? key.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

const printable = (value: unknown): string => {
  if (value === null || value === undefined) return '—';
  if (typeof value === 'boolean') return value ? 'yes' : 'no';
  if (typeof value === 'string' || typeof value === 'number') return String(value);

  // A structured value from a tool no shipped label map knows. Compact JSON is
  // the fallback, not the format.
  return JSON.stringify(value);
};

export function ArgumentList({ args }: { args: Record<string, unknown> | null }) {
  const entries = Object.entries(args ?? {});

  if (!entries.length) {
    return (
      <p className="text-[12px]" style={{ color: 'var(--text-dim)' }}>
        No details attached.
      </p>
    );
  }

  return (
    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
      {entries.map(([key, value]) => (
        <div key={key} className="contents">
          <dt className="scr-label self-baseline">{humanise(key)}</dt>
          <dd className="min-w-0 self-baseline break-words text-[13px]">{printable(value)}</dd>
        </div>
      ))}
    </dl>
  );
}
