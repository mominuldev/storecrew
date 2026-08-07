import type { ReactNode } from 'react';

/** Micro-label. Mono, uppercase, wide — the console's connective tissue. */
export function Label({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`scr-label ${className}`}>{children}</div>;
}

export function Card({
  children,
  edge,
  className = '',
}: {
  children: ReactNode;
  /** Status colour for the left edge-bar. */
  edge?: string;
  className?: string;
}) {
  return (
    <div
      className={`scr-card ${edge ? 'scr-edge' : ''} ${className}`}
      style={edge ? ({ '--edge': edge } as React.CSSProperties) : undefined}
    >
      {children}
    </div>
  );
}

export function Section({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
  return (
    <section className="mb-9">
      <div className="mb-3 flex items-end justify-between gap-4">
        <Label>{title}</Label>
        {action}
      </div>
      {children}
    </section>
  );
}

type ButtonProps = {
  children: ReactNode;
  onClick?: () => void;
  variant?: 'primary' | 'quiet' | 'danger';
  disabled?: boolean;
  type?: 'button' | 'submit';
};

export function Button({ children, onClick, variant = 'quiet', disabled, type = 'button' }: ButtonProps) {
  const base =
    'inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-[13px] font-medium transition-colors disabled:opacity-40 disabled:cursor-not-allowed';

  const styles = {
    primary: 'bg-signal-500 text-ink-950 hover:bg-signal-400',
    quiet: 'border hover:bg-[var(--bg)]',
    danger: 'text-alert-500 hover:bg-alert-500/10',
  }[variant];

  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      className={`${base} ${styles}`}
      style={variant === 'quiet' ? { borderColor: 'var(--line)' } : undefined}
    >
      {children}
    </button>
  );
}

/** A number with its unit, in mono. Numbers are the second-most important
 *  thing on this screen after status, so they get their own treatment. */
export function Stat({ value, unit, tone }: { value: string | number; unit?: string; tone?: string }) {
  return (
    <div className="flex items-baseline gap-1.5">
      <span className="scr-num text-2xl font-semibold" style={tone ? { color: tone } : undefined}>
        {value}
      </span>
      {unit ? <span className="scr-label">{unit}</span> : null}
    </div>
  );
}

export function Empty({ title, hint, action }: { title: string; hint: string; action?: ReactNode }) {
  return (
    <Card className="px-6 py-10 text-center">
      <p className="text-sm font-medium">{title}</p>
      <p className="mx-auto mt-1 max-w-md text-[13px]" style={{ color: 'var(--text-dim)' }}>
        {hint}
      </p>
      {action ? <div className="mt-4 flex justify-center">{action}</div> : null}
    </Card>
  );
}

export function Spinner({ label = 'Loading' }: { label?: string }) {
  return (
    <div className="flex items-center gap-2 py-8" style={{ color: 'var(--text-dim)' }}>
      <span className="scr-live inline-block h-1.5 w-1.5 rounded-full bg-signal-500" />
      <span className="scr-label">{label}</span>
    </div>
  );
}

export function Problem({ message }: { message: string }) {
  return (
    <Card edge="var(--color-alert-500)" className="px-4 py-3">
      <p className="text-[13px]">{message}</p>
    </Card>
  );
}
