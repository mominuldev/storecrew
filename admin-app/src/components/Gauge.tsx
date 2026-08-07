/**
 * The tick-mark arc gauge. Pure SVG — a chart library for one dial would be
 * the bundle equivalent of shipping Guzzle for one request.
 *
 * `value` is 0–100. Ticks fill clockwise in the accent colour; the rest stay
 * on the hairline colour, so the dial reads in both themes without a legend.
 */

const TICKS = 40;
const SWEEP = 260;
const START = 140;

export function Gauge({ value, children }: { value: number; children?: React.ReactNode }) {
  const filled = Math.round((Math.min(100, Math.max(0, value)) / 100) * TICKS);

  return (
    <div className="relative mx-auto aspect-square w-full max-w-[210px]">
      <svg viewBox="0 0 100 100" className="h-full w-full" aria-hidden>
        {Array.from({ length: TICKS }, (_, i) => {
          const angle = ((START + (SWEEP / (TICKS - 1)) * i) * Math.PI) / 180;
          const x1 = 50 + Math.cos(angle) * 36;
          const y1 = 50 + Math.sin(angle) * 36;
          const x2 = 50 + Math.cos(angle) * 46;
          const y2 = 50 + Math.sin(angle) * 46;

          return (
            <line
              key={i}
              x1={x1}
              y1={y1}
              x2={x2}
              y2={y2}
              stroke={i < filled ? 'var(--color-accent-500)' : 'var(--line-strong)'}
              strokeWidth={2.4}
              strokeLinecap="round"
            />
          );
        })}
      </svg>
      <div className="absolute inset-0 grid place-items-center">{children}</div>
    </div>
  );
}
