import type { ReactNode } from 'react';
import { Icon, type IconName } from './Icon';

/** Micro-label. Mono, uppercase, wide — the console's connective tissue. */
export function Label({ children, className = '' }: { children: ReactNode; className?: string }) {
  return <div className={`scr-label ${className}`}>{children}</div>;
}

export function Card({
  children,
  edge,
  interactive,
  className = '',
}: {
  children: ReactNode;
  /** Status colour for the left edge-bar. */
  edge?: string;
  /** Hover lift for cards that navigate somewhere. */
  interactive?: boolean;
  className?: string;
}) {
  return (
    <div
      className={`scr-card ${edge ? 'scr-edge' : ''} ${interactive ? 'scr-card-link' : ''} ${className}`}
      style={edge ? ({ '--edge': edge } as React.CSSProperties) : undefined}
    >
      {children}
    </div>
  );
}

/** Every screen opens with one of these: what this place is, in one line. */
export function PageHeader({ title, sub, action }: { title: string; sub: string; action?: ReactNode }) {
  return (
    <header className="mb-8 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 className="text-[22px] font-semibold tracking-tight">{title}</h1>
        <p className="mt-1 text-[13px]" style={{ color: 'var(--text-dim)' }}>
          {sub}
        </p>
      </div>
      {action}
    </header>
  );
}

export function Section({ title, action, children }: { title: string; action?: ReactNode; children: ReactNode }) {
  return (
    <section className="mb-10">
      <div className="mb-3 flex items-end justify-between gap-4">
        <Label>{title}</Label>
        {action}
      </div>
      {children}
    </section>
  );
}

type Tone = 'signal' | 'crew' | 'alert' | 'neutral';

const TONE = {
  signal: { bg: 'var(--tint-signal)', fg: 'var(--fg-signal)' },
  crew: { bg: 'var(--tint-crew)', fg: 'var(--fg-crew)' },
  alert: { bg: 'var(--tint-alert)', fg: 'var(--fg-alert)' },
  neutral: { bg: 'var(--tint-neutral)', fg: 'var(--text-dim)' },
} as const;

/** Status pill: a dot and a word on a wash of the status colour. */
export function Pill({ tone = 'neutral', live, children }: { tone?: Tone; live?: boolean; children: ReactNode }) {
  const t = TONE[tone];

  return (
    <span className="scr-pill" style={{ background: t.bg, color: t.fg }}>
      <span className={`scr-pill-dot ${live ? 'scr-live' : ''}`} aria-hidden />
      {children}
    </span>
  );
}

/** An icon on its tone's wash — the visual anchor of a stat or empty state. */
export function IconChip({ name, tone = 'neutral', size = 34 }: { name: IconName; tone?: Tone; size?: number }) {
  const t = TONE[tone];

  return (
    <span
      className="grid shrink-0 place-items-center rounded-[10px]"
      style={{ width: size, height: size, background: t.bg, color: t.fg }}
    >
      <Icon name={name} />
    </span>
  );
}

type ButtonProps = {
  children: ReactNode;
  onClick?: () => void;
  variant?: 'primary' | 'quiet' | 'danger';
  icon?: IconName;
  disabled?: boolean;
  type?: 'button' | 'submit';
};

export function Button({ children, onClick, variant = 'quiet', icon, disabled, type = 'button' }: ButtonProps) {
  const base =
    'inline-flex items-center gap-1.5 rounded-lg px-3.5 py-2 text-[13px] font-medium transition-all disabled:opacity-40 disabled:cursor-not-allowed';

  const styles = {
    primary: 'bg-signal-500 font-semibold text-ink-950 shadow-sm hover:bg-signal-400 active:translate-y-px',
    quiet: 'border hover:border-[var(--line-strong)] hover:bg-[var(--surface-2)]',
    danger: 'text-alert-500 hover:bg-[var(--tint-alert)]',
  }[variant];

  return (
    <button
      type={type}
      onClick={onClick}
      disabled={disabled}
      className={`${base} ${styles}`}
      style={variant === 'quiet' ? { borderColor: 'var(--line)', background: 'var(--surface)' } : undefined}
    >
      {icon ? <Icon name={icon} size={15} /> : null}
      {children}
    </button>
  );
}

/** A number with its unit, in mono. Numbers are the second-most important
 *  thing on this screen after status, so they get their own treatment. */
export function Stat({ value, unit, tone }: { value: string | number; unit?: string; tone?: string }) {
  return (
    <div className="flex items-baseline gap-1.5">
      <span className="scr-num text-[26px] font-semibold" style={tone ? { color: tone } : undefined}>
        {value}
      </span>
      {unit ? <span className="scr-label">{unit}</span> : null}
    </div>
  );
}

/** The stat card: icon chip, label, and whatever the number is. */
export function StatCard({
  icon,
  tone,
  label,
  edge,
  children,
}: {
  icon: IconName;
  tone?: Tone;
  label: string;
  edge?: string;
  children: ReactNode;
}) {
  return (
    <Card edge={edge} className="px-4 py-4">
      <div className="flex items-center gap-2.5">
        <IconChip name={icon} tone={tone} size={30} />
        <Label>{label}</Label>
      </div>
      <div className="mt-3">{children}</div>
    </Card>
  );
}

export function Empty({
  icon = 'spark',
  title,
  hint,
  action,
}: {
  icon?: IconName;
  title: string;
  hint: string;
  action?: ReactNode;
}) {
  return (
    <Card className="px-6 py-12 text-center">
      <div className="mx-auto mb-4 grid h-11 w-11 place-items-center rounded-full" style={{ background: 'var(--tint-neutral)', color: 'var(--text-dim)' }}>
        <Icon name={icon} size={20} />
      </div>
      <p className="text-sm font-semibold">{title}</p>
      <p className="mx-auto mt-1.5 max-w-md text-[13px] leading-relaxed" style={{ color: 'var(--text-dim)' }}>
        {hint}
      </p>
      {action ? <div className="mt-5 flex justify-center">{action}</div> : null}
    </Card>
  );
}

export function Spinner({ label = 'Loading' }: { label?: string }) {
  return (
    <div className="flex items-center gap-2.5 py-8" style={{ color: 'var(--text-dim)' }}>
      <span className="scr-live inline-block h-2 w-2 rounded-full bg-signal-500" />
      <span className="scr-label">{label}</span>
    </div>
  );
}

export function Problem({ message }: { message: string }) {
  return (
    <Card edge="var(--color-alert-500)" className="flex items-start gap-2.5 px-4 py-3">
      <span className="mt-px" style={{ color: 'var(--fg-alert)' }}>
        <Icon name="alert" size={15} />
      </span>
      <p className="text-[13px] leading-relaxed">{message}</p>
    </Card>
  );
}
